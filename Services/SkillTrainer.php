<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\AiTextServiceContract;
use MultiTenantSaas\Contracts\UsageSettlementContract;
use MultiTenantSaas\Modules\AiSkill\DTOs\AuthorizedDatasetSpec;
use MultiTenantSaas\Modules\AiSkill\Exceptions\SkillDomainException;
use MultiTenantSaas\Modules\AiSkill\Models\SkillTrainingRun;

/**
 * 异步 LLM 训练处理器（WP-147a / BL-144+147）。
 *
 * 只消费已授权、已冻结的训练任务：采样走 ExperienceSourceAdapter 的授权门，
 * 一次任务最多一次 LLM 调用（重试即重复烧 token），产物只能是候选，
 * 绝不能写 validation 或发布版本（判据归独立评测器与 AdoptionPolicy）。
 *
 * 幂等由单条 queued→running 的原子 UPDATE 保证：重复投递的第二份直接空转。
 * 取消优先于终态：LLM 已花掉的 token 仍要记账为事实，但不再产出候选。
 * 成本无价格来源时 cost_micro 保持 null=unknown，不按 0 记。
 */
final class SkillTrainer
{
    private const USAGE_KIND = 'aiskill_training';

    private int $llmCalls = 0;

    /** @var array<string, mixed> */
    private array $usage = [];

    public function __construct(
        private readonly AiTextServiceContract $llm,
        private readonly UsageSettlementContract $settlements,
        private readonly SkillTrainingService $training,
        private readonly EvidenceDesensitizer $desensitizer = new EvidenceDesensitizer,
    ) {}

    /**
     * @return string queued|running 之外不执行；返回终态标签供测试与日志断言
     */
    public function handle(int $trainingRunId): string
    {
        $this->llmCalls = 0;
        $this->usage = [];

        if (! $this->claim($trainingRunId)) {
            return 'skipped';
        }
        $run = SkillTrainingRun::withoutGlobalScopes()->whereKey($trainingRunId)->first();
        if ($run === null) {
            return 'skipped';
        }

        try {
            $this->assertBudget($run);
            $evidence = $this->sampleEvidence($run);
            $drafts = $this->generate($run, $evidence['items'], $evidence['refs']);
        } catch (\Throwable $e) {
            $this->writeFacts($run);
            $this->markFailed($run, $e);

            return 'failed';
        }

        // 事实先落库：token 已花掉就是花掉了，之后才被取消也不抹去用量
        $this->writeFacts($run);

        if ($this->statusOf($run) !== SkillTrainingRun::STATUS_RUNNING) {
            return 'cancelled';
        }

        foreach ($drafts as $candidateKey => $draft) {
            $this->training->proposeCandidate(
                (int) $run->getKey(),
                (string) $candidateKey,
                $draft,
                $this->refsFor($draft, $evidence['refs']),
            );
        }

        $this->transition($run, SkillTrainingRun::STATUS_RUNNING, SkillTrainingRun::STATUS_COMPLETED);

        return 'completed';
    }

    /** 单条原子 UPDATE 完成领取：抢不到即重复投递或已终态，直接空转。 */
    private function claim(int $trainingRunId): bool
    {
        return DB::table('ai_skill_training_runs')
            ->where('training_run_id', $trainingRunId)
            ->where('status', SkillTrainingRun::STATUS_QUEUED)
            ->update([
                'status' => SkillTrainingRun::STATUS_RUNNING,
                'started_at' => now(),
                'updated_at' => now(),
            ]) === 1;
    }

    private function statusOf(SkillTrainingRun $run): ?string
    {
        return DB::table('ai_skill_training_runs')
            ->where('training_run_id', $run->getKey())
            ->value('status');
    }

    private function transition(SkillTrainingRun $run, string $from, string $to, string $error = ''): void
    {
        $patch = ['status' => $to, 'finished_at' => now(), 'updated_at' => now()];
        if ($error !== '') {
            $patch['error'] = $error;
        }
        // 条件更新：状态被并发改写时不覆盖别人的终态
        DB::table('ai_skill_training_runs')
            ->where('training_run_id', $run->getKey())
            ->where('status', $from)
            ->update($patch);
    }

    private function assertBudget(SkillTrainingRun $run): void
    {
        $budget = is_array($run->budget) ? $run->budget : [];
        foreach (['max_calls', 'max_tokens', 'max_cost_micro'] as $key) {
            if (isset($budget[$key]) && (! is_numeric($budget[$key]) || (float) $budget[$key] < 0)) {
                throw new SkillDomainException('budget_exceeded', 'Invalid training budget limit.');
            }
        }
        $maxCalls = (int) ($budget['max_calls'] ?? $this->config('budget.max_calls', 1));
        if ($maxCalls < 1) {
            throw new SkillDomainException('budget_exceeded', 'Training budget allows no model call.');
        }
    }

    /**
     * @return array{items: list<array<string, mixed>>, refs: list<string>}
     */
    private function sampleEvidence(SkillTrainingRun $run): array
    {
        $manifest = is_array($run->dataset_manifest) ? $run->dataset_manifest : [];
        $from = (string) ($manifest['time_from'] ?? '');
        $to = (string) ($manifest['time_to'] ?? '');
        if ($from === '' || $to === '') {
            throw new SkillDomainException('data_incomplete', 'Frozen dataset window is required.');
        }
        $spec = new AuthorizedDatasetSpec(
            sourceTypes: array_values(array_map('strval', (array) ($manifest['source_types'] ?? []))),
            timeFromIso: $from,
            timeToIso: $to,
            fieldAllowlist: array_values(array_map('strval', (array) ($manifest['field_allowlist'] ?? []))),
            sampleIds: array_values(array_map('strval', (array) ($manifest['sample_ids'] ?? []))),
            tenantId: (string) (int) $run->tenant_id,
            actorType: (string) $run->actor_type,
            actorId: (string) (int) $run->actor_id,
            accessMode: (string) ($manifest['access_mode'] ?? 'metadata_only'),
            promptFingerprint: $run->prompt_fingerprint !== null && $run->prompt_fingerprint !== ''
                ? (string) $run->prompt_fingerprint
                : null,
        );

        $adapter = new ExperienceSourceAdapter(
            $this->desensitizer,
            new TrustedTrainingActor((int) $run->tenant_id, (int) $run->actor_id),
        );
        $batch = $adapter->sample($spec);
        if ($batch->isEmpty()) {
            throw new SkillDomainException('data_incomplete', 'Authorized dataset window produced no evidence.');
        }

        $items = array_slice(
            (array) $this->desensitizer->mask($batch->items),
            0,
            max(1, (int) $this->config('max_evidence_items', 40)),
        );
        $refs = [];
        foreach ($batch->coverage as $source => $count) {
            $refs[] = 'dataset:' . $source . ':' . (int) $count;
        }

        return ['items' => $items, 'refs' => $refs];
    }

    /**
     * 一次 LLM 调用产出候选；任何一步不过都抛领域异常，不落半成品。
     *
     * @param  list<array<string, mixed>>  $evidence
     * @param  list<string>  $evidenceRefs
     * @return array<string, array<string, mixed>> candidateKey => skill_draft
     */
    private function generate(SkillTrainingRun $run, array $evidence, array $evidenceRefs): array
    {
        $budget = is_array($run->budget) ? $run->budget : [];
        $maxTokens = min(
            (int) $this->config('max_output_tokens', 4000),
            isset($budget['max_tokens']) ? max(1, (int) $budget['max_tokens']) : PHP_INT_MAX,
        );
        $options = [
            'max_tokens' => $maxTokens,
            'temperature' => (float) $this->config('temperature', 0.2),
        ];
        if (is_string($run->model_ref) && $run->model_ref !== '') {
            $options['model'] = $run->model_ref;
        }

        $response = $this->llm->chat([
            ['role' => 'system', 'content' => $this->systemPrompt($run)],
            ['role' => 'user', 'content' => (string) json_encode([
                'mode' => $run->mode,
                'dataset_fingerprint' => (string) $run->input_fingerprint,
                'evidence' => $evidence,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
        ], $options);
        $this->llmCalls++;
        $this->usage = $this->normalizeUsage($response->usage, $response->model);
        $this->settle($run);

        return $this->parseDrafts($run, $response->content);
    }

    private function systemPrompt(SkillTrainingRun $run): string
    {
        $tenant = (int) $run->tenant_id;
        $allowlist = array_values(array_map('strval', (array) $this->config('tool_allowlist', [])));

        return <<<PROMPT
        你是租户技能训练器。依据给定的脱敏经验摘要，提出至多 {$this->int('max_candidates', 3)} 个可复用 Skill 候选。
        只输出一个 JSON 对象，不要解释、不要 Markdown 代码块之外的文字：
        {"candidates":[{"candidate_key":"<1-64位 [A-Za-z0-9_-]>","skill_draft":{ ... }}]}
        skill_draft 字段与约束：
        - skill_key：必须以 "tenant.{$tenant}." 开头（租户命名空间），version：语义化版本字符串
        - title/description：中文简述；execution：instructions|chain|mixed
        - content_body：instructions 为提示词正文；chain/mixed 为 JSON 字符串 {"schema_version":1,"chain":{"steps":[{"name","type":"tool","tool","output_key"}]}}
        - input_schema：JSON Schema（type=object）；applicability/success_criteria：对象或数组
        - tool_deps：只能取以下白名单 slug {$this->json($allowlist)}；execution 非 instructions 时每个 step 的 tool 都必须在 tool_deps 内
        - risk：{"level":"low|medium|high","business_write":true|false,"requires_confirmation":true|false}
        禁止：编造证据、输出任何凭据或真实联系方式、跨租户命名空间、写操作伪装成只读。
        PROMPT;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function parseDrafts(SkillTrainingRun $run, string $content): array
    {
        $decoded = json_decode($this->extractJson($content), true, 512, JSON_THROW_ON_ERROR);
        $items = is_array($decoded) ? ($decoded['candidates'] ?? null) : null;
        if (! is_array($items)) {
            throw new SkillDomainException('data_incomplete', 'Model response has no candidates list.');
        }

        $tenant = (int) $run->tenant_id;
        $allowlist = array_map('strval', (array) $this->config('tool_allowlist', []));
        $max = max(1, (int) $this->config('max_candidates', 3));
        $drafts = [];
        $seen = [];

        foreach ($items as $item) {
            if (count($drafts) >= $max) {
                break;
            }
            if (! is_array($item)) {
                continue;
            }
            $key = (string) ($item['candidate_key'] ?? '');
            $draft = is_array($item['skill_draft'] ?? null) ? $item['skill_draft'] : [];
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $key) !== 1) {
                continue;
            }
            if (! $this->draftAllowed($draft, $tenant, $allowlist)) {
                continue;
            }
            // 只保留白名单字段且结构合法的草稿：多余输出或坏草稿不得混进候选
            $validator = validator($draft, SkillDraftRules::rules());
            if ($validator->errors()->isNotEmpty()) {
                continue;
            }
            $validated = $validator->validated();
            $identity = $validated['skill_key'] . '@' . $validated['version'];
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $drafts[$key] = $validated;
        }

        if ($drafts === []) {
            throw new SkillDomainException('data_incomplete', 'Model produced no schema-valid Skill draft.');
        }

        return $drafts;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  list<string>  $allowlist
     */
    private function draftAllowed(array $draft, int $tenant, array $allowlist): bool
    {
        $skillKey = (string) ($draft['skill_key'] ?? '');
        if ($tenant <= 0 || ! str_starts_with($skillKey, 'tenant.' . $tenant . '.')) {
            return false;
        }
        $deps = array_map('strval', (array) ($draft['tool_deps'] ?? []));
        if ($deps !== [] && array_diff($deps, $allowlist) !== []) {
            return false;
        }
        $execution = (string) ($draft['execution'] ?? '');
        if ($execution === '' || $execution === 'instructions') {
            return true;
        }
        $body = json_decode((string) ($draft['content_body'] ?? ''), true);
        if (! is_array($body) || ($body['schema_version'] ?? null) !== 1 || ! is_array($body['chain'] ?? null)) {
            return false;
        }
        foreach ((array) ($body['chain']['steps'] ?? []) as $step) {
            if (! is_array($step) || ($step['type'] ?? null) !== 'tool'
                || ! in_array((string) ($step['tool'] ?? ''), $deps, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 证据引用：数据集覆盖 + 该候选自己的键，便于审证时回查而不复制正文。
     *
     * @param  array<string, mixed>  $draft
     * @param  list<string>  $refs
     * @return list<string>
     */
    private function refsFor(array $draft, array $refs): array
    {
        $out = $refs;
        $out[] = 'draft:' . (string) $draft['skill_key'] . '@' . (string) $draft['version'];

        return array_values(array_unique($out));
    }

    /**
     * @param  array<string, mixed>  $usage
     * @return array<string, mixed>
     */
    private function normalizeUsage(array $usage, string $model): array
    {
        $input = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
        $out = [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => (int) ($usage['total_tokens'] ?? ($input + $output)),
        ];
        if ($model !== '') {
            $out['model'] = $model;
        }

        return $out;
    }

    private function settle(SkillTrainingRun $run): void
    {
        if ($this->usage['input_tokens'] <= 0 && $this->usage['output_tokens'] <= 0) {
            return;
        }
        $this->settlements->settle(
            substr(hash('sha1', 'aiskill-training|' . $run->tenant_id . '|' . $run->getKey()), 0, 32),
            self::USAGE_KIND,
            (int) $run->tenant_id,
            (int) $run->actor_id > 0 ? (int) $run->actor_id : null,
            (int) $this->usage['input_tokens'],
            (int) $this->usage['output_tokens'],
        );
    }

    private function writeFacts(SkillTrainingRun $run): void
    {
        // 无价格来源 → 成本仍是 unknown（null），绝不写 0
        $cost = $run->cost_micro;
        DB::table('ai_skill_training_runs')
            ->where('training_run_id', $run->getKey())
            ->update([
                'llm_calls' => $this->llmCalls,
                'usage' => $this->usage === [] ? null : (string) json_encode($this->usage, JSON_UNESCAPED_UNICODE),
                'cost_source' => $cost === null ? 'unknown' : 'provider',
                'updated_at' => now(),
            ]);
    }

    private function markFailed(SkillTrainingRun $run, \Throwable $e): void
    {
        $reason = $e instanceof SkillDomainException ? $e->getMessage() : 'Training execution failed.';
        Log::warning('[AiSkill] 训练任务执行失败', [
            'training_run_id' => (int) $run->getKey(),
            'tenant_id' => (int) $run->tenant_id,
            'reason' => $reason,
        ]);
        $this->transition(
            $run,
            SkillTrainingRun::STATUS_RUNNING,
            SkillTrainingRun::STATUS_FAILED,
            (string) mb_substr((string) $this->desensitizer->mask($reason), 0, 500),
        );
    }

    /** 去掉模型可能包裹的 Markdown 代码块，取出 JSON 主体。 */
    private function extractJson(string $content): string
    {
        $trimmed = trim($content);
        if (str_starts_with($trimmed, '```')) {
            $start = (int) strpos($trimmed, "\n");
            $end = (int) strrpos($trimmed, '```');
            if ($start !== false && $end > $start) {
                return trim((string) substr($trimmed, $start + 1, $end - $start - 1));
            }
        }
        $begin = strpos($trimmed, '{');
        $finish = strrpos($trimmed, '}');
        if ($begin !== false && $finish !== false && $finish > $begin) {
            return (string) substr($trimmed, $begin, $finish - $begin + 1);
        }

        return $trimmed;
    }

    private function config(string $key, mixed $default): mixed
    {
        return config('ai-skill.training.' . $key, $default);
    }

    private function int(string $key, int $default): int
    {
        return (int) $this->config($key, $default);
    }

    /**
     * @param  list<string>  $values
     */
    private function json(array $values): string
    {
        return (string) json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
