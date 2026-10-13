<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Services;

use Illuminate\Database\Eloquent\Builder;
use MultiTenantSaas\Modules\AiSkill\Contracts\ActorResolverContract;
use MultiTenantSaas\Modules\AiSkill\Exceptions\SkillDomainException;
use MultiTenantSaas\Modules\AiSkill\Models\SkillDefinition;

/** Published catalog scoped by trusted operator identity. */
final class SkillCatalog
{
    public function __construct(private readonly ActorResolverContract $actors) {}

    public function list(int $limit = 50): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Catalog limit must be between 1 and 100.');
        }

        return $this->visible()->orderBy('skill_key')->orderBy('version')
            ->orderBy((new SkillDefinition)->getKeyName())->limit($limit)->get()->toArray();
    }

    public function resolve(string $skillKey, string $version): SkillDefinition
    {
        $matches = $this->visible()->where('skill_key', $skillKey)->where('version', $version)->limit(2)->get();
        if ($matches->count() !== 1) {
            throw new SkillDomainException($matches->isEmpty() ? 'scope_denied' : 'version_conflict',
                'Skill reference must identify exactly one visible published version.');
        }

        return $matches->first();
    }

    private function visible(): Builder
    {
        $actor = $this->actors->resolve();
        if (($actor['actor_type'] ?? null) !== 'operator' || (int) ($actor['actor_id'] ?? 0) <= 0
            || (int) ($actor['tenant_id'] ?? 0) <= 0) {
            throw new SkillDomainException('scope_denied', 'A trusted tenant operator is required.');
        }
        $tenantId = (int) $actor['tenant_id'];

        return SkillDefinition::query()->where('status', SkillDefinition::STATUS_PUBLISHED)
            ->where(function (Builder $query) use ($tenantId): void {
                $query->where(function (Builder $global): void {
                    $global->whereIn('scope_type', [SkillDefinition::SCOPE_MODULE, SkillDefinition::SCOPE_PROJECT])
                        ->where('scope_id', 0);
                })->orWhere(function (Builder $tenant) use ($tenantId): void {
                    $tenant->where('scope_type', SkillDefinition::SCOPE_TENANT)->where('scope_id', $tenantId);
                });
            });
    }
}
