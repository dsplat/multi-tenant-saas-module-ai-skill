<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\AiSkill\Models\SkillTrainingRun;
use MultiTenantSaas\Modules\AiSkill\Services\SkillTrainer;

/**
 * 异步训练执行 Job（WP-147a）。
 *
 * tries=1：LLM 重试即重复烧 token，失败交由治理入口重新发起新任务。
 * 队列层失败（超时/worker 异常）不经 handle，必须在 failed() 落终态，
 * 否则任务永卡 running。领取与终态写入都是条件更新，重复投递安全。
 */
class RunSkillTrainingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public int $trainingRunId,
        public int $tenantId,
    ) {}

    public function handle(SkillTrainer $trainer): string
    {
        TenantContext::setTenantId((string) $this->tenantId);

        return $trainer->handle($this->trainingRunId);
    }

    public function failed(\Throwable $exception): void
    {
        TenantContext::setTenantId((string) $this->tenantId);

        // 只收敛仍在本 worker 手里的 running 行，不覆盖用户取消或已提交的终态
        DB::table('ai_skill_training_runs')
            ->where('training_run_id', $this->trainingRunId)
            ->where('status', SkillTrainingRun::STATUS_RUNNING)
            ->update([
                'status' => SkillTrainingRun::STATUS_FAILED,
                'error' => mb_substr($exception->getMessage(), 0, 500),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
