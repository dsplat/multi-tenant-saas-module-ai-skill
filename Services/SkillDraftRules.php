<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Services;

use MultiTenantSaas\Modules\AiSkill\Exceptions\SkillDomainException;

/**
 * 候选正文的唯一结构标准。
 *
 * 生成方（训练器）与采用方（发布器）必须校验同一套规则，
 * 否则「过了生成门」不代表「能采用」，两处各写一份迟早分叉。
 */
final class SkillDraftRules
{
    /**
     * 评测证据必须绑定「当前这份草稿」：改稿即证据失效。
     *
     * 发布是唯一咽喉（AUTO 采用与训练自动发布都从这里走），因此校验也只在这里做一次：
     * 少了它，一份手写形状合格的 validation 就能带着旧证据发布未经评测的内容。
     */
    public static function assertCurrentEvaluationEvidence(array $draft, array $validation): void
    {
        $evaluation = (array) ($validation['evaluation'] ?? []);
        if (($evaluation['evidence_level'] ?? null) !== CandidateEvaluator::EVIDENCE_LEVEL
            || ! is_string($evaluation['draft_sha256'] ?? null)
            || ! hash_equals($evaluation['draft_sha256'], self::fingerprint($draft))
            || ! is_string($evaluation['manifest_sha256'] ?? null)
            || ! preg_match('/^[a-f0-9]{64}$/D', $evaluation['manifest_sha256'])
            || empty($evaluation['dataset_id']) || empty($evaluation['dataset_version'])
            || empty($evaluation['rubric_version']) || empty($evaluation['evaluator_version'])) {
            throw new SkillDomainException('verification_missing', 'Adoption requires current independent read-only evaluation evidence.');
        }
    }

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

    /** Bind independent evidence and review decisions to the exact candidate semantics. */
    public static function fingerprint(array $draft): string
    {
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($canonical, $value);
        };

        return hash('sha256', json_encode($canonical(array_intersect_key($draft,
            array_flip(array_keys(self::rules())))), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
