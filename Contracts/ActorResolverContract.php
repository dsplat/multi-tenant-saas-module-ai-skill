<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Contracts;

/**
 * 服务端 actor/tenant 授权解析器。Skill 执行不得接受任意 actor 入参。
 * resolve 必须核验身份有效性、目标租户成员或平台资格以及当前用途权限；
 * tenant_id 是本次获准的单个租户。未经授权应抛领域异常，不返回客户端指定身份。
 */
interface ActorResolverContract
{
    /**
     * @return array{actor_type: string, actor_id: int|null, tenant_id: int}
     */
    public function resolve(): array;
}
