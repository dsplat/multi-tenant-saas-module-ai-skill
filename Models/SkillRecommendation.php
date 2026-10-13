<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 推荐记录。曝光/采用/忽略/执行完成分列；点击 ≠ 成功。
 */
class SkillRecommendation extends Model
{
    use BelongsToTenant, HasGlobalId;

    protected $table = 'ai_skill_recommendations';

    protected $primaryKey = 'recommendation_id';

    protected $fillable = [
        'tenant_id',
        'visible_actor_type',
        'visible_actor_id',
        'context_ref',
        'skill_key',
        'version',
        'candidate_id',
        'position',
        'exposed_at',
        'adopted_at',
        'ignored_at',
        'executed_at',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'context_ref' => 'array',
            'reason' => 'array',
            'exposed_at' => 'datetime',
            'adopted_at' => 'datetime',
            'ignored_at' => 'datetime',
            'executed_at' => 'datetime',
            'visible_actor_id' => 'integer',
            'candidate_id' => 'integer',
            'position' => 'integer',
        ];
    }

    public function wasAdopted(): bool
    {
        return $this->adopted_at !== null;
    }

    public function wasExecuted(): bool
    {
        return $this->executed_at !== null;
    }
}
