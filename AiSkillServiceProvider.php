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
        $this->app->when([
            Services\SkillCatalog::class,
            Services\SkillFavoriteService::class,
            Services\SkillExecutionService::class,
            Services\SkillTrainingService::class,
            Services\SkillRecommendationService::class,
        ])->needs(Contracts\ActorResolverContract::class)->give(Services\OperatorActorResolver::class);
        $this->app->bind(Contracts\FavoriteAccessPolicyContract::class, Services\FavoriteAccessPolicy::class);
        $this->app->bind(Contracts\SkillFavoriteServiceContract::class, Services\SkillFavoriteService::class);
        $this->app->bind(Contracts\SkillExecutionServiceContract::class, Services\SkillExecutionService::class);
        $this->app->bind(Contracts\AdoptionPolicyContract::class, Services\AdoptionPolicy::class);
        $this->app->bind(Contracts\SkillTrainingServiceContract::class, Services\SkillTrainingService::class);
        $this->app->bind(Contracts\SkillRecommendationServiceContract::class, Services\SkillRecommendationService::class);
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
