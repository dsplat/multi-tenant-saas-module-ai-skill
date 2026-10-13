<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Contracts;

/**
 * 服务端 actor/tenant 解析器。Skill 执行不得接受任意 actor 入参。
 */
interface ActorResolverContract
{
    /**
     * @return array{actor_type: string, actor_id: int|null, tenant_id: int}
     */
    public function resolve(): array;
}
