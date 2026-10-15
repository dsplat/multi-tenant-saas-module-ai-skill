<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Services;

use MultiTenantSaas\Modules\AiSkill\Contracts\ActorResolverContract;

/**
 * 后台任务用的可信 actor。
 *
 * 身份只来自训练任务入库时已授权的 tenant_id/actor_id 事实，不读请求上下文、
 * 不接受任意 actor 入参；采样授权门因此与 Web 路径同一条判据。
 */
final class TrustedTrainingActor implements ActorResolverContract
{
    public function __construct(
        private readonly int $tenantId,
        private readonly int $operatorId,
    ) {}

    /**
     * @return array{actor_type: string, actor_id: int|null, tenant_id: int}
     */
    public function resolve(): array
    {
        return [
            'actor_type' => 'operator',
            'actor_id' => $this->operatorId > 0 ? $this->operatorId : null,
            'tenant_id' => $this->tenantId,
        ];
    }
}
