<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Services;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Contracts\PermissionCheckerContract;
use MultiTenantSaas\Modules\AiSkill\Contracts\AdoptionPolicyContract;
use MultiTenantSaas\Modules\AiSkill\DTOs\AdoptionDecision;
use MultiTenantSaas\Modules\AiSkill\Exceptions\SkillDomainException;
use MultiTenantSaas\Modules\AiSkill\Jobs\RunCandidateEvaluationJob;
use MultiTenantSaas\Modules\AiSkill\Jobs\RunSkillTrainingJob;
use MultiTenantSaas\Modules\AiSkill\Models\SkillCandidate;
use MultiTenantSaas\Modules\AiSkill\Models\SkillDefinition;
use MultiTenantSaas\Modules\AiSkill\Models\SkillEvalCase;
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

    /** 冻结样本允许的唯一断言字段（与 CandidateEvaluator::assertCase 一一对应） */
    private const EVAL_ASSERT_KEYS = ['contains', 'not_contains', 'equals', 'required_tools', 'forbidden_tools'];

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
        $manifest = TrainingDataset::freeze((array) ($trainingSpec['dataset_manifest'] ?? []), $tenantId);
        $trainingSpec['dataset_manifest'] = $manifest;

        $created = null;
        $result = DB::transaction(function () use ($tenantId, $trainingSpec, $requestKey, $mode, $manifest, &$created): array {
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
            $created = (int) $run->getKey();
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

        // 事务提交后才派发：执行器默认关闭，登记与真实调用分离
        if ($created !== null && config('ai-skill.training.enabled') === true) {
            RunSkillTrainingJob::dispatch($created, $tenantId);
        }

        return $result;
    }

    /**
     * 训练运行列表（只读已授权租户）。执行事实必须可见：状态流转不等于模型跑过。
     *
     * @return list<array<string, mixed>>
     */
    public function listTrainingRuns(int $tenantId, int $limit = 20): array
    {
        $this->assertPlatform(self::PERM_VIEW);
        $this->authorizeTenant($tenantId);

        return SkillTrainingRun::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->orderByDesc((new SkillTrainingRun)->getKeyName())
            ->limit(min(100, max(1, $limit)))
            ->get()
            ->map(fn (SkillTrainingRun $run): array => [
                'training_run_id' => (string) $run->getKey(),
                'tenant_id' => (string) $run->tenant_id,
                'mode' => (string) $run->mode,
                'status' => (string) $run->status,
                'dataset_manifest' => (array) $run->dataset_manifest,
                'model_ref' => $run->model_ref,
                'budget' => $run->budget,
                'cost_micro' => $run->cost_micro,
                'cost_unknown' => $run->costIsUnknown(),
                'cost_source' => $run->cost_source,
                'llm_calls' => $run->llm_calls,
                'usage' => $run->usage,
                'started_at' => $run->started_at?->toISOString(),
                'finished_at' => $run->finished_at?->toISOString(),
                'error' => $run->error,
                'created_at' => $run->created_at?->toISOString(),
            ])
            ->all();
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
        // Admin 请求没有租户上下文，TenantScope 会 fail-closed 成 WHERE 1=0；
        // 跨租户读取只能显式去作用域 + 按已授权的 tenant_id 过滤。
        $query = SkillCandidate::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->orderByDesc((new SkillCandidate)->getKeyName());

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

    /** Human review approves publication, never an unverified write or a new execution grant. */
    private function assertIndependentReviewEvidence(SkillCandidate $candidate): void
    {
        $draft = (array) $candidate->skill_draft;
        if (($draft['risk']['business_write'] ?? null) !== false) {
            throw new SkillDomainException('verification_missing', 'Human approval requires current independent read-only evaluation evidence.');
        }
        // 证据时效/绑定口径唯一在 SkillDraftRules（发布咽喉同样用它），这里只多加人审的只读要求。
        SkillDraftRules::assertCurrentEvaluationEvidence($draft, (array) $candidate->validation);
    }

    /**
     * 审核裁决：仍走 AdoptionPolicy，Admin 不得绕过策略。
     *
     * @return array<string, mixed>
     */
    public function decideCandidate(int $candidateId, string $action, array $context = []): array
    {
        $this->assertPlatform(self::PERM_MANAGE);
        if (! in_array($action, ['evaluate', 'adopt', 'reject'], true)) {
            throw new SkillDomainException('review_required', 'Unsupported review action.');
        }

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
                } elseif ($action === 'adopt') {
                    if (! $decision->isAuto()) {
                        $this->assertIndependentReviewEvidence($candidate);
                        $result = (array) $candidate->adoption_policy_result;
                        $result['review'] = [
                            'approved_by' => (string) auth()->user()->getAuthIdentifier(),
                            'approved_at' => now()->toIso8601String(),
                            'draft_sha256' => SkillDraftRules::fingerprint((array) $candidate->skill_draft),
                            'manifest_sha256' => $candidate->validation['evaluation']['manifest_sha256'],
                        ];
                        $candidate->adoption_policy_result = $result;
                    }
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

    /**
     * 冻结样本列表（只读，metadata + 断言，供人工核对判据）。
     *
     * @return list<array<string, mixed>>
     */
    public function listEvalCases(int $tenantId, string $datasetId, string $datasetVersion, int $limit = 50): array
    {
        $this->assertPlatform(self::PERM_VIEW);
        $this->authorizeTenant($tenantId);
        $this->assertDatasetRef($datasetId, $datasetVersion);

        return SkillEvalCase::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('dataset_id', $datasetId)
            ->where('dataset_version', $datasetVersion)
            ->orderBy('case_key')
            ->orderBy((new SkillEvalCase)->getKeyName())
            ->limit(min(200, max(1, $limit)))
            ->get()
            ->map(fn (SkillEvalCase $row): array => [
                'eval_case_id' => (string) $row->getKey(),
                'dataset_id' => (string) $row->dataset_id,
                'dataset_version' => (string) $row->dataset_version,
                'split' => (string) $row->split,
                'skill_key' => (string) $row->skill_key,
                'case_key' => (string) $row->case_key,
                'input' => (array) $row->input,
                'asserts' => (array) $row->asserts,
                'rubric_version' => (string) $row->rubric_version,
                'content_sha256' => (string) $row->content_sha256,
            ])
            ->all();
    }

    /**
     * 冻结判据（BL-144）：样本只能由平台治理入口写入，训练器与候选正文都改不了。
     *
     * 已冻结的 case_key 不可覆写——摘要相同则幂等跳过，不同则拒绝并另起 dataset_version；
     * 否则「评测时改题」就能把判据改成候选正文愿意回答的样子。
     *
     * @param  list<array<string, mixed>>  $cases
     * @return array{created: int, unchanged: int, dataset_id: string, dataset_version: string, split: string}
     */
    public function freezeEvalCases(int $tenantId, string $datasetId, string $datasetVersion, string $split, array $cases): array
    {
        $this->assertPlatform(self::PERM_MANAGE);
        $this->authorizeTenant($tenantId);
        $this->assertDatasetRef($datasetId, $datasetVersion);
        if (! in_array($split, [SkillEvalCase::SPLIT_DISCOVERY, SkillEvalCase::SPLIT_VALIDATION], true)) {
            throw new SkillDomainException('data_incomplete', 'Evaluation split must be discovery or validation.');
        }
        if ($cases === [] || count($cases) > 200) {
            throw new SkillDomainException('data_incomplete', 'Evaluation cases must number 1..200 per request.');
        }

        $normalized = [];
        foreach ($cases as $case) {
            $normalized[] = $this->normalizeEvalCase(is_array($case) ? $case : []);
        }
        $keys = array_column($normalized, 'case_key');
        if (count($keys) !== count(array_unique($keys))) {
            throw new SkillDomainException('version_conflict', 'Duplicate case_key in one freeze request.');
        }

        $operatorId = $this->platformOperatorId();
        $result = DB::transaction(function () use ($tenantId, $datasetId, $datasetVersion, $split, $normalized, $operatorId): array {
            $created = 0;
            $unchanged = 0;
            foreach ($normalized as $case) {
                $digest = SkillEvalCase::digestFor($case['input'], $case['asserts']);
                $existing = SkillEvalCase::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('dataset_id', $datasetId)
                    ->where('dataset_version', $datasetVersion)
                    ->where('case_key', $case['case_key'])
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    if (! hash_equals((string) $existing->content_sha256, $digest)
                        || (string) $existing->split !== $split
                        || (string) $existing->skill_key !== $case['skill_key']) {
                        throw new SkillDomainException('version_conflict', "Frozen case [{$case['case_key']}] cannot be overwritten; use a new dataset_version.");
                    }
                    $unchanged++;

                    continue;
                }
                SkillEvalCase::create([
                    'tenant_id' => $tenantId,
                    'dataset_id' => $datasetId,
                    'dataset_version' => $datasetVersion,
                    'split' => $split,
                    'skill_key' => $case['skill_key'],
                    'case_key' => $case['case_key'],
                    'input' => $case['input'],
                    'asserts' => $case['asserts'],
                    'rubric_version' => $case['rubric_version'],
                    'content_sha256' => $digest,
                    'created_by_operator_id' => $operatorId,
                ]);
                $created++;
            }

            return ['created' => $created, 'unchanged' => $unchanged];
        }, 3);

        // 审计只记引用与数量，不落样本正文（判据里可能含用户语境）
        $this->audit('eval_cases_freeze', [
            'tenant_id' => (string) $tenantId,
            'dataset_id' => $datasetId,
            'dataset_version' => $datasetVersion,
            'split' => $split,
            'created' => $result['created'],
            'unchanged' => $result['unchanged'],
        ]);

        return [
            'created' => $result['created'],
            'unchanged' => $result['unchanged'],
            'dataset_id' => $datasetId,
            'dataset_version' => $datasetVersion,
            'split' => $split,
        ];
    }

    /**
     * 触发独立评测。执行器开关未开一律拒绝：治理入口与真实花钱必须是两个动作。
     *
     * @return array<string, mixed>
     */
    public function enqueueEvaluation(int $candidateId, string $datasetId, string $datasetVersion): array
    {
        $this->assertPlatform(self::PERM_MANAGE);
        if (config('ai-skill.evaluation.enabled') !== true) {
            throw new SkillDomainException('review_required', 'The evaluation executor is disabled.');
        }
        $this->assertDatasetRef($datasetId, $datasetVersion);

        $candidate = SkillCandidate::withoutGlobalScopes()->whereKey($candidateId)->first();
        if ($candidate === null) {
            throw new SkillDomainException('scope_denied', 'Candidate not found.');
        }
        $tenantId = (int) $candidate->tenant_id;
        $this->authorizeTenant($tenantId);
        if (in_array((string) $candidate->status, [SkillCandidate::STATUS_ADOPTED, SkillCandidate::STATUS_REJECTED], true)) {
            return $this->candidatePayload($candidate);
        }

        RunCandidateEvaluationJob::dispatch($candidateId, $tenantId, $datasetId, $datasetVersion);
        $this->audit('candidate_evaluate', [
            'candidate_id' => (string) $candidateId,
            'tenant_id' => (string) $tenantId,
            'dataset_id' => $datasetId,
            'dataset_version' => $datasetVersion,
        ]);

        return $this->candidatePayload($candidate);
    }

    private function assertDatasetRef(string $datasetId, string $datasetVersion): void
    {
        validator([
            'dataset_id' => $datasetId,
            'dataset_version' => $datasetVersion,
        ], [
            'dataset_id' => 'required|string|max:100',
            'dataset_version' => 'required|string|max:50',
        ])->validate();
    }

    /**
     * 断言只允许确定性判定器认识的字段：不接受「让模型自己评」这类口子。
     *
     * @param  array<string, mixed>  $case
     * @return array{skill_key: string, case_key: string, input: array<string, mixed>, asserts: array<string, mixed>, rubric_version: string}
     */
    private function normalizeEvalCase(array $case): array
    {
        $asserts = is_array($case['asserts'] ?? null) ? $case['asserts'] : [];
        $unknown = array_diff(array_keys($asserts), self::EVAL_ASSERT_KEYS);
        if ($unknown !== []) {
            throw new SkillDomainException('data_incomplete', 'Unsupported assertion: ' . implode(',', $unknown));
        }
        foreach (['contains', 'not_contains', 'required_tools', 'forbidden_tools'] as $listKey) {
            foreach ((array) ($asserts[$listKey] ?? []) as $item) {
                if (! is_string($item) || trim($item) === '' || mb_strlen($item) > 500) {
                    throw new SkillDomainException('data_incomplete', "Assertion [{$listKey}] items must be short strings.");
                }
            }
        }
        if (isset($asserts['equals']) && ! is_array($asserts['equals'])) {
            throw new SkillDomainException('data_incomplete', 'Assertion [equals] must be a key=>value map.');
        }

        $validated = validator($case, [
            'skill_key' => 'required|string|max:100',
            'case_key' => 'required|string|regex:/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/',
            'input' => 'required|array',
            'asserts' => 'required|array',
            'rubric_version' => 'sometimes|nullable|string|max:50',
        ])->validate();

        return [
            'skill_key' => (string) $validated['skill_key'],
            'case_key' => (string) $validated['case_key'],
            'input' => (array) $validated['input'],
            'asserts' => (array) $validated['asserts'],
            'rubric_version' => (string) ($validated['rubric_version'] ?? '') ?: CandidateEvaluator::EVALUATOR_VERSION,
        ];
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
