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
 * 所有作用域持久化完整正文；模块/project 文件仅为部署期导入来源。
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
        'tool_deps', 'risk', 'success_criteria', 'content_ref', 'content_body', 'content_sha256',
    ];

    protected $attributes = ['scope_id' => 0, 'status' => self::STATUS_DRAFT];

    protected static function booted(): void
    {
        static::saving(function (self $skill): void {
            if ($skill->content_body !== null) {
                if (! is_string($skill->content_body) || $skill->content_body === '' || strlen($skill->content_body) > 262144
                    || ! mb_check_encoding($skill->content_body, 'UTF-8')) {
                    throw new SkillDomainException('data_incomplete', 'Invalid Skill content snapshot.');
                }
                $digest = hash('sha256', $skill->content_body);
                if ($skill->content_sha256 === null) {
                    $skill->content_sha256 = $digest;
                } elseif (! hash_equals($digest, $skill->content_sha256)) {
                    throw new SkillDomainException('verification_missing', 'Skill snapshot digest mismatch.');
                }
            } elseif ($skill->content_sha256 !== null) {
                throw new SkillDomainException('verification_missing', 'Skill snapshot content is missing.');
            }
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

    protected $hidden = ['content_body'];

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
        'content_body',
        'content_sha256',
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

    /** Content stays local to the DB row; never resolve content_ref as a filesystem path. */
    public function snapshot(): string
    {
        if (! is_string($this->content_body) || $this->content_body === '' || ! is_string($this->content_sha256)
            || ! hash_equals(hash('sha256', $this->content_body), $this->content_sha256)) {
            throw new SkillDomainException('verification_missing', 'Immutable Skill snapshot is missing or corrupt.');
        }

        return $this->content_body;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * 主键以字符串序列化：HasGlobalId 为 16 位随机数字，可能超出 JS Number.MAX_SAFE_INTEGER，
     * 前端一律作不透明引用，禁止 Number 强转（见 ai-skill-backoffice-playbook §4.1）。
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        $key = $this->getKeyName();
        if (array_key_exists($key, $array) && $array[$key] !== null) {
            $array[$key] = (string) $array[$key];
        }

        return $array;
    }

    /**
     * 已发布版本禁止原地改语义字段（应用层硬门；库层由集成者酌情加触发器）。
     */
    public function isImmutableSemantic(): bool
    {
        return in_array($this->status, [self::STATUS_PUBLISHED, self::STATUS_DISABLED], true);
    }
}
