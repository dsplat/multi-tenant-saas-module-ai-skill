<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skill 固定执行运行表（C 包 / BL-143）。
 *
 * start 将数据库快照（skill_key/version/content_sha256/方法/输入）固定到本行；
 * advance/cancel 以 revision 乐观锁；request_key 幂等（同键同指纹重放，同键异指纹 409）。
 * 执行状态机在本表；结构化链推进可挂 task_chain_runs.run_id，不复制执行器。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_skill_runs')) {
            return;
        }

        Schema::create('ai_skill_runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('skill_run_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('operator_id')->default(0);
            $table->string('skill_key', 100);
            $table->string('skill_version', 50);
            $table->unsignedBigInteger('skill_definition_id')->nullable();
            $table->string('content_sha256', 64)->nullable();
            $table->string('execution', 20); // chain|instructions|mixed
            $table->string('variant_key', 50)->nullable();
            $table->json('input_params')->nullable();
            $table->json('server_resolved')->nullable();
            $table->json('plan'); // 启动时固化的步骤计划，恢复不改读目录
            $table->string('request_key', 100);
            $table->string('input_fingerprint', 64);
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('current_step')->default(0);
            $table->string('status', 30)->default('running');
            // running|waiting_input|waiting_confirm|completed|failed|cancelled|uncertain
            $table->string('outcome', 20)->nullable(); // success|incomplete|failed|cancelled|uncertain
            $table->string('reason', 40)->nullable();
            $table->json('warnings')->nullable();
            $table->json('result_refs')->nullable();
            $table->unsignedBigInteger('task_chain_run_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'request_key'], 'ai_skill_runs_tenant_request_unique');
            $table->index(['tenant_id', 'status', 'skill_run_id'], 'ai_skill_runs_tenant_status_id');
            $table->index(['tenant_id', 'skill_key', 'skill_version'], 'ai_skill_runs_tenant_skill');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_skill_runs');
    }
};
