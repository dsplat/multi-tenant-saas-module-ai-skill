<?php

namespace MultiTenantSaas\Modules\AiSkill\Contracts;

use MultiTenantSaas\Modules\AiSkill\DTOs\OutcomeVerification;

/** Server-registered read-only business verification; never a model/client attestation. */
interface SkillOutcomeVerifierContract
{
    /** Scope comes from the owned run; implementations must recheck authoritative business facts. */
    public function verify(array $scope, array $input, array $outputs, array $criteria): OutcomeVerification;
}
