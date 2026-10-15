<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Services;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Modules\AiSkill\Contracts\ActorResolverContract;
use MultiTenantSaas\Modules\AiSkill\Contracts\AdoptionPolicyContract;
use MultiTenantSaas\Modules\AiSkill\Contracts\SkillTrainingServiceContract;
use MultiTenantSaas\Modules\AiSkill\DTOs\AdoptionDecision;
use MultiTenantSaas\Modules\AiSkill\DTOs\RequestKey;
use MultiTenantSaas\Modules\AiSkill\Exceptions\SkillDomainException;
use MultiTenantSaas\Modules\AiSkill\Models\SkillCandidate;
use MultiTenantSaas\Modules\AiSkill\Models\SkillTrainingRun;

/**
 * 训练任务服务（D 包 / BL-145+146 后端）。
 *
 * enqueue：授权来源/预算/指纹入档；request_key 幂等。
 * 只登记受控训练任务，不开放 SQL/任意工具给分析模型。
 * 模型完成 ≠ 候选可采用；采用统一走 AdoptionPolicy。
 * 取消不重复执行已发请求；结果不明保留对账状态（cost_micro=null → unknown）。
 */
final class SkillTrainingService implements SkillTrainingServiceContract
{
    public function __construct(
        private readonly ActorResolverContract $actors,
        private readonly AdoptionPolicyContract $adoption,
    ) {}

    /**
     * @param  array<string, mixed>  $trainingSpec  mode/dataset_manifest/config_fingerprint/prompt_fingerprint/budget
     * @return array{training_run_id: string, status: string}
     */
    public function enqueue(array $trainingSpec, RequestKey $requestKey): array
    {
        $actor = $this->actor();
        $tenantId = (int) $actor['tenant_id'];
        $this->assertSpec($trainingSpec);
        $fingerprint = $this->fingerprint($trainingSpec);

        return DB::transaction(function () use ($trainingSpec, $requestKey, $tenantId, $actor, $fingerprint): array {
            $existing = SkillTrainingRun::query()->where('tenant_id', $tenantId)
                ->where('request_key', $requestKey->key)->lockForUpdate()->first();
            if ($existing !== null) {
                if ((string) $existing->input_fingerprint !== $fingerprint) {
                    throw new SkillDomainException('version_conflict', 'Request key already used with different training input.');
                }

                return [
                    'training_run_id' => (string) $existing->getKey(),
                    'status' => (string) $existing->status,
                ];
            }

            $run = SkillTrainingRun::create([
                'tenant_id' => $tenantId,
                'actor_type' => (string) ($actor['actor_type'] ?? 'operator'),
                'actor_id' => (int) ($actor['actor_id'] ?? 0),
                'mode' => (string) $trainingSpec['mode'],
                'dataset_manifest' => $trainingSpec['dataset_manifest'] ?? [],
                'config_fingerprint' => (string) ($trainingSpec['config_fingerprint'] ?? ''),
                'prompt_fingerprint' => (string) ($trainingSpec['prompt_fingerprint'] ?? ''),
                'model_ref' => $trainingSpec['model_ref'] ?? null,
                'budget' => $trainingSpec['budget'] ?? null,
                'cost_micro' => $trainingSpec['cost_micro'] ?? null,
                'status' => SkillTrainingRun::STATUS_QUEUED,
                'request_key' => $requestKey->key,
                'input_fingerprint' => $fingerprint,
            ]);

            return [
                'training_run_id' => (string) $run->getKey(),
                'status' => (string) $run->status,
            ];
        }, 3);
    }

    /**
     * @return array{training_run_id: string, status: string}
     */
    public function cancel(int $trainingRunId): array
    {
        return DB::transaction(function () use ($trainingRunId): array {
            $run = $this->lockRun($trainingRunId);
            if ($run->isTerminal()) {
                return [
                    'training_run_id' => (string) $run->getKey(),
                    'status' => (string) $run->status,
                ];
            }
            // 取消不重复执行已发请求；已产生的 cost 保留事实
            $run->status = SkillTrainingRun::STATUS_CANCELLED;
            $run->save();

            return [
                'training_run_id' => (string) $run->getKey(),
                'status' => (string) $run->status,
            ];
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $trainingRunId): array
    {
        $run = $this->ownedRun($trainingRunId);

        return $this->runPayload($run);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?string $status = null, int $limit = 50): array
    {
        $actor = $this->actor();
        $limit = min(100, max(1, $limit));
        $query = SkillTrainingRun::query()->where('tenant_id', (int) $actor['tenant_id']);
        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }
        // 同秒分页以主键收尾
        $rows = $query->orderByDesc('created_at')->orderByDesc((new SkillTrainingRun)->getKeyName())
            ->limit($limit)->get();

        return $rows->map(fn (SkillTrainingRun $run): array => $this->runPayload($run))->all();
    }

    /**
     * 登记候选（训练 job / 测试夹具调用）。模型输出不能直接执行。
     *
     * @param  array<string, mixed>  $skillDraft
     * @param  array<string, mixed>  $evidenceRefs
     * @param  array<string, mixed>  $validation
     * @return array<string, mixed>
     */
    public function proposeCandidate(
        int $trainingRunId,
        string $candidateKey,
        array $skillDraft,
        array $evidenceRefs,
        array $validation,
    ): array {
        return DB::transaction(function () use ($trainingRunId, $candidateKey, $skillDraft, $evidenceRefs, $validation): array {
            $run = $this->lockRun($trainingRunId);
            if ($run->status === SkillTrainingRun::STATUS_CANCELLED) {
                throw new SkillDomainException('version_conflict', 'Training run is cancelled.');
            }

            $existing = SkillCandidate::withoutGlobalScopes()->where('training_run_id', $run->getKey())
                ->where('candidate_key', $candidateKey)->lockForUpdate()->first();
            if ($existing !== null && in_array($existing->status, ['adopted', 'rejected'], true)) {
                throw new SkillDomainException('version_conflict', 'A decided candidate is immutable.');
            }
            $candidate = SkillCandidate::withoutGlobalScopes()->updateOrCreate(
                [
                    'training_run_id' => $run->getKey(),
                    'candidate_key' => $candidateKey,
                ],
                [
                    'tenant_id' => $run->tenant_id,
                    'skill_draft' => $skillDraft,
                    'evidence_refs' => $evidenceRefs,
                    'validation' => $validation,
                    'status' => ($validation['passed'] ?? false) === true
                        ? SkillCandidate::STATUS_VALIDATED
                        : SkillCandidate::STATUS_PROPOSED,
                ],
            );

            return $this->candidatePayload($candidate);
        }, 3);
    }

    /**
     * 采用/拒绝裁决。decision 只来自 AdoptionPolicy，UI 不得自行判定低风险。
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function decideCandidate(int $candidateId, array $context = [], ?string $force = null): array
    {
        return DB::transaction(function () use ($candidateId, $force): array {
            $actor = $this->actor();
            $candidate = SkillCandidate::query()->whereKey($candidateId)
                ->where('tenant_id', (int) $actor['tenant_id'])->lockForUpdate()->first();
            if ($candidate === null) {
                throw new SkillDomainException('scope_denied', 'Candidate not found.');
            }
            if ($candidate->status === SkillCandidate::STATUS_ADOPTED
                || $candidate->status === SkillCandidate::STATUS_REJECTED) {
                return $this->candidatePayload($candidate);
            }

            $decision = $force === 'reject'
                ? new AdoptionDecision(AdoptionDecision::DENIED, 'review_required')
                : $this->adoption->evaluate([
                    'skill_draft' => (array) $candidate->skill_draft,
                    'validation' => (array) $candidate->validation,
                    'evidence_refs' => (array) $candidate->evidence_refs,
                ], app(CandidatePublication::class)->context($candidate));

            $payload = [
                'decision' => $decision->decision,
                'reason' => $decision->reason,
                'evidence' => $decision->evidence,
                'limited_tenant_ids' => $decision->limitedTenantIds,
            ];
            $candidate->adoption_policy_result = $payload;

            if ($force === 'reject' || $decision->isDenied()) {
                $candidate->status = SkillCandidate::STATUS_REJECTED;
            } elseif ($decision->isAuto() && $force === 'adopt') {
                app(CandidatePublication::class)->publish($candidate);
                $candidate->status = SkillCandidate::STATUS_ADOPTED;
            } else {
                // needs_review：保持可审，不自动采用
                $candidate->status = SkillCandidate::STATUS_VALIDATED;
            }
            $candidate->save();

            return $this->candidatePayload($candidate);
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCandidate(int $candidateId): array
    {
        $actor = $this->actor();
        $candidate = SkillCandidate::query()->whereKey($candidateId)
            ->where('tenant_id', (int) $actor['tenant_id'])->first();
        if ($candidate === null) {
            throw new SkillDomainException('scope_denied', 'Candidate not found.');
        }

        return $this->candidatePayload($candidate);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listCandidates(?int $trainingRunId = null, int $limit = 50): array
    {
        $actor = $this->actor();
        $limit = min(100, max(1, $limit));
        $query = SkillCandidate::query()->where('tenant_id', (int) $actor['tenant_id']);
        if ($trainingRunId !== null) {
            $query->where('training_run_id', $trainingRunId);
        }
        $rows = $query->orderByDesc((new SkillCandidate)->getKeyName())->limit($limit)->get();

        return $rows->map(fn (SkillCandidate $candidate): array => $this->candidatePayload($candidate))->all();
    }

    private function assertSpec(array $spec): void
    {
        $mode = (string) ($spec['mode'] ?? '');
        $allowed = [
            SkillTrainingRun::MODE_MODULE_DOCS,
            SkillTrainingRun::MODE_HISTORY,
            SkillTrainingRun::MODE_RECOMMEND_OPTIMIZE,
            SkillTrainingRun::MODE_FAILURE_DIAGNOSIS,
        ];
        if (! in_array($mode, $allowed, true)) {
            throw new SkillDomainException('data_incomplete', 'Unsupported training mode.');
        }
        $manifest = $spec['dataset_manifest'] ?? null;
        if (! is_array($manifest) || $manifest === []) {
            throw new SkillDomainException('data_incomplete', 'dataset_manifest is required.');
        }
        // 来源必须显式授权，禁止自由 URL / 文件系统路径
        foreach ((array) ($manifest['source_types'] ?? []) as $source) {
            if (! is_string($source) || ! preg_match('/^[a-z0-9_]+$/D', $source)) {
                throw new SkillDomainException('scope_denied', 'Invalid dataset source.');
            }
        }
        if (isset($manifest['remote_url']) || isset($manifest['file_path'])) {
            throw new SkillDomainException('scope_denied', 'Remote URL or filesystem path is not allowed.');
        }
        $budget = $spec['budget'] ?? null;
        if ($budget !== null) {
            if (! is_array($budget)) {
                throw new SkillDomainException('budget_exceeded', 'Invalid budget.');
            }
            foreach (['max_calls', 'max_tokens', 'max_cost_micro'] as $key) {
                if (isset($budget[$key]) && (! is_numeric($budget[$key]) || (float) $budget[$key] < 0)) {
                    throw new SkillDomainException('budget_exceeded', 'Invalid budget limit.');
                }
            }
        }
    }

    private function lockRun(int $trainingRunId): SkillTrainingRun
    {
        $actor = $this->actor();
        $run = SkillTrainingRun::query()->whereKey($trainingRunId)
            ->where('tenant_id', (int) $actor['tenant_id'])->lockForUpdate()->first();
        if ($run === null) {
            throw new SkillDomainException('scope_denied', 'Training run not found.');
        }

        return $run;
    }

    private function ownedRun(int $trainingRunId): SkillTrainingRun
    {
        $actor = $this->actor();
        $run = SkillTrainingRun::query()->whereKey($trainingRunId)
            ->where('tenant_id', (int) $actor['tenant_id'])->first();
        if ($run === null) {
            throw new SkillDomainException('scope_denied', 'Training run not found.');
        }

        return $run;
    }

    private function actor(): array
    {
        $actor = $this->actors->resolve();
        if (($actor['actor_type'] ?? null) !== 'operator' || (int) ($actor['actor_id'] ?? 0) <= 0
            || (int) ($actor['tenant_id'] ?? 0) <= 0) {
            throw new SkillDomainException('scope_denied');
        }

        return $actor;
    }

    /**
     * @return array<string, mixed>
     */
    private function runPayload(SkillTrainingRun $run): array
    {
        return [
            'training_run_id' => (string) $run->getKey(),
            'mode' => (string) $run->mode,
            'status' => (string) $run->status,
            'dataset_manifest' => (array) $run->dataset_manifest,
            'config_fingerprint' => $run->config_fingerprint,
            'prompt_fingerprint' => $run->prompt_fingerprint,
            'model_ref' => $run->model_ref,
            'budget' => $run->budget,
            // 成本缺失显示 unknown，不显示 0
            'cost_micro' => $run->cost_micro,
            'cost_unknown' => $run->costIsUnknown(),
            'error' => $run->error,
            'created_at' => $run->created_at?->toISOString(),
            'updated_at' => $run->updated_at?->toISOString(),
        ];
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
            'candidate_key' => (string) $candidate->candidate_key,
            'status' => (string) $candidate->status,
            'skill_draft' => (array) $candidate->skill_draft,
            'evidence_refs' => (array) $candidate->evidence_refs,
            'validation' => (array) $candidate->validation,
            'adoption' => $result === [] ? null : [
                'decision' => $result['decision'] ?? null,
                'reason' => $result['reason'] ?? null,
                'limited_tenant_ids' => $result['limited_tenant_ids'] ?? [],
            ],
            'bound_definition_id' => $candidate->bound_definition_id === null
                ? null
                : (string) $candidate->bound_definition_id,
        ];
    }

    private function fingerprint(array $spec): string
    {
        $canonical = $this->canonical($spec);

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($this->canonical(...), $value);
        }

        return $value;
    }
}
