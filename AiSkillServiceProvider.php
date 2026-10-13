<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

/**
 * Module shell: configuration and migrations use the standard bootstrap.
 * Catalog/favorites use public identity and RBAC contracts; training remains unbound.
 */
class AiSkillServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'ai-skill';

    protected function registerModuleBindings(): void
    {
        // Catalog permission must not become an implicit training/execution authorization.
        $this->app->when([Services\SkillCatalog::class, Services\SkillFavoriteService::class])
            ->needs(Contracts\ActorResolverContract::class)->give(Services\OperatorActorResolver::class);
        $this->app->bind(Contracts\FavoriteAccessPolicyContract::class, Services\FavoriteAccessPolicy::class);
        $this->app->bind(Contracts\SkillFavoriteServiceContract::class, Services\SkillFavoriteService::class);
    }

    protected function registerModuleCommands(): void
    {
        $this->commands([Console\ImportSkillSeedsCommand::class]);
    }

    protected function bootModule(): void
    {
        $this->app['router']->aliasMiddleware('ai-skill.enabled', Http\Middleware\EnsureAiSkillEnabled::class);
    }
}
