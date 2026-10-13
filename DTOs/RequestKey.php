<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\DTOs;

/**
 * 幂等请求键。关键写操作（start/enqueue/save）必须携带；同键同输入重放，同键异输入 409。
 */
final class RequestKey
{
    public function __construct(
        public readonly string $key,
        /** 输入指纹（递归键排序 + 稳定 JSON + sha256），与能力计费指纹同口径 */
        public readonly string $inputFingerprint,
    ) {}
}
