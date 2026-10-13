<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Modules\AiSkill\Exceptions\SkillDomainException;
use MultiTenantSaas\Modules\AiSkill\Models\Builders\SkillDefinitionBuilder;

/**
 * Skill 定义一版（skill_key+version+scope_type+scope_id 唯一）。
 *
 * 发布版本不可原地改；execution 为 chain|instructions|mixed。
 * 模块/project 种子随代码交付，tenant 版本可持久化。
 * 本表无 tenant_id（scope 维度隔离），故不挂 BelongsToTenant。
 */
class SkillDefinition extends Model
{
    use HasGlobalId;

    public const SCOPE_MODULE = 'module';

    public const SCOPE_PROJECT = 'project';

    public const SCOPE_TENANT = 'tenant';

    public const EXECUTION_CHAIN = 'chain';

    public const EXECUTION_INSTRUCTIONS = 'instructions';

    public const EXECUTION_MIXED = 'mixed';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_DISABLED = 'disabled';

    public const SEMANTIC_FIELDS = [
        'skill_key', 'version', 'scope_type', 'scope_id', 'owner_type', 'owner_id',
        'execution', 'title', 'description', 'input_schema', 'applicability',
        'tool_deps', 'risk', 'success_criteria', 'content_ref',
    ];

    protected $attributes = ['scope_id' => 0, 'status' => self::STATUS_DRAFT];

    protected static function booted(): void
    {
        static::saving(function (self $skill): void {
            if (! in_array($skill->scope_type, [self::SCOPE_MODULE, self::SCOPE_PROJECT, self::SCOPE_TENANT], true)
                || ($skill->scope_type === self::SCOPE_TENANT && (int) $skill->scope_id <= 0)
                || ($skill->scope_type !== self::SCOPE_TENANT && $skill->scope_id !== 0)
                || ! in_array($skill->execution, [self::EXECUTION_CHAIN, self::EXECUTION_INSTRUCTIONS, self::EXECUTION_MIXED], true)
                || ! in_array($skill->status, [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_DISABLED], true)) {
                throw new SkillDomainException('scope_denied', 'Invalid Skill scope or execution definition.');
            }
            if ($skill->exists && in_array($skill->getRawOriginal('status'), [self::STATUS_PUBLISHED, self::STATUS_DISABLED], true)
                && ($skill->isDirty(self::SEMANTIC_FIELDS) || $skill->status === self::STATUS_DRAFT)) {
                throw new SkillDomainException('version_conflict', 'Published Skill semantics are immutable.');
            }
        });
    }

    public function newEloquentBuilder($query): SkillDefinitionBuilder
    {
        return new SkillDefinitionBuilder($query);
    }

    protected $table = 'ai_skill_definitions';

    protected $primaryKey = 'skill_definition_id';

    protected $fillable = [
        'skill_key',
        'version',
        'scope_type',
        'scope_id',
        'owner_type',
        'owner_id',
        'execution',
        'title',
        'description',
        'input_schema',
        'applicability',
        'tool_deps',
        'risk',
        'success_criteria',
        'content_ref',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'input_schema' => 'array',
            'applicability' => 'array',
            'tool_deps' => 'array',
            'risk' => 'array',
            'success_criteria' => 'array',
            'scope_id' => 'integer',
            'owner_id' => 'integer',
        ];
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * 已发布版本禁止原地改语义字段（应用层硬门；库层由集成者酌情加触发器）。
     */
    public function isImmutableSemantic(): bool
    {
        return in_array($this->status, [self::STATUS_PUBLISHED, self::STATUS_DISABLED], true);
    }
}
