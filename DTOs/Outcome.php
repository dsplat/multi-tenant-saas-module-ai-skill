<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\DTOs;

/**
 * 业务结果五态。与工具/HTTP 输出状态分开：status 成功 ≠ 业务目标达成。
 */
final class Outcome
{
    public const SUCCESS = 'success';

    public const INCOMPLETE = 'incomplete';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const UNCERTAIN = 'uncertain';

    public static function isValid(string $outcome): bool
    {
        return in_array($outcome, [
            self::SUCCESS,
            self::INCOMPLETE,
            self::FAILED,
            self::CANCELLED,
            self::UNCERTAIN,
        ], true);
    }
}
