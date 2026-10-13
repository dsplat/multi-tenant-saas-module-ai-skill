<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\DTOs;

/**
 * 评测清单（设计 §13.4）。判定独立于训练/采用流程：冻结样本与评分规则由独立入口持有。
 *
 * 训练只消费 discovery 集；采用门使用保留 validation 集；测试预期不可由候选生成过程修改。
 * 不要求单独部署模块。
 */
final class EvaluationManifest
{
    public const SCHEMA_VERSION = 1;

    public const SPLIT_DISCOVERY = 'discovery';

    public const SPLIT_VALIDATION = 'validation';

    /**
     * @param  list<string>  $sampleRefs
     * @param  array<string, mixed>  $coverage
     * @param  array<string, string>  $versionPins  model/skill/tool/policy/rubric/evaluator 版本钉扎
     */
    public function __construct(
        public readonly string $datasetId,
        public readonly string $datasetVersion,
        public readonly string $split,
        public readonly array $sampleRefs = [],
        public readonly array $coverage = [],
        public readonly ?string $expectedOutcomeRef = null,
        public readonly ?string $rubricVersion = null,
        public readonly ?string $evaluatorVersion = null,
        public readonly array $versionPins = [],
        public readonly ?string $contextSnapshotRef = null,
        public readonly int $repeatCount = 1,
        /** @var array{max_cases?: int, max_calls?: int, max_tokens?: int, max_cost_micro?: int}|null */
        public readonly ?array $budget = null,
        /** 缺失标 unknown，不按 0 计算 */
        public readonly ?string $costSource = null,
        public readonly int $schemaVersion = self::SCHEMA_VERSION,
    ) {}

    public function isValidationSplit(): bool
    {
        return $this->split === self::SPLIT_VALIDATION;
    }

    public function isDiscoverySplit(): bool
    {
        return $this->split === self::SPLIT_DISCOVERY;
    }
}
