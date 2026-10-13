<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 训练候选。模型输出不能直接执行；采用绑定 SkillDefinition 版本。
 */
class SkillCandidate extends Model
{
    use BelongsToTenant, HasGlobalId;

    public const STATUS_PROPOSED = 'proposed';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_ADOPTED = 'adopted';

    public const STATUS_REJECTED = 'rejected';

    protected $table = 'ai_skill_candidates';

    protected $primaryKey = 'candidate_id';

    protected $fillable = [
        'training_run_id',
        'tenant_id',
        'candidate_key',
        'skill_draft',
        'evidence_refs',
        'validation',
        'status',
        'adoption_policy_result',
        'bound_definition_id',
    ];

    protected function casts(): array
    {
        return [
            'skill_draft' => 'array',
            'evidence_refs' => 'array',
            'validation' => 'array',
            'adoption_policy_result' => 'array',
            'training_run_id' => 'integer',
            'bound_definition_id' => 'integer',
        ];
    }

    public function isAdoptable(): bool
    {
        return $this->status === self::STATUS_VALIDATED;
    }
}
