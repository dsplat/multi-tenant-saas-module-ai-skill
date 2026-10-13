<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Services;

use Illuminate\Database\Eloquent\Builder;
use MultiTenantSaas\Modules\AiSkill\Contracts\ActorResolverContract;
use MultiTenantSaas\Modules\AiSkill\Contracts\FavoriteAccessPolicyContract;
use MultiTenantSaas\Modules\AiSkill\Contracts\SkillFavoriteServiceContract;
use MultiTenantSaas\Modules\AiSkill\Exceptions\SkillDomainException;
use MultiTenantSaas\Modules\AiSkill\Models\SkillFavorite;

final class SkillFavoriteService implements SkillFavoriteServiceContract
{
    public function __construct(
        private readonly ActorResolverContract $actors,
        private readonly SkillCatalog $catalog,
        private readonly FavoriteAccessPolicyContract $policy,
    ) {}

    public function save(array $reference, array $allowedDefaults, string $scope): array
    {
        $actor = $this->actor();
        if (! in_array($scope, ['personal', 'tenant'], true)) {
            throw new SkillDomainException('scope_denied');
        }
        if ($scope === 'tenant') {
            $this->policy->authorizeTenantWrite($actor);
        }
        $definition = $this->catalog->resolve((string) ($reference['skill_key'] ?? ''), (string) ($reference['version'] ?? ''));
        $defaults = $this->policy->validateDefaults($definition, $allowedDefaults);

        return SkillFavorite::withoutGlobalScopes()->updateOrCreate([
            'tenant_id' => $actor['tenant_id'], 'operator_id' => $scope === 'personal' ? $actor['actor_id'] : 0,
            'scope' => $scope, 'skill_key' => $definition->skill_key, 'version' => $definition->version,
        ], ['allowed_defaults' => $defaults])->toArray();
    }

    public function list(): array
    {
        return $this->visible($this->actor())->orderByDesc('updated_at')
            ->orderByDesc((new SkillFavorite)->getKeyName())->get()->toArray();
    }

    public function remove(array $reference): void
    {
        $actor = $this->actor();
        $query = $this->visible($actor);
        if (isset($reference['skill_favorite_id'])) {
            $query->whereKey($reference['skill_favorite_id']);
        } elseif (isset($reference['skill_key'], $reference['version'])) {
            $query->where('skill_key', $reference['skill_key'])->where('version', $reference['version']);
        } else {
            throw new SkillDomainException('scope_denied', 'An exact favorite reference is required.');
        }
        // Authorization of the whole set precedes deletion, avoiding partial removal on rejection.
        $query->getModel()->getConnection()->transaction(function () use ($query, $actor): void {
            $favorites = $query->lockForUpdate()->get();
            if ($favorites->contains(fn (SkillFavorite $favorite): bool => $favorite->isTenantShared())) {
                $this->policy->authorizeTenantWrite($actor);
            }
            foreach ($favorites as $favorite) {
                $favorite->delete();
            }
        });
    }

    private function actor(): array
    {
        $actor = $this->actors->resolve();
        if (($actor['actor_type'] ?? null) !== 'operator' || (int) ($actor['actor_id'] ?? 0) <= 0
            || (int) ($actor['tenant_id'] ?? 0) <= 0) {
            throw new SkillDomainException('scope_denied');
        }

        return $actor;
    }

    private function visible(array $actor): Builder
    {
        return SkillFavorite::withoutGlobalScopes()->where('tenant_id', $actor['tenant_id'])
            ->where(function (Builder $query) use ($actor): void {
                $query->where(function (Builder $personal) use ($actor): void {
                    $personal->where('scope', 'personal')->where('operator_id', $actor['actor_id']);
                })->orWhere(function (Builder $shared): void {
                    $shared->where('scope', 'tenant')->where('operator_id', 0);
                });
            });
    }
}
