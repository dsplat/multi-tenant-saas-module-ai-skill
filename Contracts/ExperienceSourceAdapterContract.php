<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Contracts;

use MultiTenantSaas\Modules\AiSkill\DTOs\AuthorizedDatasetSpec;
use MultiTenantSaas\Modules\AiSkill\DTOs\EvidenceBatch;
use MultiTenantSaas\Modules\AiSkill\Exceptions\SkillDomainException;

/**
 * 经验采样适配器（A 包冻结接口）。
 *
 * 仅通过授权身份/字段/时间范围获取数据；工具日志经会话关联隔离；
 * 无可靠关联保持 unknown/低可信，不根据 auth id 或时间邻近猜测主体和任务。
 * 实现可读 Logging/Ai/项目源表，但不得搬表、不得给 LLM 任意 SQL。
 */
interface ExperienceSourceAdapterContract
{
    /**
     * @throws SkillDomainException
     */
    public function sample(AuthorizedDatasetSpec $spec): EvidenceBatch;
}
