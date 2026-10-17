<?php

namespace MultiTenantSaas\Modules\AiSkill\DTOs;

final class OutcomeVerification
{
    public function __construct(
        public readonly string $outcome,
        public readonly ?string $reason = null,
        public readonly array $evidenceRefs = [],
    ) {
        if (! in_array($outcome, [Outcome::SUCCESS, Outcome::INCOMPLETE, Outcome::FAILED, Outcome::UNCERTAIN], true)
            || ($reason !== null && ! ReasonCode::isValid($reason))
            || ($outcome !== Outcome::SUCCESS && $reason === null)
            || ($outcome === Outcome::SUCCESS && ($reason !== null || $evidenceRefs === []))
            || count($evidenceRefs) > 20) {
            throw new \InvalidArgumentException('Invalid independent outcome verification.');
        }
        foreach ($evidenceRefs as $ref) {
            if (! is_string($ref) || ! preg_match('~^[A-Za-z0-9_.:/@-]{1,200}$~D', $ref)) {
                throw new \InvalidArgumentException('Outcome evidence must be a bounded reference.');
            }
        }
    }
}
