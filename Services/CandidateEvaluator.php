<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MultiTenantSaas\Contracts\AiTextServiceContract;
use MultiTenantSaas\Contracts\TaskChainHarnessContract;
use MultiTenantSaas\Contracts\UsageSettlementContract;
use MultiTenantSaas\Modules\AiSkill\Exceptions\SkillDomainException;
use MultiTenantSaas\Modules\AiSkill\Models\SkillCandidate;
use MultiTenantSaas\Modules\AiSkill\Models\SkillDefinition;
use MultiTenantSaas\Modules\AiSkill\Models\SkillEvalCase;

/**
 * 独立评测器（WP-147b / BL-144）。validation 的唯一写入方。
 *
 * 判据与候选生成彻底分离：样本与断言由平台治理入口冻结在 ai_skill_eval_cases，
 * 训练器与候选正文都改不了；评测器只读候选正文、只写 validation。
 *
 * 执行面只走可信路径：chain/mixed 必须过 TaskChainHarness 的固定只读工具门
 * （L1 + pinned_read_tools 白名单 + 实现指纹），instructions 走单次固定提示词调用；
 * 具备写能力的候选直接拒绝，不产生任何伪证据。
 * 评分全部为确定性断言，不用 LLM 给 LLM 打分。
 */
final class CandidateEvaluator
{
    public const EVIDENCE_LEVEL = 'independent_evaluation';

    public const EVALUATOR_VERSION = 'wp147b-v1';

    private const USAGE_KIND = 'aiskill_evaluation';

    private int $llmCalls = 0;

    /** 被测执行自身消耗的 LLM 调用：固定只读链应为 0，这是效果门的关键事实 */
    private int $orchestrationCalls = 0;

    /** @var array<string, mixed> */
    private array $usage = [];

    public function __construct(
        private readonly TaskChainHarnessContract $harness,
        private readonly AiTextServiceContract $llm,
        private readonly UsageSettlementContract $settlements,
    ) {}

    /**
     * @return string skipped|completed
     */
    public function handle(int $candidateId, string $datasetId, string $datasetVersion): string
    {
        $this->llmCalls = 0;
        $this->orchestrationCalls = 0;
        $this->usage = [];

        $plan = $this->prepare($candidateId, $datasetId, $datasetVersion);
        if ($plan === null) {
            return 'skipped';
        }

        $results = $this->executeCases($plan);
        $this->settle($plan);

        return $this->commit($plan, $results) ? 'completed' : 'skipped';
    }

    /**
     * 事务 A：锁候选、冻结判据、解析基线。任何拒绝都在这里抛出，
     * 因此被拒候选不会留下半份 validation。
     *
     * @return array<string, mixed>|null
     */
    private function prepare(int $candidateId, string $datasetId, string $datasetVersion): ?array
    {
        return DB::transaction(function () use ($candidateId, $datasetId, $datasetVersion): ?array {
            $candidate = SkillCandidate::withoutGlobalScopes()->whereKey($candidateId)->lockForUpdate()->first();
            if ($candidate === null) {
                throw new SkillDomainException('scope_denied', 'Candidate not found.');
            }
            if (in_array((string) $candidate->status, [SkillCandidate::STATUS_ADOPTED, SkillCandidate::STATUS_REJECTED], true)) {
                return null;
            }
            $tenantId = (int) $candidate->tenant_id;
            try {
                $draft = validator((array) $candidate->skill_draft, SkillDraftRules::rules())->validate();
            } catch (ValidationException $e) {
                throw new SkillDomainException('data_incomplete', 'Candidate draft does not match the Skill structure.', previous: $e);
            }
            $this->assertEvaluable($draft, $tenantId);

            $baseline = $this->resolveBaseline((string) $draft['skill_key'], $tenantId);
            $cases = $this->frozenCases($tenantId, $datasetId, $datasetVersion, (string) $draft['skill_key']);

            return [
                'candidate_id' => (int) $candidate->getKey(),
                'tenant_id' => $tenantId,
                'draft' => $draft,
                'draft_sha' => $this->draftSha($draft),
                'baseline' => $baseline,
                'cases' => $cases['items'],
                'manifest_sha' => $cases['manifest_sha'],
                'dataset_id' => $datasetId,
                'dataset_version' => $datasetVersion,
                'rubric_version' => $cases['rubric_version'],
                'min_cases' => max(1, (int) $this->config('evaluation.min_holdout_cases', 10)),
                'repeat' => max(1, min(3, (int) $this->config('evaluation.repeat', 1))),
            ];
        }, 3);
    }

    /**
     * 写能力与非白名单工具一律拒绝：评测器不是让候选「跑一遍看看」的后门。
     *
     * @param  array<string, mixed>  $draft
     */
    private function assertEvaluable(array $draft, int $tenantId): void
    {
        $risk = is_array($draft['risk'] ?? null) ? $draft['risk'] : [];
        if (($risk['business_write'] ?? null) !== false) {
            throw new SkillDomainException('review_required', 'Write-capable candidates are not evaluable by the trusted evaluator.');
        }
        $allowlist = array_map('strval', (array) $this->config('evaluation.tool_allowlist', []));
        $deps = array_map('strval', (array) $draft['tool_deps']);
        if ($deps !== [] && array_diff($deps, $allowlist) !== []) {
            throw new SkillDomainException('review_required', 'Candidate tool dependency is outside the evaluation allowlist.');
        }
        if ($draft['execution'] === SkillDefinition::EXECUTION_INSTRUCTIONS) {
            if ($deps !== []) {
                throw new SkillDomainException('review_required', 'Instructions candidate must declare no tool dependency.');
            }

            return;
        }
        // 固定链的可信性由 harness 判定（L1、pinned_read_tools、租户启用、实现指纹）
        try {
            $this->harness->validatePinned($this->chainDefinition($draft), $tenantId);
        } catch (\InvalidArgumentException $e) {
            throw new SkillDomainException('tool_incompatible', 'Candidate chain is not executable on the trusted path.', previous: $e);
        }
    }

    /**
     * 基线只从已发布且摘要完好的持久行解析，不接受请求指定。
     *
     * @return array<string, mixed>|null
     */
    private function resolveBaseline(string $skillKey, int $tenantId): ?array
    {
        $definition = SkillDefinition::query()
            ->where('status', SkillDefinition::STATUS_PUBLISHED)
            ->where('skill_key', $skillKey)
            ->where(fn ($q) => $q->where(fn ($g) => $g->whereIn('scope_type', [SkillDefinition::SCOPE_MODULE, SkillDefinition::SCOPE_PROJECT])->where('scope_id', 0))
                ->orWhere(fn ($own) => $own->where('scope_type', SkillDefinition::SCOPE_TENANT)->where('scope_id', $tenantId)))
            ->orderByDesc('version')
            ->orderByDesc((new SkillDefinition)->getKeyName())
            ->first();
        if ($definition === null) {
            return null;
        }

        return [
            'definition_id' => (int) $definition->getKey(),
            'version' => (string) $definition->version,
            'execution' => (string) $definition->execution,
            'content_body' => $definition->snapshot(),
            'tool_deps' => array_map('strval', (array) $definition->tool_deps),
        ];
    }

    /**
     * 冻结样本：validation 分集，按 case_key 稳定排序，摘要参与 manifest。
     *
     * @return array{items: list<array<string, mixed>>, manifest_sha: string, rubric_version: string}
     */
    private function frozenCases(int $tenantId, string $datasetId, string $datasetVersion, string $skillKey): array
    {
        $rows = SkillEvalCase::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('dataset_id', $datasetId)
            ->where('dataset_version', $datasetVersion)
            ->where('split', SkillEvalCase::SPLIT_VALIDATION)
            ->where('skill_key', $skillKey)
            ->orderBy('case_key')
            ->orderBy((new SkillEvalCase)->getKeyName())
            ->limit(max(1, (int) $this->config('evaluation.max_cases', 50)))
            ->get();

        $items = [];
        $ledger = [];
        foreach ($rows as $row) {
            $digest = SkillEvalCase::digestFor((array) $row->input, (array) $row->asserts);
            if (! hash_equals((string) $row->content_sha256, $digest)) {
                throw new SkillDomainException('verification_missing', 'Frozen evaluation case digest mismatch.');
            }
            $items[] = [
                'case_key' => (string) $row->case_key,
                'input' => (array) $row->input,
                'asserts' => (array) $row->asserts,
                'content_sha256' => $digest,
            ];
            $ledger[] = $row->case_key . ':' . $digest;
        }

        if (count($items) < max(1, (int) $this->config('evaluation.min_holdout_cases', 10))) {
            throw new SkillDomainException('data_incomplete', 'Frozen validation split has fewer cases than the adoption bar requires.');
        }

        return [
            'items' => $items,
            'manifest_sha' => hash('sha256', implode('|', $ledger)),
            'rubric_version' => (string) ($rows->first()?->rubric_version ?? self::EVALUATOR_VERSION),
        ];
    }

    /**
     * 事务外执行：候选与基线各跑同一批冻结样本。
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, array<string, mixed>>
     */
    private function executeCases(array $plan): array
    {
        $results = [];
        foreach ($plan['cases'] as $case) {
            $passed = true;
            $severe = false;
            $failures = [];
            for ($round = 0; $round < (int) $plan['repeat']; $round++) {
                $observed = $this->runOnce($plan['draft'], (array) $case['input'], (int) $plan['tenant_id']);
                $roundFailures = $this->assertCase($case, $observed);
                $passed = $passed && $roundFailures === [];
                $severe = $severe || $observed['severe'] === true;
                $failures = $roundFailures === [] ? $failures : $roundFailures;
            }
            $baselinePassed = null;
            if ($plan['baseline'] !== null) {
                $baselinePassed = true;
                for ($round = 0; $round < (int) $plan['repeat']; $round++) {
                    $observed = $this->runBaselineOnce($plan, (array) $case['input']);
                    $baselinePassed = $baselinePassed && $this->assertCase($case, $observed) === [];
                }
            }
            $results[(string) $case['case_key']] = [
                'passed' => $passed,
                'failures' => $failures,
                'severe' => $severe,
                'baseline_passed' => $baselinePassed,
            ];
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $input
     * @return array{outputs: array<string, mixed>, slugs: list<string>, text: string, severe: bool}
     */
    private function runOnce(array $draft, array $input, int $tenantId): array
    {
        if ($draft['execution'] === SkillDefinition::EXECUTION_INSTRUCTIONS) {
            return $this->runPrompt((string) $draft['content_body'], $input);
        }

        return $this->runChain($this->chainDefinition($draft), $input, $tenantId);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $input
     * @return array{outputs: array<string, mixed>, slugs: list<string>, text: string, severe: bool}
     */
    private function runBaselineOnce(array $plan, array $input): array
    {
        $baseline = $plan['baseline'];
        if ((string) $baseline['execution'] === SkillDefinition::EXECUTION_INSTRUCTIONS) {
            return $this->runPrompt((string) $baseline['content_body'], $input);
        }

        return $this->runChain($this->chainDefinition([
            'skill_key' => (string) $plan['draft']['skill_key'],
            'version' => (string) $baseline['version'],
            'title' => 'baseline',
            'execution' => (string) $baseline['execution'],
            'content_body' => (string) $baseline['content_body'],
            'tool_deps' => $baseline['tool_deps'],
        ]), $input, (int) $plan['tenant_id']);
    }

    private function settle(array $plan): void
    {
        $input = (int) ($this->usage['input_tokens'] ?? 0);
        $output = (int) ($this->usage['output_tokens'] ?? 0);
        if ($input <= 0 && $output <= 0) {
            return;
        }
        $this->settlements->settle(
            substr(hash('sha1', 'aiskill-evaluation|' . $plan['tenant_id'] . '|' . $plan['candidate_id'] . '|' . $plan['manifest_sha']), 0, 32),
            self::USAGE_KIND,
            (int) $plan['tenant_id'],
            null,
            $input,
            $output,
        );
    }

    /**
     * 固定链执行：只经 harness，不接受客户端回填，步骤全部由服务端工具产出。
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $input
     * @return array{outputs: array<string, mixed>, slugs: list<string>, text: string, severe: bool}
     */
    private function runChain(array $definition, array $input, int $tenantId): array
    {
        $slugs = array_values(array_map(static fn (array $step): string => (string) $step['tool'], (array) $definition['steps']));
        try {
            $run = $this->harness->startPinned($definition, $tenantId, $input);
            $guard = 0;
            while (in_array((string) $run['status'], ['running', 'waiting_input', 'waiting_confirm'], true) && $guard++ < 20) {
                $run = $this->harness->advancePinned((int) $run['run_id'], $tenantId);
            }
            $context = array_filter(
                (array) ($run['context'] ?? []),
                static fn (string $key): bool => ! array_key_exists($key, $input),
                ARRAY_FILTER_USE_KEY,
            );

            return [
                'outputs' => $context + ['chain_status' => (string) $run['status']],
                'slugs' => $slugs,
                'text' => (string) json_encode($context, JSON_UNESCAPED_UNICODE),
                'severe' => (string) ($run['status'] ?? '') !== 'completed',
            ];
        } catch (\Throwable) {
            return ['outputs' => [], 'slugs' => $slugs, 'text' => '', 'severe' => true];
        }
    }

    /**
     * instructions 走单次固定提示词调用：正文即被测对象，不接受样本以外输入。
     *
     * @param  array<string, mixed>  $input
     * @return array{outputs: array<string, mixed>, slugs: list<string>, text: string, severe: bool}
     */
    private function runPrompt(string $contentBody, array $input): array
    {
        try {
            $response = $this->llm->chat([
                ['role' => 'system', 'content' => $contentBody],
                ['role' => 'user', 'content' => (string) json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
            ], ['max_tokens' => (int) $this->config('evaluation.max_output_tokens', 2000)]);
        } catch (\Throwable) {
            $this->llmCalls++;

            return ['outputs' => [], 'slugs' => [], 'text' => '', 'severe' => true];
        }
        $this->llmCalls++;
        $this->orchestrationCalls++;
        $usage = $response->usage;
        $this->usage = [
            'input_tokens' => (int) ($this->usage['input_tokens'] ?? 0) + (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0),
            'output_tokens' => (int) ($this->usage['output_tokens'] ?? 0) + (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0),
        ];

        return [
            'outputs' => ['answer' => $response->content],
            'slugs' => [],
            'text' => $response->content,
            'severe' => trim($response->content) === '',
        ];
    }

    /**
     * 确定性断言：不依赖模型自评。
     *
     * @param  array<string, mixed>  $case
     * @param  array{outputs: array<string, mixed>, slugs: list<string>, text: string, severe: bool}  $observed
     * @return list<string>
     */
    private function assertCase(array $case, array $observed): array
    {
        $asserts = is_array($case['asserts'] ?? null) ? $case['asserts'] : [];
        $failures = [];

        foreach ((array) ($asserts['contains'] ?? []) as $needle) {
            if (! is_string($needle) || $needle === '' || ! str_contains($observed['text'], $needle)) {
                $failures[] = 'contains:' . (string) $needle;
            }
        }
        foreach ((array) ($asserts['not_contains'] ?? []) as $needle) {
            if (is_string($needle) && $needle !== '' && str_contains($observed['text'], $needle)) {
                $failures[] = 'not_contains:' . $needle;
            }
        }
        foreach ((array) ($asserts['equals'] ?? []) as $key => $expected) {
            $actual = $observed['outputs'][$key] ?? null;
            if ($actual !== $expected) {
                $failures[] = 'equals:' . (string) $key;
            }
        }
        foreach ((array) ($asserts['required_tools'] ?? []) as $slug) {
            if (! in_array((string) $slug, $observed['slugs'], true)) {
                $failures[] = 'required_tools:' . (string) $slug;
            }
        }
        foreach ((array) ($asserts['forbidden_tools'] ?? []) as $slug) {
            if (in_array((string) $slug, $observed['slugs'], true)) {
                $failures[] = 'forbidden_tools:' . (string) $slug;
            }
        }

        return $failures;
    }

    /**
     * 事务 B：复核正文摘要未被改写后，写入唯一由本器生产的 validation。
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, array<string, mixed>>  $results
     */
    private function commit(array $plan, array $results): bool
    {
        $totals = $this->score($plan, $results);

        return DB::transaction(function () use ($plan, $totals): bool {
            $candidate = SkillCandidate::withoutGlobalScopes()->whereKey($plan['candidate_id'])->lockForUpdate()->first();
            if ($candidate === null) {
                return false;
            }
            if (in_array((string) $candidate->status, [SkillCandidate::STATUS_ADOPTED, SkillCandidate::STATUS_REJECTED], true)) {
                return false;
            }
            if ($this->draftSha((array) $candidate->skill_draft) !== $plan['draft_sha']) {
                // 正文已被改写：旧判据失效，必须重新评测
                return false;
            }

            $candidate->validation = $totals['validation'];
            $candidate->status = $totals['validation']['passed'] === true
                ? SkillCandidate::STATUS_VALIDATED
                : SkillCandidate::STATUS_PROPOSED;
            $candidate->save();

            return true;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, array<string, mixed>>  $results
     * @return array{validation: array<string, mixed>}
     */
    private function score(array $plan, array $results): array
    {
        $total = count($results);
        $passed = 0;
        $severe = 0;
        foreach ($results as $result) {
            if ($result['passed'] === true) {
                $passed++;
            }
            if ($result['severe'] === true) {
                $severe++;
            }
        }
        $score = $total > 0 ? round($passed / $total, 4) : 0.0;

        $baselineTotal = 0;
        $baselinePassed = 0;
        foreach ($results as $result) {
            if ($result['baseline_passed'] !== null) {
                $baselineTotal++;
                $baselinePassed += $result['baseline_passed'] === true ? 1 : 0;
            }
        }
        $baselineScore = $baselineTotal > 0 ? round($baselinePassed / $baselineTotal, 4) : null;

        $comparative = $baselineScore !== null;
        $meetsBar = $total >= $plan['min_cases']
            && $severe === 0
            && $score >= 0.8
            && (! $comparative || $score >= $baselineScore);

        return ['validation' => [
            'passed' => $meetsBar,
            'holdout_cases' => $total,
            'holdout_score' => $score,
            'baseline_score' => $baselineScore,
            'severe_errors' => $severe,
            'baseline_definition_id' => $plan['baseline'] === null ? null : (int) $plan['baseline']['definition_id'],
            'evaluation' => [
                'evidence_level' => self::EVIDENCE_LEVEL,
                'draft_sha256' => $plan['draft_sha'],
                'evaluator_version' => self::EVALUATOR_VERSION,
                'dataset_id' => $plan['dataset_id'],
                'dataset_version' => $plan['dataset_version'],
                'rubric_version' => $plan['rubric_version'],
                'manifest_sha256' => $plan['manifest_sha'],
                'repeat' => $plan['repeat'],
                'llm_calls' => $this->llmCalls,
                'orchestration_calls' => $this->orchestrationCalls,
                'comparative' => $comparative,
                'failed_cases' => array_slice(
                    array_map(
                        static fn (array $r): array => ['case' => $r['case'], 'reasons' => array_slice($r['failures'], 0, 5)],
                        array_values(array_filter(
                            array_map(
                                static fn (string $k, array $r): array => ['case' => $k, 'failures' => (array) $r['failures']],
                                array_keys($results),
                                $results,
                            ),
                            static fn (array $row): bool => $row['failures'] !== [],
                        )),
                    ),
                    0,
                    20,
                ),
            ],
        ]];
    }

    /**
     * 候选正文 → 固定链定义（与发布/执行同一结构要求）。
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function chainDefinition(array $draft): array
    {
        $body = json_decode((string) $draft['content_body'], true, 64, JSON_THROW_ON_ERROR);
        if (($body['schema_version'] ?? null) !== 1 || ! is_array($body['chain'] ?? null)) {
            throw new SkillDomainException('tool_incompatible', 'Executable candidate requires a versioned JSON chain.');
        }
        $chain = $body['chain'];
        $chain['key'] = $draft['skill_key'] . '@' . $draft['version'];
        $chain['title'] = (string) $draft['title'];
        foreach ((array) ($chain['steps'] ?? []) as $step) {
            if (! is_array($step) || ($step['type'] ?? null) !== 'tool'
                || ! in_array((string) ($step['tool'] ?? ''), array_map('strval', (array) $draft['tool_deps']), true)) {
                throw new SkillDomainException('tool_incompatible', 'Chain step must use a declared tool dependency.');
            }
        }

        return $chain;
    }

    /**
     * 正文摘要：只取结构规则白名单字段并归一键序，
     * 这样「改写无关字段」不算变更、「换键序」不算变更，真改判据对象才算。
     *
     * @param  array<string, mixed>  $draft
     */
    private function draftSha(array $draft): string
    {
        return SkillDraftRules::fingerprint($draft);
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

    private function config(string $key, mixed $default): mixed
    {
        return config('ai-skill.' . $key, $default);
    }
}
