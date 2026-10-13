<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\DTOs;

/**
 * 统一上下文预算合同（BL-148 / 设计 §13.3）。
 *
 * explore / reuse / mixed / 秘书 / headless / task-chain 共用；路径差异只在谁决定下一步。
 * 预算按所选模型适配器估算并记录误差；未知窗口拒绝无界输入，不能只以字符数宣称精确 token。
 */
final class ContextPlan
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param  list<string>  $trustedSources  允许进入上下文的来源类型（system/policy/tool_schema/memory/…）
     * @param  array<string, int>  $budgetTokens  分项预算（system/tool_schema/input/history/memory/tool_results/delegate/reserved_output）
     */
    public function __construct(
        public readonly string $modelRef,
        public readonly ?int $contextWindowTokens,
        public readonly int $reservedOutputTokens,
        /** @var array<string, int> */
        public readonly array $budgetTokens = [],
        /** @var list<string> */
        public readonly array $trustedSources = [],
        /** truncate | summarize | ref_only —— 截断后的缺失值不得当作业务事实 */
        public readonly string $truncationPolicy = 'ref_only',
        /** delegate 最大深度 / 总次数 / 总 token；null 表示未启用委派 */
        public readonly ?int $delegateMaxDepth = null,
        public readonly ?int $delegateMaxCalls = null,
        public readonly ?int $delegateTokenBudget = null,
        /** 估算误差（绝对 token）；null=未知，不得宣称精确 */
        public readonly ?int $estimateErrorTokens = null,
        public readonly int $schemaVersion = self::SCHEMA_VERSION,
    ) {}

    /**
     * 长会话/多 Skill 共享总预算时，禁止每个子代理各拿一份无限额度。
     */
    public function shareWithSubAgent(int $requestedTokens): int
    {
        $remaining = $this->budgetTokens['shared_remaining'] ?? null;
        if ($remaining === null) {
            return max(0, $requestedTokens);
        }

        return max(0, min($requestedTokens, $remaining));
    }
}
