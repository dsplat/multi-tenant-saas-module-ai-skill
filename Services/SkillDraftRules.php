<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Services;

/**
 * 候选正文的唯一结构标准。
 *
 * 生成方（训练器）与采用方（发布器）必须校验同一套规则，
 * 否则「过了生成门」不代表「能采用」，两处各写一份迟早分叉。
 */
final class SkillDraftRules
{
    /**
     * @return array<string, string>
     */
    public static function rules(): array
    {
        return [
            'skill_key' => 'required|string|max:100',
            'version' => 'required|string|max:50',
            'title' => 'required|string|max:255',
            'description' => 'sometimes|string|max:2000',
            'execution' => 'required|in:instructions,chain,mixed',
            'content_body' => 'required|string|max:262144',
            'input_schema' => 'required|array',
            'applicability' => 'present|array',
            'tool_deps' => 'present|array',
            'risk' => 'required|array',
            'success_criteria' => 'present|array',
        ];
    }
}
