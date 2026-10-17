<?php

namespace MultiTenantSaas\Modules\AiSkill\Services;

use MultiTenantSaas\Modules\AiSkill\Contracts\SkillOutcomeVerifierContract;
use MultiTenantSaas\Modules\AiSkill\DTOs\Outcome;
use MultiTenantSaas\Modules\AiSkill\DTOs\OutcomeVerification;
use MultiTenantSaas\Modules\AiSkill\Models\SkillDefinition;
use MultiTenantSaas\Modules\AiSkill\Models\SkillRun;

/** Exact Skill-key registration is deployment-owned; candidates cannot select executable PHP. */
final class SkillOutcomeVerificationService
{
    public function pin(SkillDefinition $definition): array
    {
        return [
            'criteria' => (array) $definition->success_criteria,
            'verifier' => $this->descriptor((string) $definition->skill_key),
        ];
    }

    public function verify(SkillRun $run, array $outputs): OutcomeVerification
    {
        $pin = $run->plan['verification'] ?? [];
        if (! is_array($pin['verifier'] ?? null) || empty($pin['criteria'])) {
            return new OutcomeVerification(Outcome::INCOMPLETE, 'verification_missing');
        }
        // Changing deployed validation code does not retroactively change a running contract.
        if ($pin['verifier'] !== $this->descriptor((string) $run->skill_key)) {
            return new OutcomeVerification(Outcome::UNCERTAIN, 'version_conflict');
        }
        $verifier = app($pin['verifier']['class']);
        // instanceof 只证明「像」，还得就是钉定的那个类：容器 bind 可以把注册类换成恒通过的实现，
        // 而 sha 钉的只是注册类的文件，替换后仍然匹配。
        if (! $verifier instanceof SkillOutcomeVerifierContract || get_class($verifier) !== $pin['verifier']['class']) {
            return new OutcomeVerification(Outcome::UNCERTAIN, 'verification_missing');
        }

        return $verifier->verify([
            'tenant_id' => (int) $run->tenant_id,
            'operator_id' => (int) $run->operator_id,
            'skill_run_id' => (int) $run->getKey(),
            'task_chain_run_id' => (int) $run->task_chain_run_id,
            'skill_key' => (string) $run->skill_key,
            'skill_version' => (string) $run->skill_version,
        ], (array) $run->input_params, $outputs, (array) $pin['criteria']);
    }

    private function descriptor(string $skillKey): ?array
    {
        $registration = config('ai-skill.outcome_verifiers', [])[$skillKey] ?? null;
        if (! is_array($registration) || ! is_string($registration['class'] ?? null)
            || ! is_string($registration['version'] ?? null) || $registration['version'] === ''
            || ! is_subclass_of($registration['class'], SkillOutcomeVerifierContract::class)) {
            return null;
        }
        $file = (new \ReflectionClass($registration['class']))->getFileName();
        if ($file === false || ! is_readable($file)) {
            return null;
        }

        return ['class' => $registration['class'], 'version' => $registration['version'], 'sha256' => hash_file('sha256', $file)];
    }
}
