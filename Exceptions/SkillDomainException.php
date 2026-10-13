<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Exceptions;

use MultiTenantSaas\Modules\AiSkill\DTOs\ReasonCode;

/**
 * Skill 域异常。reason 必须取 ReasonCode 冻结枚举；
 * 不向用户暴露 SQL/路径。HTTP 映射由集成者按 401/403/409/422 既有领域异常约定接线。
 */
class SkillDomainException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message = '',
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        if (! ReasonCode::isValid($reason)) {
            throw new \InvalidArgumentException("Unknown reason code: {$reason}");
        }
        parent::__construct($message !== '' ? $message : $reason, 0, $previous);
    }
}
