<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Services;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\ExecutionIdentity;
use MultiTenantSaas\Context\TaskAuthEnvelope;
use MultiTenantSaas\Contracts\ExecutionIdentityContract;
use MultiTenantSaas\Modules\AiSkill\Contracts\ActorResolverContract;
use MultiTenantSaas\Modules\AiSkill\Contracts\AdoptionPolicyContract;
use MultiTenantSaas\Modules\AiSkill\Contracts\SkillTrainingServiceContract;
use MultiTenantSaas\Modules\AiSkill\DTOs\AdoptionDecision;
use MultiTenantSaas\Modules\AiSkill\DTOs\RequestKey;
use MultiTenantSaas\Modules\AiSkill\Exceptions\SkillDomainException;
use MultiTenantSaas\Modules\AiSkill\Jobs\RunSkillTrainingJob;
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
        // 窗口在登记时冻结成规范键：授权面不能等到执行时再决定
        $manifest = TrainingDataset::freeze((array) ($trainingSpec['dataset_manifest'] ?? []), $tenantId);
        $spec = array_replace($trainingSpec, ['dataset_manifest' => $manifest]);
        $fingerprint = $this->fingerprint($spec);

        $created = null;
        $result = DB::transaction(function () use ($manifest, $spec, $requestKey, $tenantId, $actor, $fingerprint, &$created): array {
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
                'mode' => (string) $spec['mode'],
                'dataset_manifest' => $manifest,
                'config_fingerprint' => (string) ($spec['config_fingerprint'] ?? ''),
                'prompt_fingerprint' => (string) ($spec['prompt_fingerprint'] ?? ''),
                'model_ref' => $spec['model_ref'] ?? null,
                'budget' => $spec['budget'] ?? null,
                'cost_micro' => $spec['cost_micro'] ?? null,
                'status' => SkillTrainingRun::STATUS_QUEUED,
                'request_key' => $requestKey->key,
                'input_fingerprint' => $fingerprint,
            ]);
            $created = (int) $run->getKey();

            return [
                'training_run_id' => (string) $run->getKey(),
                'status' => (string) $run->status,
            ];
        }, 3);

        // 只在事务提交后派发；执行器默认关闭（试点开关），同步驱动下不会误跑
        if ($created !== null && config('ai-skill.training.enabled') === true) {
            RunSkillTrainingJob::dispatch($created, $tenantId)
                ->withTaskAuthEnvelope($this->taskEnvelope('ai-skill.training-run', $tenantId));
        }

        return $result;
    }

    /**
     * 派发信封：HTTP 触发继承当前身份（不扩大上限）；无请求身份时用显式受信 service。
     */
    private function taskEnvelope(string $subjectId, int $tenantId): TaskAuthEnvelope
    {
        $identity = app(ExecutionIdentityContract::class)->current();

        if ($identity instanceof ExecutionIdentity) {
            return TaskAuthEnvelope::fromIdentity($identity, $subjectId);
        }

        return TaskAuthEnvelope::forService($subjectId, $tenantId, $subjectId);
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
     * 登记候选（训练处理器唯一写入路径；无 HTTP 入口）。
     *
     * 授权事实取自已入库的 run 行（enqueue 时已校验 actor/tenant），因此后台 worker 与
     * Web 路径共用同一条判据；本方法**不接收 validation**：判据只能由独立评测器写入，
     * 候选生成方无法自评。正文变化会清空既有 validation，必须重新评测。
     *
     * @param  array<string, mixed>  $skillDraft
     * @param  list<string>  $evidenceRefs
     * @return array<string, mixed>
     */
    public function proposeCandidate(int $trainingRunId, string $candidateKey, array $skillDraft, array $evidenceRefs): array
    {
        return DB::transaction(function () use ($trainingRunId, $candidateKey, $skillDraft, $evidenceRefs): array {
            $run = SkillTrainingRun::withoutGlobalScopes()->whereKey($trainingRunId)->lockForUpdate()->first();
            if ($run === null || (int) $run->tenant_id <= 0) {
                throw new SkillDomainException('scope_denied', 'Training run not found.');
            }
            if (in_array((string) $run->status, [SkillTrainingRun::STATUS_CANCELLED, SkillTrainingRun::STATUS_FAILED], true)) {
                throw new SkillDomainException('version_conflict', 'A closed training run cannot register candidates.');
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
                    'validation' => [],
                    'status' => SkillCandidate::STATUS_PROPOSED,
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
        // 来源合法性、窗口显式性由 TrainingDataset::freeze 在同一次登记里判定
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
            // 执行事实：效果门要能区分「模型真跑过」与「状态流转过」
            'llm_calls' => $run->llm_calls,
            'usage' => $run->usage,
            'cost_source' => $run->cost_source,
            'started_at' => $run->started_at?->toISOString(),
            'finished_at' => $run->finished_at?->toISOString(),
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
