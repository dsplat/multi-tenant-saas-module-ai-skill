<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\DTOs;

/**
 * 采用策略裁决。低风险可 auto；实质行为变化 needs_review；不满足条件 denied。
 * 「人工采用」不代替技术校验，「通过验证」不自动授予执行权限。
 */
final class AdoptionDecision
{
    public const AUTO = 'auto';

    public const NEEDS_REVIEW = 'needs_review';

    public const DENIED = 'denied';

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public readonly string $decision,
        public readonly string $reason,
        public readonly array $evidence = [],
        /** 只读等价变体自动采用时的限定租户集合；空=不限定（仅当 policy 明确允许） */
        public readonly array $limitedTenantIds = [],
    ) {}

    public function isAuto(): bool
    {
        return $this->decision === self::AUTO;
    }

    public function isDenied(): bool
    {
        return $this->decision === self::DENIED;
    }
}
