<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Services;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Contracts\PermissionCheckerContract;
use MultiTenantSaas\Modules\AiSkill\Contracts\AdoptionPolicyContract;
use MultiTenantSaas\Modules\AiSkill\DTOs\AdoptionDecision;
use MultiTenantSaas\Modules\AiSkill\Exceptions\SkillDomainException;
use MultiTenantSaas\Modules\AiSkill\Models\SkillCandidate;
use MultiTenantSaas\Modules\AiSkill\Models\SkillDefinition;
use MultiTenantSaas\Modules\AiSkill\Models\SkillExperience;
use MultiTenantSaas\Modules\AiSkill\Models\SkillTrainingRun;
use MultiTenantSaas\Modules\Operator\Models\Operator;

/**
 * 平台 Admin 治理服务（F2 写契约 / BL-146）。
 *
 * 独立于租户 Console API：仅 scope=platform Operator + platform.ai.skill.* 权限。
 * 不把租户 API 包装成全局管理；训练必须显式指定获授权 tenant_id；
 * 平台公共 scope 不是业务租户 fallback；写操作留最小审计。
 */
final class SkillAdminService
{
    public const PERM_VIEW = 'platform.ai.skill.view';

    public const PERM_MANAGE = 'platform.ai.skill.manage';

    public function __construct(
        private readonly PermissionCheckerContract $permissions,
        private readonly AdoptionPolicyContract $adoption,
    ) {}

    /**
     * 公共/租户版本治理列表（含 disabled）。不默认全租户并集；租户列表需显式 tenant_ids。
     *
     * @param  list<int>|null  $tenantIds
     * @return list<array<string, mixed>>
     */
    public function listGovernedVersions(?array $tenantIds = null, int $limit = 50): array
    {
        $this->assertPlatform(self::PERM_VIEW);
        $limit = min(100, max(1, $limit));

        $query = SkillDefinition::query()
            ->orderBy('skill_key')->orderBy('version')
            ->orderBy((new SkillDefinition)->getKeyName());
        if ($tenantIds === null) {
            // 默认只看公共作用域，不自动读全租户
            $query->whereIn('scope_type', [SkillDefinition::SCOPE_MODULE, SkillDefinition::SCOPE_PROJECT])->where('scope_id', 0);
        } else {
            $ids = array_values(array_filter(array_map('intval', $tenantIds), static fn ($id): bool => $id > 0));
            if ($ids === []) {
                throw new SkillDomainException('scope_denied', 'Explicit tenant list is required.');
            }
            foreach ($ids as $id) {
                $this->authorizeTenant($id);
            }
            $query->where(function ($q) use ($ids): void {
                $q->where(fn ($global) => $global->whereIn('scope_type', [SkillDefinition::SCOPE_MODULE, SkillDefinition::SCOPE_PROJECT])->where('scope_id', 0))
                    ->orWhere(function ($t) use ($ids): void {
                        $t->where('scope_type', SkillDefinition::SCOPE_TENANT)->whereIn('scope_id', $ids);
                    });
            });
        }

        return $query->limit($limit)->get()->map(function (SkillDefinition $definition): array {
            $view = $definition->toArray();
            try {
                $definition->snapshot();
                $view['content_status'] = 'ready';
            } catch (SkillDomainException) {
                $view['content_status'] = 'missing_or_corrupt';
            }
            $view['governable'] = true;

            return $view;
        })->all();
    }

    /**
     * 公共版本启停（乐观状态）。不改正文；已发布语义不可原地改。
     *
     * @return array<string, mixed>
     */
    public function changeVersionStatus(int $definitionId, string $expectedStatus, string $status): array
    {
        $this->assertPlatform(self::PERM_MANAGE);
        $allowed = [SkillDefinition::STATUS_PUBLISHED, SkillDefinition::STATUS_DISABLED, SkillDefinition::STATUS_DRAFT];
        if (! in_array($expectedStatus, $allowed, true) || ! in_array($status, $allowed, true)) {
            throw new SkillDomainException('review_required', 'Unsupported status transition.');
        }
        if ($status === SkillDefinition::STATUS_DRAFT && $expectedStatus !== SkillDefinition::STATUS_DRAFT) {
            // 发布/停用版本语义禁止改回 draft
            throw new SkillDomainException('version_conflict', 'Published semantics cannot return to draft.');
        }

        return DB::transaction(function () use ($definitionId, $expectedStatus, $status): array {
            $definition = SkillDefinition::query()->whereKey($definitionId)->lockForUpdate()->first();
            if ($definition === null) {
                throw new SkillDomainException('scope_denied', 'Definition not found.');
            }
            if ($definition->scope_type === 'tenant') {
                $this->authorizeTenant((int) $definition->scope_id);
            } elseif (! in_array($definition->scope_type, ['module', 'project'], true) || (int) $definition->scope_id !== 0) {
                throw new SkillDomainException('scope_denied', 'Invalid public scope.');
            }
            if ($definition->status === SkillDefinition::STATUS_DRAFT) {
                throw new SkillDomainException('review_required', 'Draft publishing requires a reviewed publication path.');
            }
            if ($definition->status !== $expectedStatus) {
                throw new SkillDomainException('version_conflict');
            }
            if ($status === SkillDefinition::STATUS_PUBLISHED) {
                $definition->snapshot();
            }
            $definition->status = $status;
            $definition->save();
            $this->audit('version_status', [
                'definition_id' => (string) $definition->getKey(),
                'from' => $expectedStatus,
                'to' => $status,
                'skill_key' => $definition->skill_key,
                'version' => $definition->version,
            ]);

            return $definition->toArray();
        }, 3);
    }

    /**
     * 对显式授权租户发起训练。缺 tenant_id 拒绝；不默认全租户采样。
     *
     * 平台 Operator 不依赖 TenantContext；tenant_id 来自请求显式授权字段。
     *
     * @param  array<string, mixed>  $trainingSpec
     * @return array{training_run_id: string, status: string, tenant_id: string}
     */
    public function enqueueTrainingForTenant(int $tenantId, array $trainingSpec, string $requestKey, string $inputFingerprint = ''): array
    {
        $this->assertPlatform(self::PERM_MANAGE);
        if ($tenantId <= 0) {
            throw new SkillDomainException('scope_denied', 'Explicit authorized tenant_id is required.');
        }
        $this->authorizeTenant($tenantId);
        $mode = (string) ($trainingSpec['mode'] ?? '');
        if (! in_array($mode, [
            SkillTrainingRun::MODE_MODULE_DOCS,
            SkillTrainingRun::MODE_HISTORY,
            SkillTrainingRun::MODE_RECOMMEND_OPTIMIZE,
            SkillTrainingRun::MODE_FAILURE_DIAGNOSIS,
        ], true)) {
            throw new SkillDomainException('data_incomplete', 'Unsupported training mode.');
        }
        $manifest = (array) ($trainingSpec['dataset_manifest'] ?? []);
        if (isset($manifest['remote_url']) || isset($manifest['file_path'])) {
            throw new SkillDomainException('scope_denied', 'Remote URL or filesystem path is not allowed.');
        }
        $manifest['authorized_tenant_id'] = $tenantId;

        return DB::transaction(function () use ($tenantId, $trainingSpec, $requestKey, $mode, $manifest): array {
            $existing = SkillTrainingRun::withoutGlobalScopes()->where('tenant_id', $tenantId)
                ->where('request_key', $requestKey)->lockForUpdate()->first();
            $fingerprint = hash('sha256', json_encode([
                'spec' => $trainingSpec, 'manifest' => $manifest, 'actor' => $this->platformOperatorId(),
            ], JSON_UNESCAPED_UNICODE));
            if ($existing !== null) {
                if ((string) $existing->input_fingerprint !== $fingerprint) {
                    throw new SkillDomainException('version_conflict', 'Request key already used with different training input.');
                }

                return [
                    'training_run_id' => (string) $existing->getKey(),
                    'status' => (string) $existing->status,
                    'tenant_id' => (string) $tenantId,
                ];
            }

            $run = SkillTrainingRun::create([
                'tenant_id' => $tenantId,
                'actor_type' => 'operator',
                'actor_id' => $this->platformOperatorId(),
                'mode' => $mode,
                'dataset_manifest' => $manifest,
                'config_fingerprint' => (string) ($trainingSpec['config_fingerprint'] ?? ''),
                'prompt_fingerprint' => (string) ($trainingSpec['prompt_fingerprint'] ?? ''),
                'model_ref' => $trainingSpec['model_ref'] ?? null,
                'budget' => $trainingSpec['budget'] ?? null,
                'cost_micro' => $trainingSpec['cost_micro'] ?? null,
                'status' => SkillTrainingRun::STATUS_QUEUED,
                'request_key' => $requestKey,
                'input_fingerprint' => $fingerprint,
            ]);
            $this->audit('training_enqueue', [
                'training_run_id' => (string) $run->getKey(),
                'tenant_id' => (string) $tenantId,
                'mode' => $mode,
            ]);

            return [
                'training_run_id' => (string) $run->getKey(),
                'status' => (string) $run->status,
                'tenant_id' => (string) $tenantId,
            ];
        }, 3);
    }

    /**
     * 候选审核列表（可按租户过滤）。
     *
     * @return list<array<string, mixed>>
     */
    public function listCandidates(?int $tenantId = null, int $limit = 50): array
    {
        $this->assertPlatform(self::PERM_VIEW);
        $limit = min(100, max(1, $limit));
        if ($tenantId === null) {
            throw new SkillDomainException('scope_denied', 'Explicit authorized tenant required.');
        }
        $this->authorizeTenant($tenantId);
        $query = SkillCandidate::query()->orderByDesc((new SkillCandidate)->getKeyName());
        if ($tenantId !== null) {
            if ($tenantId <= 0) {
                throw new SkillDomainException('scope_denied', 'Explicit tenant_id is required.');
            }
            $query->where('tenant_id', $tenantId);
        }

        return $query->limit($limit)->get()->map(function (SkillCandidate $candidate): array {
            $result = (array) ($candidate->adoption_policy_result ?? []);

            return [
                'candidate_id' => (string) $candidate->getKey(),
                'training_run_id' => (string) $candidate->training_run_id,
                'tenant_id' => (string) $candidate->tenant_id,
                'candidate_key' => (string) $candidate->candidate_key,
                'status' => (string) $candidate->status,
                'skill_draft' => (array) $candidate->skill_draft,
                'validation' => (array) $candidate->validation,
                'adoption' => $result === [] ? null : $result,
            ];
        })->all();
    }

    /**
     * 审核裁决：仍走 AdoptionPolicy，Admin 不得绕过策略。
     *
     * @return array<string, mixed>
     */
    public function decideCandidate(int $candidateId, string $action, array $context = []): array
    {
        $this->assertPlatform(self::PERM_MANAGE);

        return DB::transaction(function () use ($candidateId, $action): array {
            $candidate = SkillCandidate::withoutGlobalScopes()->whereKey($candidateId)->lockForUpdate()->first();
            if ($candidate === null) {
                throw new SkillDomainException('scope_denied', 'Candidate not found.');
            }
            $this->authorizeTenant((int) $candidate->tenant_id);
            if (in_array($candidate->status, [SkillCandidate::STATUS_ADOPTED, SkillCandidate::STATUS_REJECTED], true)) {
                return $this->candidatePayload($candidate);
            }

            if ($action === 'reject') {
                $candidate->adoption_policy_result = [
                    'decision' => AdoptionDecision::DENIED,
                    'reason' => 'review_required',
                    'evidence' => [],
                    'limited_tenant_ids' => [],
                ];
                $candidate->status = SkillCandidate::STATUS_REJECTED;
            } else {
                $decision = $this->adoption->evaluate([
                    'skill_draft' => (array) $candidate->skill_draft,
                    'validation' => (array) $candidate->validation,
                    'evidence_refs' => (array) $candidate->evidence_refs,
                ], app(CandidatePublication::class)->context($candidate));
                $candidate->adoption_policy_result = [
                    'decision' => $decision->decision,
                    'reason' => $decision->reason,
                    'evidence' => $decision->evidence,
                    'limited_tenant_ids' => $decision->limitedTenantIds,
                ];
                if ($decision->isDenied()) {
                    $candidate->status = SkillCandidate::STATUS_REJECTED;
                } elseif ($decision->isAuto() && $action === 'adopt') {
                    app(CandidatePublication::class)->publish($candidate);
                    $candidate->status = SkillCandidate::STATUS_ADOPTED;
                } else {
                    $candidate->status = SkillCandidate::STATUS_VALIDATED;
                }
            }
            $candidate->save();
            $this->audit('candidate_decide', [
                'candidate_id' => (string) $candidateId,
                'action' => $action,
                'status' => (string) $candidate->status,
            ]);

            return $this->candidatePayload($candidate);
        }, 3);
    }

    private function platformOperatorId(): int
    {
        $user = request()->user();

        return $user instanceof Operator ? (int) $user->getKey() : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function candidatePayload(SkillCandidate $candidate): array
    {
        $result = (array) ($candidate->adoption_policy_result ?? []);

        return [
            'candidate_id' => (string) $candidate->getKey(),
            'training_run_id' => (string) $candidate->training_run_id,
            'tenant_id' => (string) $candidate->tenant_id,
            'candidate_key' => (string) $candidate->candidate_key,
            'status' => (string) $candidate->status,
            'skill_draft' => (array) $candidate->skill_draft,
            'validation' => (array) $candidate->validation,
            'adoption' => $result === [] ? null : $result,
        ];
    }

    private function authorizeTenant(int $tenantId): void
    {
        $allowed = config('ai-skill.platform_tenant_scopes.' . $this->platformOperatorId(), []);
        if ($tenantId <= 0 || ! in_array($tenantId, $allowed, true)) {
            throw new SkillDomainException('scope_denied', 'Tenant is not in the platform operator scope.');
        }
    }

    private function assertPlatform(string $permission): void
    {
        $user = request()->user();
        if (! $user instanceof Operator || ! $user->isPlatform()) {
            throw new SkillDomainException('scope_denied', 'Platform operator required.');
        }
        if (! $this->permissions->check($permission) && ! $this->permissions->check(self::PERM_MANAGE)) {
            throw new SkillDomainException('scope_denied', "Missing permission [{$permission}].");
        }
    }

    /**
     * 最小审计：metadata-only，不落 Prompt/名单/凭据。
     *
     * @param  array<string, mixed>  $payload
     */
    private function audit(string $action, array $payload): void
    {
        SkillExperience::withoutGlobalScopes()->create([
            'tenant_id' => 0,
            'actor_type' => 'platform',
            'actor_id' => $this->platformOperatorId(),
            'task_ref' => 'admin.' . $action,
            'source' => 'manual',
            'goal' => $action,
            'outcome' => 'success',
            'tool_ref' => 'admin.governance',
            'skill_key' => (string) ($payload['skill_key'] ?? '') ?: null,
            'skill_version' => (string) ($payload['version'] ?? '') ?: null,
            'occurred_at' => now(),
            'attributes' => $this->clampAttributes($payload),
            'trust' => 'high',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function clampAttributes(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            if (! is_scalar($value) && $value !== null) {
                continue;
            }
            $out[(string) $key] = $value;
            if (count($out) >= 16) {
                break;
            }
        }

        return $out;
    }
}
