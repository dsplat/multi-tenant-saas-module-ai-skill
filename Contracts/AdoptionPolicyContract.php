<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Contracts;

use MultiTenantSaas\Modules\AiSkill\DTOs\AdoptionDecision;

/**
 * 候选采用策略（A 包冻结接口）。
 *
 * 根据风险差异/验证/作用范围返回 auto | needs_review | denied 及 reason。
 * 训练发现可改进不自动触发触达；审核通过不替代本次操作授权。
 */
interface AdoptionPolicyContract
{
    /**
     * @param  array<string, mixed>  $candidate  候选草案 + 验证结果 + evidence_refs
     * @param  array<string, mixed>  $context  作用范围/租户/风险标签
     */
    public function evaluate(array $candidate, array $context): AdoptionDecision;
}
