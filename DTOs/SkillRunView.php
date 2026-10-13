<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\DTOs;

/**
 * 运行视图。expectedRevision 用于乐观并发；关键状态操作锁 run 并校验 revision。
 */
final class SkillRunView
{
    /**
     * @param  array<string, mixed>  $steps  [{name,type,status,output_key},…]
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $warnings
     */
    public function __construct(
        public readonly int $runId,
        public readonly string $skillKey,
        public readonly string $skillVersion,
        public readonly int $revision,
        public readonly string $status,
        public readonly int $currentStep,
        public readonly array $steps = [],
        public readonly array $context = [],
        public readonly ?string $outcome = null,
        public readonly ?string $reason = null,
        public readonly ?string $nextAction = null,
        public readonly array $warnings = [],
        public readonly ?ExecutionEnvelope $envelope = null,
    ) {}

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function with(array $overrides): self
    {
        return new self(
            runId: $overrides['runId'] ?? $this->runId,
            skillKey: $overrides['skillKey'] ?? $this->skillKey,
            skillVersion: $overrides['skillVersion'] ?? $this->skillVersion,
            revision: $overrides['revision'] ?? $this->revision,
            status: $overrides['status'] ?? $this->status,
            currentStep: $overrides['currentStep'] ?? $this->currentStep,
            steps: $overrides['steps'] ?? $this->steps,
            context: $overrides['context'] ?? $this->context,
            outcome: array_key_exists('outcome', $overrides) ? $overrides['outcome'] : $this->outcome,
            reason: array_key_exists('reason', $overrides) ? $overrides['reason'] : $this->reason,
            nextAction: array_key_exists('nextAction', $overrides) ? $overrides['nextAction'] : $this->nextAction,
            warnings: $overrides['warnings'] ?? $this->warnings,
            envelope: array_key_exists('envelope', $overrides) ? $overrides['envelope'] : $this->envelope,
        );
    }
}
