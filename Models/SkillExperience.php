<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Modules\AiSkill\DTOs\Outcome;

/**
 * 规范化经验引用。evidence_refs 指向源表，不存整份日志。
 * actor 不可靠时 actor_type=unknown，禁止补猜。
 */
class SkillExperience extends Model
{
    use BelongsToTenant, HasGlobalId;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_AGENT = 'agent';

    public const SOURCE_SKILL = 'skill';

    public const TRUST_HIGH = 'high';

    public const TRUST_LOW = 'low';

    public const TRUST_UNKNOWN = 'unknown';

    protected $table = 'ai_skill_experiences';

    protected $primaryKey = 'experience_id';

    protected $fillable = [
        'tenant_id',
        'actor_type',
        'actor_id',
        'task_ref',
        'run_ref',
        'source',
        'goal',
        'input_refs',
        'step_param_source',
        'result_criteria',
        'outcome',
        'tool_ref',
        'skill_key',
        'skill_version',
        'occurred_at',
        'cost_refs',
        'evidence_refs',
        'trace_id',
        'span_id',
        'parent_span_id',
        'attributes',
        'trust',
    ];

    protected function casts(): array
    {
        return [
            'input_refs' => 'array',
            'step_param_source' => 'array',
            'result_criteria' => 'array',
            'cost_refs' => 'array',
            'evidence_refs' => 'array',
            'attributes' => 'array',
            'occurred_at' => 'datetime',
            'actor_id' => 'integer',
        ];
    }

    public function isOutcomeSuccess(): bool
    {
        return $this->outcome === Outcome::SUCCESS;
    }

    public function isReliable(): bool
    {
        return $this->trust === self::TRUST_HIGH;
    }
}
