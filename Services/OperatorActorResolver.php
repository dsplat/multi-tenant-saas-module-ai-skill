<?php

namespace MultiTenantSaas\Modules\AiSkill\Services;

use MultiTenantSaas\Contracts\OperatorContextContract;
use MultiTenantSaas\Contracts\PermissionCheckerContract;
use MultiTenantSaas\Modules\AiSkill\Contracts\ActorResolverContract;
use MultiTenantSaas\Modules\AiSkill\Exceptions\SkillDomainException;

final class OperatorActorResolver implements ActorResolverContract
{
    public function __construct(private readonly OperatorContextContract $operators, private readonly PermissionCheckerContract $permissions) {}

    public function resolve(): array
    {
        $actor = $this->operators->resolve();
        if (! $this->permissions->check('ai.capability.view')) {
            throw new SkillDomainException('scope_denied');
        }

        return $actor;
    }
}
