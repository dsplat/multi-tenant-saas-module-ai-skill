<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\DTOs;

/**
 * 域 Reason 码（A 包冻结）。
 *
 * 与 HTTP 状态映射：401/403/409/422 沿用既有领域异常；不向用户暴露 SQL/路径。
 */
final class ReasonCode
{
    public const COHORT_UNRESOLVED = 'cohort_unresolved';

    public const SCOPE_DENIED = 'scope_denied';

    public const DATA_INCOMPLETE = 'data_incomplete';

    public const SKILL_NOT_APPLICABLE = 'skill_not_applicable';

    public const VERSION_CONFLICT = 'version_conflict';

    public const TOOL_INCOMPATIBLE = 'tool_incompatible';

    public const BUDGET_EXCEEDED = 'budget_exceeded';

    public const VERIFICATION_MISSING = 'verification_missing';

    public const REVIEW_REQUIRED = 'review_required';

    public const INSUFFICIENT_EVIDENCE = 'insufficient_evidence';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::COHORT_UNRESOLVED,
            self::SCOPE_DENIED,
            self::DATA_INCOMPLETE,
            self::SKILL_NOT_APPLICABLE,
            self::VERSION_CONFLICT,
            self::TOOL_INCOMPATIBLE,
            self::BUDGET_EXCEEDED,
            self::VERIFICATION_MISSING,
            self::REVIEW_REQUIRED,
            self::INSUFFICIENT_EVIDENCE,
        ];
    }

    public static function isValid(string $reason): bool
    {
        return in_array($reason, self::all(), true);
    }
}
