<?php

namespace MultiTenantSaas\Modules\AiSkill\Services;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Contracts\OperatorContextContract;
use MultiTenantSaas\Contracts\PermissionCheckerContract;
use MultiTenantSaas\Modules\AiSkill\Exceptions\SkillDomainException;
use MultiTenantSaas\Modules\AiSkill\Models\SkillDefinition;

/** Tenant lifecycle management; publication and content changes belong to adoption. */
final class SkillDefinitionManagement
{
    public function __construct(private readonly OperatorContextContract $operators, private readonly PermissionCheckerContract $permissions) {}

    public function changeStatus(int $definitionId, string $expectedStatus, string $status): array
    {
        $actor = $this->operators->resolve();
        if (($actor['actor_type'] ?? null) !== 'operator' || (int) ($actor['tenant_id'] ?? 0) <= 0
            || ! $this->permissions->check('ai.capability.view') || ! $this->permissions->check('setting.update')) {
            throw new SkillDomainException('scope_denied');
        }
        $allowed = [SkillDefinition::STATUS_PUBLISHED, SkillDefinition::STATUS_DISABLED];
        if (! in_array($expectedStatus, $allowed, true) || ! in_array($status, $allowed, true)) {
            throw new SkillDomainException('review_required');
        }

        return DB::transaction(function () use ($definitionId, $expectedStatus, $status, $actor): array {
            $definition = SkillDefinition::query()->whereKey($definitionId)
                ->where('scope_type', SkillDefinition::SCOPE_TENANT)->where('scope_id', $actor['tenant_id'])
                ->lockForUpdate()->first();
            if (! $definition) {
                throw new SkillDomainException('scope_denied');
            }
            if ($definition->status !== $expectedStatus) {
                throw new SkillDomainException('version_conflict');
            }
            if ($status === SkillDefinition::STATUS_PUBLISHED) {
                $definition->snapshot();
            }
            $definition->status = $status;
            $definition->save();

            return $definition->toArray();
        }, 3);
    }
}
