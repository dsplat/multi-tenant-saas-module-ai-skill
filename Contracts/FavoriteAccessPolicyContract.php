<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Contracts;

use MultiTenantSaas\Modules\AiSkill\Models\SkillDefinition;

/** Trusted authorization and safe-default validation; never supplied by the client. */
interface FavoriteAccessPolicyContract
{
    /** Throw scope_denied if this operator cannot modify shared tenant favorites. */
    public function authorizeTenantWrite(array $actor): void;

    /** Return validated, explicitly permitted defaults; reject dynamic IDs and secrets. */
    public function validateDefaults(SkillDefinition $definition, array $defaults): array;
}
