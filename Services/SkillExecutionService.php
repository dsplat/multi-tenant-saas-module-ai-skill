<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Services;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Contracts\PermissionCheckerContract;
use MultiTenantSaas\Contracts\TaskChainHarnessContract;
use MultiTenantSaas\Modules\AiSkill\Contracts\ActorResolverContract;
use MultiTenantSaas\Modules\AiSkill\Contracts\SkillExecutionServiceContract;
use MultiTenantSaas\Modules\AiSkill\DTOs\Outcome;
use MultiTenantSaas\Modules\AiSkill\DTOs\OutcomeVerification;
use MultiTenantSaas\Modules\AiSkill\DTOs\RequestKey;
use MultiTenantSaas\Modules\AiSkill\DTOs\SkillRunView;
use MultiTenantSaas\Modules\AiSkill\DTOs\SkillSelection;
use MultiTenantSaas\Modules\AiSkill\DTOs\ValidatedInput;
use MultiTenantSaas\Modules\AiSkill\Exceptions\SkillDomainException;
use MultiTenantSaas\Modules\AiSkill\Models\SkillDefinition;
use MultiTenantSaas\Modules\AiSkill\Models\SkillExperience;
use MultiTenantSaas\Modules\AiSkill\Models\SkillRun;

/**
 * Skill 固定执行（C 包 / BL-143）。
 *
 * start：读取并校验数据库快照，将 skill_key/version/content_sha256/方法/输入/计划固定到 run；
 *        恢复不得重新读目录或改取最新版。
 * advance/cancel：锁 run、校验 revision、幂等；重复请求不重复副作用。
 * actor/tenant 来自注入 resolver，不接受任意 actor 入参。
 * 结构化链步可挂 task_chain_run_id（复用 TaskChainRunner），本服务不复制执行器。
 */
final class SkillExecutionService implements SkillExecutionServiceContract
{
    public function __construct(
        private readonly ActorResolverContract $actors,
        private readonly SkillCatalog $catalog,
        private readonly TaskChainHarnessContract $harness,
        private readonly PermissionCheckerContract $permissions,
    ) {}

    public function start(SkillSelection $selection, ValidatedInput $input, RequestKey $requestKey): SkillRunView
    {
        $actor = $this->actor(true);
        $tenantId = (int) $actor['tenant_id'];
        $operatorId = (int) ($actor['actor_id'] ?? 0);
        $fingerprint = $this->fingerprint([
            'selection' => [$selection->skillKey, $selection->version, $selection->variantKey, $selection->definitionRef, $selection->options],
            'input' => $input->params, 'actor' => $operatorId,
        ]);

        return DB::transaction(function () use ($selection, $input, $requestKey, $tenantId, $operatorId, $fingerprint): SkillRunView {
            $existing = SkillRun::query()->where('tenant_id', $tenantId)
                ->where('request_key', $requestKey->key)->lockForUpdate()->first();
            if ($existing !== null) {
                if ((int) $existing->operator_id !== $operatorId) {
                    throw new SkillDomainException('scope_denied');
                }
                if ($existing->input_fingerprint !== $fingerprint) {
                    throw new SkillDomainException('version_conflict', 'Request key already used with different input.');
                }

                return $this->view($existing);
            }

            // 固定数据库快照：resolveSnapshot 校验摘要；此后运行定义不再回读目录
            $definition = $this->catalog->resolveSnapshot($selection->skillKey, $selection->version);
            if ($selection->definitionRef !== null
                && ! in_array($selection->definitionRef, [(string) $definition->getKey(), (string) $definition->content_ref], true)) {
                throw new SkillDomainException('version_conflict', 'Selection definition_ref does not match resolved version.');
            }
            $this->assertInputMatchesSchema($definition, $input->params);
            if ($selection->variantKey !== null && ! $this->variantAllowed($definition, $selection->variantKey)) {
                throw new SkillDomainException('skill_not_applicable', 'Variant is not declared by this Skill version.');
            }

            $plan = $this->buildPlan($definition, $selection);
            $plan['verification'] = app(SkillOutcomeVerificationService::class)->pin($definition);

            $run = SkillRun::create([
                'tenant_id' => $tenantId,
                'operator_id' => $operatorId,
                'skill_key' => $definition->skill_key,
                'skill_version' => $definition->version,
                'skill_definition_id' => $definition->getKey(),
                'content_sha256' => $definition->content_sha256,
                'execution' => $definition->execution,
                'variant_key' => $selection->variantKey,
                'input_params' => $input->params,
                'server_resolved' => [
                    'tenant_id' => $tenantId,
                    'operator_id' => $operatorId,
                    'policy_key' => null,
                    'policy_version' => null,
                ],
                'plan' => $plan,
                'request_key' => $requestKey->key,
                'input_fingerprint' => $fingerprint,
                'revision' => 1,
                'current_step' => 0,
                'status' => SkillRun::STATUS_RUNNING,
                'warnings' => $plan['warnings'] ?? [],
            ]);

            if ($definition->execution !== 'instructions') {
                try {
                    $chain = $this->harness->startPinned($plan['definition'], $tenantId, $input->params);
                } catch (\InvalidArgumentException $e) {
                    throw new SkillDomainException('tool_incompatible', 'Pinned chain is not executable.', previous: $e);
                }
                $run->task_chain_run_id = $chain['run_id'];
                $run->save();
            }

            return $this->view($run);
        }, 3);
    }

    public function advance(int $runId, int $expectedRevision, array $stepInput = []): SkillRunView
    {
        return DB::transaction(function () use ($runId, $expectedRevision, $stepInput): SkillRunView {
            $run = $this->lockRun($runId);
            $this->assertRevision($run, $expectedRevision);
            if ($run->status === SkillRun::STATUS_CANCELLED) {
                throw new SkillDomainException('version_conflict', 'Run is cancelled.');
            }
            if ($run->status === SkillRun::STATUS_UNCERTAIN) {
                throw new SkillDomainException('review_required', 'Run result is uncertain; reconcile before advancing.');
            }
            if (in_array($run->status, [SkillRun::STATUS_COMPLETED, SkillRun::STATUS_FAILED], true)) {
                return $this->view($run);
            }

            if ($run->execution === 'instructions' || $run->task_chain_run_id === null) {
                throw new SkillDomainException('verification_missing', 'Use the assistant for instructions; no trusted execution is bound.');
            }
            if ($stepInput !== []) {
                throw new SkillDomainException('verification_missing', 'Client output is not execution evidence.');
            }
            try {
                $result = $this->harness->advancePinned((int) $run->task_chain_run_id, (int) $run->tenant_id);
            } catch (\InvalidArgumentException $e) {
                throw new SkillDomainException('tool_incompatible', 'Pinned tool is no longer executable.', previous: $e);
            } catch (\Throwable $e) {
                // Never retry an external call whose outcome is unknown.
                $run->status = SkillRun::STATUS_UNCERTAIN;
                $run->outcome = Outcome::UNCERTAIN;
                $run->reason = 'verification_missing';
                $run->revision++;
                $run->save();
                $this->recordExperience($run);

                return $this->view($run);
            }
            $plan = (array) $run->plan;
            foreach ($result['steps'] as $index => $step) {
                $plan['steps'][$index]['status'] = $step['status'];
            }
            $run->plan = $plan;
            $run->result_refs = ['task_chain_run_id' => (string) $run->task_chain_run_id, 'outputs' => $result['context'] ?? []];
            $run->current_step = $result['current_step'];
            $run->status = $result['status'];
            $run->outcome = match ($result['status']) {
                'completed' => Outcome::INCOMPLETE,
                'failed' => Outcome::FAILED,
                default => null,
            };
            $run->reason = $result['status'] === 'completed' ? 'verification_missing' : null;
            if ($result['status'] === 'completed') {
                try {
                    $verification = app(SkillOutcomeVerificationService::class)->verify($run, (array) ($result['context'] ?? []));
                } catch (\Throwable) {
                    // Verification failure cannot replay a completed business operation.
                    $verification = new OutcomeVerification(Outcome::UNCERTAIN, 'verification_missing');
                }
                $run->outcome = $verification->outcome;
                $run->reason = $verification->reason;
                $run->result_refs = array_merge((array) $run->result_refs, ['verification' => [
                    'outcome' => $verification->outcome, 'reason' => $verification->reason,
                    'evidence_refs' => $verification->evidenceRefs,
                    'verifier' => $plan['verification']['verifier'] ?? null,
                ]]);
            }
            $run->revision++;
            $run->save();
            if ($run->outcome !== null) {
                $this->recordExperience($run);
            }

            return $this->view($run);
        }, 3);
    }

    public function cancel(int $runId, int $expectedRevision): SkillRunView
    {
        return DB::transaction(function () use ($runId, $expectedRevision): SkillRunView {
            $run = $this->lockRun($runId);
            $this->assertRevision($run, $expectedRevision);
            if ($run->isFinished()) {
                return $this->view($run);
            }

            if ($run->task_chain_run_id !== null) {
                $this->harness->cancelPinned((int) $run->task_chain_run_id, (int) $run->tenant_id);
            }
            // 已发生副作用不回滚；取消是终态，不重放
            $run->status = SkillRun::STATUS_CANCELLED;
            $run->outcome = Outcome::CANCELLED;
            $run->reason = $run->reason ?? 'cancelled';
            $run->revision = $run->revision + 1;
            $run->save();
            $this->recordExperience($run);

            return $this->view($run);
        }, 3);
    }

    /** 读取当前 run（恢复入口）；固定定义，不重解析目录 */
    public function get(int $runId): SkillRunView
    {
        $run = $this->ownedRun($runId);

        return $this->view($run);
    }

    /**
     * 当前租户可恢复的 run（未完成优先，同秒按主键稳定排序）。
     *
     * @return list<SkillRunView>
     */
    public function listActive(int $limit = 50): array
    {
        $actor = $this->actor();
        $limit = min(100, max(1, $limit));
        $runs = SkillRun::query()->where('tenant_id', (int) $actor['tenant_id'])->where('operator_id', (int) $actor['actor_id'])
            ->whereIn('status', [
                SkillRun::STATUS_RUNNING,
                SkillRun::STATUS_WAITING_INPUT,
                SkillRun::STATUS_WAITING_CONFIRM,
                SkillRun::STATUS_FAILED,
                SkillRun::STATUS_UNCERTAIN,
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc((new SkillRun)->getKeyName())
            ->limit($limit)->get();

        return $runs->map(fn (SkillRun $run): SkillRunView => $this->view($run))->all();
    }

    private function buildPlan(SkillDefinition $definition, SkillSelection $selection): array
    {
        if ($definition->execution === 'instructions') {
            return ['steps' => [], 'warnings' => [['code' => 'instructions_path', 'message' => '请通过小秘书探索执行；此处不记录未经验证的成功。']]];
        }
        try {
            $body = json_decode($definition->snapshot(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SkillDomainException('tool_incompatible', 'Executable Skill requires a versioned JSON chain.', previous: $e);
        }
        if (($body['schema_version'] ?? null) !== 1 || ! is_array($body['chain'] ?? null)) {
            throw new SkillDomainException('tool_incompatible', 'Executable Skill schema is missing.');
        }
        $chain = $body['chain'];
        $chain['key'] = $definition->skill_key . '@' . $definition->version;
        $chain['title'] = $definition->title ?: $definition->skill_key;
        foreach (($chain['steps'] ?? []) as $step) {
            if (! is_array($step) || ($step['type'] ?? null) !== 'tool'
                || ! in_array($step['tool'] ?? null, (array) $definition->tool_deps, true)) {
                throw new SkillDomainException('tool_incompatible', 'Step must use a declared tool dependency.');
            }
        }

        return ['steps' => $chain['steps'] ?? [], 'definition' => $chain, 'content_sha256' => $definition->content_sha256];
    }

    /** Called once under the run lock in the same transaction as its terminal outcome. */
    private function recordExperience(SkillRun $run): void
    {
        SkillExperience::create([
            'tenant_id' => (int) $run->tenant_id,
            'actor_type' => 'operator', 'actor_id' => (int) $run->operator_id,
            'task_ref' => 'skill.' . $run->skill_key,
            'run_ref' => 'skill_run:' . $run->getKey(),
            'source' => SkillExperience::SOURCE_SKILL,
            'skill_key' => $run->skill_key, 'skill_version' => $run->skill_version,
            'outcome' => $run->outcome, 'occurred_at' => now(),
            'result_criteria' => (array) ($run->plan['verification']['criteria'] ?? []),
            'input_refs' => ['skill_run:' . $run->getKey()],
            'evidence_refs' => array_merge(['skill_run:' . $run->getKey()],
                (array) ($run->result_refs['verification']['evidence_refs'] ?? [])),
            // High trust means attributable server provenance, not necessarily a successful result.
            'trust' => SkillExperience::TRUST_HIGH,
        ]);
    }

    private function variantAllowed(SkillDefinition $definition, string $variantKey): bool
    {
        $declared = $definition->input_schema['properties']['variant_key']['enum']
            ?? $definition->applicability['variants']
            ?? null;
        if (! is_array($declared)) {
            return false;
        }

        return in_array($variantKey, $declared, true);
    }

    private function assertInputMatchesSchema(SkillDefinition $definition, array $params): void
    {
        $schema = is_array($definition->input_schema) ? $definition->input_schema : [];
        if (($schema['type'] ?? 'object') !== 'object') {
            throw new SkillDomainException('data_incomplete', 'Skill input_schema must be object.');
        }
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        foreach ($params as $key => $value) {
            if (! is_string($key) || ! array_key_exists($key, $properties)) {
                if (($schema['additionalProperties'] ?? true) === false) {
                    throw new SkillDomainException('skill_not_applicable', "Input property [{$key}] is not allowed.");
                }

                continue;
            }
            $prop = (array) $properties[$key];
            if (isset($prop['enum']) && is_array($prop['enum']) && ! in_array($value, $prop['enum'], true)) {
                throw new SkillDomainException('skill_not_applicable', "Input property [{$key}] is outside the declared enum.");
            }
            $expected = $prop['type'] ?? null;
            $ok = match ($expected) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'array' => is_array($value),
                'object' => is_array($value),
                default => true,
            };
            if (! $ok) {
                throw new SkillDomainException('data_incomplete', "Input property [{$key}] type mismatch.");
            }
        }
        foreach ($schema['required'] ?? [] as $required) {
            if (! array_key_exists($required, $params)) {
                throw new SkillDomainException('data_incomplete', "Input property [{$required}] is required.");
            }
        }
    }

    private function lockRun(int $runId): SkillRun
    {
        $actor = $this->actor(true);
        $run = SkillRun::query()->whereKey($runId)
            ->where('tenant_id', (int) $actor['tenant_id'])->where('operator_id', (int) $actor['actor_id'])->lockForUpdate()->first();
        if ($run === null) {
            throw new SkillDomainException('scope_denied', 'Run not found in current tenant.');
        }

        return $run;
    }

    private function ownedRun(int $runId): SkillRun
    {
        $actor = $this->actor();
        $run = SkillRun::query()->whereKey($runId)
            ->where('tenant_id', (int) $actor['tenant_id'])->where('operator_id', (int) $actor['actor_id'])->first();
        if ($run === null) {
            throw new SkillDomainException('scope_denied', 'Run not found in current tenant.');
        }

        return $run;
    }

    private function assertRevision(SkillRun $run, int $expectedRevision): void
    {
        if ($expectedRevision !== (int) $run->revision) {
            throw new SkillDomainException('version_conflict', 'Run revision conflict; refresh and retry.');
        }
    }

    private function actor(bool $execute = false): array
    {
        $actor = $this->actors->resolve();
        if (($actor['actor_type'] ?? null) !== 'operator' || (int) ($actor['actor_id'] ?? 0) <= 0
            || (int) ($actor['tenant_id'] ?? 0) <= 0) {
            throw new SkillDomainException('scope_denied');
        }

        if ($execute && ! $this->permissions->check('ai.capability.execute')) {
            throw new SkillDomainException('scope_denied', 'Skill execution permission required.');
        }

        return $actor;
    }

    private function view(SkillRun $run): SkillRunView
    {
        $plan = (array) $run->plan;
        $steps = array_map(function (array $step): array {
            return [
                'name' => (string) ($step['name'] ?? ''),
                'type' => (string) ($step['type'] ?? ''),
                'status' => (string) ($step['status'] ?? 'pending'),
                'output_key' => $step['output_key'] ?? null,
            ];
        }, $plan['steps'] ?? []);

        return new SkillRunView(
            runId: (int) $run->getKey(),
            skillKey: (string) $run->skill_key,
            skillVersion: (string) $run->skill_version,
            revision: (int) $run->revision,
            status: (string) $run->status,
            currentStep: (int) $run->current_step,
            steps: $steps,
            context: [
                'variant_key' => $run->variant_key,
                'execution' => $run->execution,
                'content_sha256' => $run->content_sha256,
                'input' => (array) $run->input_params,
                'result_refs' => (array) $run->result_refs,
            ],
            outcome: $run->outcome,
            reason: $run->reason,
            nextAction: $this->nextAction($run),
            warnings: (array) $run->warnings,
        );
    }

    private function nextAction(SkillRun $run): ?string
    {
        return match ($run->status) {
            SkillRun::STATUS_WAITING_INPUT => 'submit_step_input',
            SkillRun::STATUS_WAITING_CONFIRM => 'confirm_or_reject_step',
            SkillRun::STATUS_RUNNING => $run->execution === 'instructions' ? 'explore_with_assistant' : 'advance',
            SkillRun::STATUS_FAILED => 'retry_or_cancel',
            SkillRun::STATUS_UNCERTAIN => 'reconcile',
            SkillRun::STATUS_COMPLETED, SkillRun::STATUS_CANCELLED => null,
            default => null,
        };
    }

    public function fingerprint(array $params): string
    {
        return hash('sha256', json_encode($this->canonical($params), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($this->canonical(...), $value);
        }

        return $value;
    }
}
