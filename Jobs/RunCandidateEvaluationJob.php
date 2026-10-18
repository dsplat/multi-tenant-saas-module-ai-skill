<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use MultiTenantSaas\Concerns\HasTaskAuthEnvelope;
use MultiTenantSaas\Context\CarriesTaskAuthEnvelope;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\AiSkill\Services\CandidateEvaluator;

/**
 * 独立评测 Job（WP-147b）。
 *
 * 样本可能几十条、每条都要真实执行，故必须异步；tries=1 与训练同理（重复即重复烧 token）。
 * 固定链执行路径由 harness 显式带 tenant，不依赖请求认证态；评测器只在候选行锁下写 validation。
 */
class RunCandidateEvaluationJob implements CarriesTaskAuthEnvelope, ShouldQueue
{
    use Dispatchable, HasTaskAuthEnvelope, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public int $candidateId,
        public int $tenantId,
        public string $datasetId,
        public string $datasetVersion,
    ) {}

    public function handle(CandidateEvaluator $evaluator): string
    {
        TenantContext::setTenantId((string) $this->tenantId);

        return $evaluator->handle($this->candidateId, $this->datasetId, $this->datasetVersion);
    }
}
