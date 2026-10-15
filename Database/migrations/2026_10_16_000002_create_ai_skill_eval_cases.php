<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 冻结评测样本表（WP-147b / BL-144 独立评测入口）。
 *
 * 判据必须独立于候选生成：样本与断言由平台治理入口写入并冻结（content_sha256），
 * 训练处理器与候选正文都无法修改。validation 分集不参与候选抽取，只用于采用门。
 * 主键走 IdGenerator 全局 ID；截断查询以唯一主键收尾。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_skill_eval_cases')) {
            return;
        }

        Schema::create('ai_skill_eval_cases', function (Blueprint $table): void {
            $table->unsignedBigInteger('eval_case_id')->primary();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('dataset_id', 100);
            $table->string('dataset_version', 50);
            $table->string('split', 12);
            $table->string('skill_key', 100);
            $table->string('case_key', 64);
            $table->json('input')->nullable();
            $table->json('asserts')->nullable();
            $table->string('rubric_version', 50);
            $table->string('content_sha256', 64);
            $table->unsignedBigInteger('created_by_operator_id')->nullable();
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'dataset_id', 'dataset_version', 'case_key'],
                'ai_skill_eval_tenant_ds_ver_case_unique'
            );
            $table->index(
                ['tenant_id', 'dataset_id', 'dataset_version', 'split'],
                'ai_skill_eval_tenant_ds_split'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_skill_eval_cases');
    }
};
