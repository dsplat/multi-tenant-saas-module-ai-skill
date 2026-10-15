<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Services;

use MultiTenantSaas\Modules\AiSkill\Contracts\AdoptionPolicyContract;
use MultiTenantSaas\Modules\AiSkill\DTOs\AdoptionDecision;

/**
 * 候选采用策略（设计 §12.5 / BL-144）。
 *
 * 低风险等价只读变体 → auto（可限定租户）；
 * 新增写步骤 / 扩大对象或权限 / 降低确认 / 验证不过 / 证据不足 → needs_review 或 denied。
 * 界面不得自行计算「低风险」；本类是唯一裁决点。
 */
final class AdoptionPolicy implements AdoptionPolicyContract
{
    public function evaluate(array $candidate, array $context): AdoptionDecision
    {
        $draft = is_array($candidate['skill_draft'] ?? null) ? $candidate['skill_draft'] : [];
        $validation = is_array($candidate['validation'] ?? null) ? $candidate['validation'] : [];
        $evidence = is_array($candidate['evidence_refs'] ?? null) ? $candidate['evidence_refs'] : [];
        $baseline = is_array($context['baseline'] ?? null) ? $context['baseline'] : [];

        if ($baseline === [] || ! in_array(strtolower((string) ($draft['risk']['level'] ?? 'unknown')), ['low', 'l1', 'medium', 'l2', 'high', 'l3'], true)) {
            return new AdoptionDecision(AdoptionDecision::DENIED, 'verification_missing');
        }

        if (($validation['passed'] ?? false) !== true) {
            return new AdoptionDecision(
                AdoptionDecision::DENIED,
                'verification_missing',
                ['validation' => $validation],
            );
        }

        if (! is_int($validation['holdout_cases'] ?? null) || $validation['holdout_cases'] < 10
            || ($validation['severe_errors'] ?? null) !== 0
            || ! is_numeric($validation['holdout_score'] ?? null)
            || ! is_numeric($validation['baseline_score'] ?? null)
            || (float) $validation['holdout_score'] > 1 || (float) $validation['baseline_score'] > 1
            || (float) $validation['baseline_score'] < 0
            || (float) $validation['holdout_score'] < (float) $validation['baseline_score']) {
            return new AdoptionDecision(AdoptionDecision::DENIED, 'insufficient_evidence');
        }

        if (($validation['holdout_score'] ?? null) !== null
            && is_numeric($validation['holdout_score'])
            && (float) $validation['holdout_score'] < 0.8) {
            return new AdoptionDecision(
                AdoptionDecision::DENIED,
                'insufficient_evidence',
                ['holdout_score' => $validation['holdout_score']],
            );
        }

        if ($evidence === [] || count($evidence) < 1) {
            return new AdoptionDecision(
                AdoptionDecision::DENIED,
                'insufficient_evidence',
                ['evidence_count' => 0],
            );
        }

        $risk = is_array($draft['risk'] ?? null) ? $draft['risk'] : [];
        $baselineRisk = is_array($baseline['risk'] ?? null) ? $baseline['risk'] : [];
        $businessWrite = (bool) ($risk['business_write'] ?? false);
        $level = (string) ($risk['level'] ?? 'unknown');
        $baselineLevel = (string) ($baselineRisk['level'] ?? 'unknown');

        // 实质行为变化：新增/扩大写、对象/权限扩大、确认要求降低
        if ($businessWrite || ($risk['business_write'] ?? null) !== false) {
            return new AdoptionDecision(
                AdoptionDecision::NEEDS_REVIEW,
                'review_required',
                ['change' => 'new_write_step'],
            );
        }
        if ($this->expandsScope($draft, $baseline)) {
            return new AdoptionDecision(
                AdoptionDecision::NEEDS_REVIEW,
                'review_required',
                ['change' => 'scope_expanded'],
            );
        }
        if ($this->lowersConfirmation($draft, $baseline)) {
            return new AdoptionDecision(
                AdoptionDecision::NEEDS_REVIEW,
                'review_required',
                ['change' => 'confirmation_lowered'],
            );
        }
        if ($this->levelRank($level) !== 1 || $this->levelRank($baselineLevel) === 99) {
            return new AdoptionDecision(
                AdoptionDecision::NEEDS_REVIEW,
                'review_required',
                ['change' => 'risk_increased', 'level' => $level, 'baseline' => $baselineLevel],
            );
        }

        // 低风险等价只读：可自动采用；限定租户集合由调用方给出（空=策略显式允许不限定）
        $limited = array_values(array_filter(
            (array) ($context['limited_tenant_ids'] ?? []),
            static fn ($id): bool => is_int($id) || (is_string($id) && ctype_digit($id)),
        ));

        return new AdoptionDecision(
            AdoptionDecision::AUTO,
            'low_risk_equivalent_readonly',
            [
                'equivalent_readonly' => true,
                'risk_level' => $level,
                'evidence_count' => count($evidence),
            ],
            $limited,
        );
    }

    private function expandsScope(array $draft, array $baseline): bool
    {
        $deps = array_map('strval', (array) ($draft['tool_deps'] ?? []));
        $baseDeps = array_map('strval', (array) ($baseline['tool_deps'] ?? []));
        $newDeps = array_values(array_diff($deps, $baseDeps));

        return $newDeps !== [];
    }

    private function lowersConfirmation(array $draft, array $baseline): bool
    {
        $confirm = $draft['requires_confirmation'] ?? $draft['risk']['requires_confirmation'] ?? null;
        $baseConfirm = $baseline['requires_confirmation'] ?? $baseline['risk']['requires_confirmation'] ?? null;
        if ($confirm === null || $baseConfirm === null) {
            return false;
        }

        return ($baseConfirm === true || $baseConfirm === 1) && ($confirm === false || $confirm === 0);
    }

    private function levelRank(string $level): int
    {
        return match (strtolower($level)) {
            'low', 'l1' => 1,
            'medium', 'l2', 'med' => 2,
            'high', 'l3' => 3,
            default => 99,
        };
    }
}
