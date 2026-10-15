<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 训练任务幂等键（D 包 / BL-145+146 后端）。
 * enqueue 必须携带 RequestKey：同键同指纹重放，同键异指纹 409。
 * 不改冻结表名/既有索引，仅追加可空列（与 B 包正文快照同模式）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_skill_training_runs')) {
            return;
        }
        if (! Schema::hasColumn('ai_skill_training_runs', 'request_key')) {
            Schema::table('ai_skill_training_runs', fn (Blueprint $table) => $table->string('request_key', 100)->nullable());
        }
        if (! Schema::hasColumn('ai_skill_training_runs', 'input_fingerprint')) {
            Schema::table('ai_skill_training_runs', fn (Blueprint $table) => $table->string('input_fingerprint', 64)->nullable());
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_skill_training_runs')) {
            return;
        }
        foreach (['request_key', 'input_fingerprint'] as $column) {
            if (Schema::hasColumn('ai_skill_training_runs', $column)) {
                Schema::table('ai_skill_training_runs', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
