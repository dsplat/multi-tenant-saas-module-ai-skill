<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Contracts;

use MultiTenantSaas\Modules\AiSkill\DTOs\RequestKey;
use MultiTenantSaas\Modules\AiSkill\DTOs\SkillRunView;
use MultiTenantSaas\Modules\AiSkill\DTOs\SkillSelection;
use MultiTenantSaas\Modules\AiSkill\DTOs\ValidatedInput;
use MultiTenantSaas\Modules\AiSkill\Exceptions\SkillDomainException;

/**
 * Skill 执行服务（A 包冻结接口）。
 *
 * actor/tenant 来自注入 resolver，不接受任意 actor 入参。
 * 关键状态操作锁 run、校验 revision、执行证据绑定与幂等；运行定义在 start 完整固定。
 * 实现复用 Ai 的 TaskChainRunner/确认/用量，不复制执行器、令牌或结算体系。
 */
interface SkillExecutionServiceContract
{
    /**
     * 启动一次 Skill run，并固定 skill 版本与完整执行定义（或受控不可变引用）。
     *
     * @throws SkillDomainException
     */
    public function start(SkillSelection $selection, ValidatedInput $input, RequestKey $requestKey): SkillRunView;

    /**
     * 推进当前步。expectedRevision 不匹配 → version_conflict（409）。
     *
     * @param  array<string, mixed>  $stepInput
     *
     * @throws SkillDomainException
     */
    public function advance(int $runId, int $expectedRevision, array $stepInput = []): SkillRunView;

    /**
     * 取消 run。已发生副作用不回滚；结果不明保留原 run，禁止重放副作用。
     *
     * @throws SkillDomainException
     */
    public function cancel(int $runId, int $expectedRevision): SkillRunView;
}
