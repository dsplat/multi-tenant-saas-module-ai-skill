<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use MultiTenantSaas\Modules\AiSkill\Exceptions\SkillDomainException;
use MultiTenantSaas\Modules\AiSkill\Models\SkillDefinition;

/** Published semantics are protected for model and bulk Eloquent writes. */
class SkillDefinitionBuilder extends Builder
{
    public function update(array $values)
    {
        return $this->getModel()->getConnection()->transaction(function () use ($values) {
            $model = $this->getModel();
            $rows = (clone $this)->toBase()->lockForUpdate()->get([
                $model->qualifyColumn($model->getKeyName()),
                $model->qualifyColumn('status'),
            ]);
            foreach ($rows as $row) {
                if (in_array($row->status, [SkillDefinition::STATUS_PUBLISHED, SkillDefinition::STATUS_DISABLED], true)
                    && (array_intersect(array_keys($values), SkillDefinition::SEMANTIC_FIELDS) !== []
                        || (isset($values['status']) && ! in_array($values['status'], [SkillDefinition::STATUS_PUBLISHED, SkillDefinition::STATUS_DISABLED], true)))) {
                    throw new SkillDomainException('version_conflict', 'Published Skill semantics are immutable.');
                }
            }

            return parent::update($values);
        });
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        throw new SkillDomainException('version_conflict', 'Skill versions do not support arithmetic updates.');
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        throw new SkillDomainException('version_conflict', 'Skill versions do not support arithmetic updates.');
    }
}
