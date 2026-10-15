<?php

namespace MultiTenantSaas\Modules\AiSkill\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use MultiTenantSaas\Contracts\TaskChainHarnessContract;
use MultiTenantSaas\Modules\AiSkill\Exceptions\SkillDomainException;
use MultiTenantSaas\Modules\AiSkill\Models\SkillCandidate;
use MultiTenantSaas\Modules\AiSkill\Models\SkillDefinition;

/** Called under the candidate row lock, in the adoption transaction. */
final class CandidatePublication
{
    public function publish(SkillCandidate $candidate): SkillDefinition
    {
        if ($candidate->bound_definition_id !== null) {
            return SkillDefinition::whereKey($candidate->bound_definition_id)->firstOrFail();
        }
        $draft = (array) $candidate->skill_draft;
        $tenant = (int) $candidate->tenant_id;
        $data = validator($draft, SkillDraftRules::rules())->validate();
        if ($tenant <= 0 || ! str_starts_with($data['skill_key'], 'tenant.' . $tenant . '.')) {
            throw new SkillDomainException('scope_denied', 'Adoption can publish only a namespaced tenant version.');
        }
        // A version conflict never overwrites or silently reactivates another publication.
        if (SkillDefinition::where('scope_type', 'tenant')->where('scope_id', $tenant)
            ->where('skill_key', $data['skill_key'])->where('version', $data['version'])->exists()) {
            throw new SkillDomainException('version_conflict', 'Target version already exists.');
        }
        if ($data['execution'] !== 'instructions') {
            try {
                $body = json_decode($data['content_body'], true, 64, JSON_THROW_ON_ERROR);
                if (($body['schema_version'] ?? null) !== 1 || ! is_array($body['chain'] ?? null)) {
                    throw new \InvalidArgumentException('Missing versioned executable chain.');
                }
                $chain = $body['chain'];
                $chain['key'] = $data['skill_key'] . '@' . $data['version'];
                $chain['title'] = $data['title'];
                foreach ($chain['steps'] ?? [] as $step) {
                    if (! is_array($step) || ! in_array($step['tool'] ?? null, $data['tool_deps'], true)) {
                        throw new \InvalidArgumentException('Undeclared tool.');
                    }
                }
                app(TaskChainHarnessContract::class)->validatePinned($chain, $tenant);
            } catch (\JsonException|\InvalidArgumentException $e) {
                throw new SkillDomainException('tool_incompatible', 'Candidate executable definition is invalid.', previous: $e);
            }
        }
        $digest = hash('sha256', $data['content_body']);
        try {
            $definition = SkillDefinition::create($data + [
                'scope_type' => 'tenant', 'scope_id' => $tenant,
                'owner_type' => 'tenant', 'owner_id' => $tenant,
                'content_sha256' => $digest, 'content_ref' => 'sha256:' . $digest,
                'status' => SkillDefinition::STATUS_PUBLISHED,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            throw new SkillDomainException('version_conflict', 'Target version was published concurrently.', previous: $e);
        }
        $definition->snapshot();
        $candidate->bound_definition_id = $definition->getKey();

        return $definition;
    }

    /** Baseline/range are resolved from trusted persisted rows, never from HTTP context. */
    public function context(SkillCandidate $candidate): array
    {
        $validation = (array) $candidate->validation;
        $baselineId = $validation['baseline_definition_id'] ?? null;
        $baseline = SkillDefinition::whereKey($baselineId)->where('status', 'published')
            ->where(fn ($q) => $q->where(fn ($g) => $g->whereIn('scope_type', ['module', 'project'])->where('scope_id', 0))
                ->orWhere(fn ($own) => $own->where('scope_type', 'tenant')->where('scope_id', $candidate->tenant_id)))->first();
        if ($baseline === null) {
            return [];
        }
        $baseline->snapshot();

        return ['baseline' => $baseline->toArray(), 'limited_tenant_ids' => [(int) $candidate->tenant_id]];
    }
}
