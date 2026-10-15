<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Modules\AiSkill\DTOs\ReasonCode;

/**
 * Skill 域异常。reason 必须取 ReasonCode 冻结枚举；
 * 不向用户暴露 SQL/路径。HTTP 映射由集成者按 401/403/409/422 既有领域异常约定接线。
 */
class SkillDomainException extends DomainException
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
        $this->statusCode = match ($reason) {
            ReasonCode::SCOPE_DENIED => 403,
            ReasonCode::VERSION_CONFLICT => 409,
            ReasonCode::BUDGET_EXCEEDED => 402,
            default => 422,
        };
        parent::__construct($message !== '' ? $message : $reason, 0, $previous);
    }

    /**
     * 稳定错误契约：`{success:false,message,reason}`。
     * reason 必达（A 包冻结枚举），供前端适配器映射；不泄漏 SQL/路径/堆栈。
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage() !== '' ? $this->getMessage() : $this->reason,
            'reason' => $this->reason,
        ], $this->getStatusCode());
    }
}
