<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Contracts;

use MultiTenantSaas\Modules\AiSkill\DTOs\RecommendationList;
use MultiTenantSaas\Modules\AiSkill\Exceptions\SkillDomainException;

/**
 * 推荐服务（A 包冻结接口）。推荐结合业务状态；只是建议，不授予执行权。
 */
interface SkillRecommendationServiceContract
{
    /**
     * @param  array<string, mixed>  $authorizedContext  服务端已解析的 tenant/actor/业务上下文
     *
     * @throws SkillDomainException
     */
    public function recommend(array $authorizedContext): RecommendationList;
}
