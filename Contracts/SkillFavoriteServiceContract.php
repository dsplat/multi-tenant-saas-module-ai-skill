<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Contracts;

use MultiTenantSaas\Modules\AiSkill\Exceptions\SkillDomainException;

/**
 * 收藏服务（A 包冻结接口）。
 *
 * 引用型收藏：不复制实现、不保存授权令牌；动态对象 ID 与敏感参数每次校验。
 * 租户共享另有 scope 与管理权限；用户修改不得污染 tenant 默认。
 */
interface SkillFavoriteServiceContract
{
    /**
     * @param  array{skill_key: string, version: string, definition_ref?: string}  $reference
     * @param  array<string, mixed>  $allowedDefaults
     * @param  string  $scope  personal|tenant
     * @return array<string, mixed>
     *
     * @throws SkillDomainException
     */
    public function save(array $reference, array $allowedDefaults, string $scope): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array;

    /**
     * @param  array{skill_favorite_id?: int, skill_key?: string, version?: string}  $reference
     */
    public function remove(array $reference): void;
}
