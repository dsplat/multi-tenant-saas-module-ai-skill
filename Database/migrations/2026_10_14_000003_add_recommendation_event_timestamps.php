<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 推荐事件时间戳补全（D 包）：曝光/点击/采用/忽略/执行/完成分列，点击 ≠ 成功。
 * 仅追加可空列，不改冻结表名/既有索引。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_skill_recommendations')) {
            return;
        }
        if (! Schema::hasColumn('ai_skill_recommendations', 'clicked_at')) {
            Schema::table('ai_skill_recommendations', fn (Blueprint $table) => $table->timestamp('clicked_at')->nullable());
        }
        if (! Schema::hasColumn('ai_skill_recommendations', 'completed_at')) {
            Schema::table('ai_skill_recommendations', fn (Blueprint $table) => $table->timestamp('completed_at')->nullable());
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_skill_recommendations')) {
            return;
        }
        foreach (['clicked_at', 'completed_at'] as $column) {
            if (Schema::hasColumn('ai_skill_recommendations', $column)) {
                Schema::table('ai_skill_recommendations', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
