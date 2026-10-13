<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

/**
 * Module shell: configuration and migrations use the standard bootstrap.
 * Runtime services remain unbound until their authorization contracts pass review.
 */
class AiSkillServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'ai-skill';
}
