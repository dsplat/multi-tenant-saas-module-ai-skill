<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 异步训练事实列（WP-147a / BL-144+147）。
 *
 * 训练任务从「只登记 queued」变为可被队列处理器真实执行：必须留下调用与用量的事实，
 * 否则效果门无法区分「模型跑过」与「状态流转过」。
 * cost_micro 仍为 null=unknown（无价格来源时不按 0 记）；cost_source 标明事实来源。
 * 仅追加可空列，不改冻结表名与既有索引（与 D 包 request_key 同模式）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_skill_training_runs')) {
            return;
        }
        $columns = [
            'llm_calls' => fn (Blueprint $table) => $table->unsignedInteger('llm_calls')->nullable(),
            'usage' => fn (Blueprint $table) => $table->json('usage')->nullable(),
            'cost_source' => fn (Blueprint $table) => $table->string('cost_source', 20)->nullable(),
            'started_at' => fn (Blueprint $table) => $table->timestamp('started_at')->nullable(),
            'finished_at' => fn (Blueprint $table) => $table->timestamp('finished_at')->nullable(),
        ];
        foreach ($columns as $name => $define) {
            if (! Schema::hasColumn('ai_skill_training_runs', $name)) {
                Schema::table('ai_skill_training_runs', $define);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_skill_training_runs')) {
            return;
        }
        foreach (['llm_calls', 'usage', 'cost_source', 'started_at', 'finished_at'] as $column) {
            if (Schema::hasColumn('ai_skill_training_runs', $column)) {
                Schema::table('ai_skill_training_runs', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
