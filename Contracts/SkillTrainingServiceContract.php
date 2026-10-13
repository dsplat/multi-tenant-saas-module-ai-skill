<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Contracts;

use MultiTenantSaas\Modules\AiSkill\DTOs\RequestKey;
use MultiTenantSaas\Modules\AiSkill\Exceptions\SkillDomainException;

/**
 * 训练任务服务（A 包冻结接口）。
 *
 * 只调用既有受控模型服务；不开放直接 SQL/任意工具执行给分析模型。
 * 模型训练完成不等于候选可采用；取消不重复执行已发请求，结果不明保留对账状态。
 */
interface SkillTrainingServiceContract
{
    /**
     * @param  array<string, mixed>  $trainingSpec  含 dataset_manifest / config / prompt 指纹 / 预算上限
     * @return array{training_run_id: int, status: string}
     *
     * @throws SkillDomainException
     */
    public function enqueue(array $trainingSpec, RequestKey $requestKey): array;

    /**
     * @return array{training_run_id: int, status: string}
     *
     * @throws SkillDomainException
     */
    public function cancel(int $trainingRunId): array;
}
