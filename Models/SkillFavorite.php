<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Modules\AiSkill\Exceptions\SkillDomainException;

/**
 * 收藏引用（tenant+operator+skill_key+version 唯一）。
 *
 * 只存被授权的默认参数，不复制实现、不保存授权令牌。
 */
class SkillFavorite extends Model
{
    use BelongsToTenant, HasGlobalId;

    public const SCOPE_PERSONAL = 'personal';

    public const SCOPE_TENANT = 'tenant';

    protected static function booted(): void
    {
        static::saving(function (self $favorite): void {
            if (($favorite->scope === self::SCOPE_PERSONAL && (int) $favorite->operator_id <= 0)
                || ($favorite->scope === self::SCOPE_TENANT && $favorite->operator_id !== 0)
                || ! in_array($favorite->scope, [self::SCOPE_PERSONAL, self::SCOPE_TENANT], true)) {
                throw new SkillDomainException('scope_denied', 'Invalid favorite ownership.');
            }
        });
    }

    protected $table = 'ai_skill_favorites';

    protected $primaryKey = 'skill_favorite_id';

    protected $fillable = [
        'tenant_id',
        'operator_id',
        'scope',
        'skill_key',
        'version',
        'allowed_defaults',
        'label',
    ];

    protected function casts(): array
    {
        return [
            'allowed_defaults' => 'array',
            'operator_id' => 'integer',
        ];
    }

    public function isTenantShared(): bool
    {
        return $this->scope === self::SCOPE_TENANT;
    }
}
