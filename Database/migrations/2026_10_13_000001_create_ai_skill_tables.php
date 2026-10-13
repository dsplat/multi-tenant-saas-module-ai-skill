<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Support\MySqlOnlyMigration;

/**
 * AiSkill 最小持久对象（A 包 / BL-141＋142）。
 *
 * 表名与索引见 docs/decisions/2026-10-13-aiskill-schema-mapping.md（冻结候选）。
 * 主键全局 ID，列名取领域名（禁止 id）；截断排序必须唯一键收尾。
 * 生产绑定/ServiceProvider 由集成者接线，本迁移仅建表。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (MySqlOnlyMigration::skip(__FILE__)) {
            return;
        }

        if (! Schema::hasTable('ai_skill_definitions')) {
            Schema::create('ai_skill_definitions', function (Blueprint $table): void {
                $table->unsignedBigInteger('skill_definition_id')->primary();
                $table->string('skill_key', 100);
                $table->string('version', 50);
                $table->string('scope_type', 20); // module|project|tenant
                $table->unsignedBigInteger('scope_id')->nullable();
                $table->string('owner_type', 20)->nullable();
                $table->unsignedBigInteger('owner_id')->nullable();
                $table->string('execution', 20); // chain|instructions|mixed
                $table->string('title', 255)->nullable();
                $table->text('description')->nullable();
                $table->json('input_schema')->nullable();
                $table->json('applicability')->nullable();
                $table->json('tool_deps')->nullable();
                $table->json('risk')->nullable();
                $table->json('success_criteria')->nullable();
                $table->string('content_ref', 255)->nullable();
                $table->string('status', 20)->default('draft'); // draft|published|disabled
                $table->timestamps();

                $table->unique(['skill_key', 'version', 'scope_type', 'scope_id'], 'ai_skill_def_key_ver_scope_unique');
                $table->index(['scope_type', 'scope_id', 'skill_key'], 'ai_skill_def_scope_key');
            });
        }

        if (! Schema::hasTable('ai_skill_favorites')) {
            Schema::create('ai_skill_favorites', function (Blueprint $table): void {
                $table->unsignedBigInteger('skill_favorite_id')->primary();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('operator_id');
                $table->string('scope', 20)->default('personal'); // personal|tenant
                $table->string('skill_key', 100);
                $table->string('version', 50);
                $table->json('allowed_defaults')->nullable();
                $table->string('label', 255)->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'operator_id', 'skill_key', 'version'], 'ai_skill_fav_tenant_op_key_ver_unique');
                $table->index(['tenant_id', 'scope', 'skill_key'], 'ai_skill_fav_tenant_scope_key');
            });
        }

        if (! Schema::hasTable('ai_skill_experiences')) {
            Schema::create('ai_skill_experiences', function (Blueprint $table): void {
                $table->unsignedBigInteger('experience_id')->primary();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('actor_type', 20)->default('unknown');
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('task_ref', 64)->nullable();
                $table->string('run_ref', 64)->nullable();
                $table->string('source', 20); // manual|agent|skill
                $table->string('goal', 255)->nullable();
                $table->json('input_refs')->nullable();
                $table->json('step_param_source')->nullable();
                $table->json('result_criteria')->nullable();
                $table->string('outcome', 20); // success|incomplete|failed|cancelled|uncertain
                $table->string('tool_ref', 120)->nullable();
                $table->string('skill_key', 100)->nullable();
                $table->string('skill_version', 50)->nullable();
                $table->timestamp('occurred_at')->nullable();
                $table->json('cost_refs')->nullable();
                $table->json('evidence_refs')->nullable();
                $table->string('trace_id', 64)->nullable();
                $table->string('span_id', 64)->nullable();
                $table->string('parent_span_id', 64)->nullable();
                $table->json('attributes')->nullable();
                $table->string('trust', 20)->default('unknown'); // high|low|unknown
                $table->timestamps();

                // 截断排序唯一键收尾（created_at 秒精度非唯一）
                $table->index(['tenant_id', 'occurred_at', 'experience_id'], 'ai_skill_exp_tenant_time_id');
                $table->index(['tenant_id', 'task_ref'], 'ai_skill_exp_tenant_task');
                $table->index(['tenant_id', 'skill_key', 'skill_version'], 'ai_skill_exp_tenant_skill');
            });
        }

        if (! Schema::hasTable('ai_skill_training_runs')) {
            Schema::create('ai_skill_training_runs', function (Blueprint $table): void {
                $table->unsignedBigInteger('training_run_id')->primary();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('actor_type', 20)->default('unknown');
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('mode', 20); // module_docs|history|recommend_optimize|failure_diagnosis
                $table->json('dataset_manifest')->nullable();
                $table->string('config_fingerprint', 80)->nullable();
                $table->string('prompt_fingerprint', 80)->nullable();
                $table->string('model_ref', 120)->nullable();
                $table->json('budget')->nullable();
                $table->unsignedBigInteger('cost_micro')->nullable(); // null=unknown
                $table->string('status', 20)->default('queued'); // queued|running|cancelled|failed|completed
                $table->text('error')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'status', 'training_run_id'], 'ai_skill_train_tenant_status_id');
                $table->index(['tenant_id', 'created_at', 'training_run_id'], 'ai_skill_train_tenant_time_id');
            });
        }

        if (! Schema::hasTable('ai_skill_candidates')) {
            Schema::create('ai_skill_candidates', function (Blueprint $table): void {
                $table->unsignedBigInteger('candidate_id')->primary();
                $table->unsignedBigInteger('training_run_id')->index();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('candidate_key', 64);
                $table->json('skill_draft')->nullable();
                $table->json('evidence_refs')->nullable();
                $table->json('validation')->nullable();
                $table->string('status', 20)->default('proposed'); // proposed|validated|adopted|rejected
                $table->json('adoption_policy_result')->nullable();
                $table->unsignedBigInteger('bound_definition_id')->nullable();
                $table->timestamps();

                $table->unique(['training_run_id', 'candidate_key'], 'ai_skill_cand_run_key_unique');
                $table->index(['tenant_id', 'status', 'candidate_id'], 'ai_skill_cand_tenant_status_id');
            });
        }

        if (! Schema::hasTable('ai_skill_recommendations')) {
            Schema::create('ai_skill_recommendations', function (Blueprint $table): void {
                $table->unsignedBigInteger('recommendation_id')->primary();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('visible_actor_type', 20)->default('unknown');
                $table->unsignedBigInteger('visible_actor_id')->nullable();
                $table->json('context_ref')->nullable();
                $table->string('skill_key', 100)->nullable();
                $table->string('version', 50)->nullable();
                $table->unsignedBigInteger('candidate_id')->nullable();
                $table->unsignedInteger('position')->default(0);
                $table->timestamp('exposed_at')->nullable();
                $table->timestamp('adopted_at')->nullable();
                $table->timestamp('ignored_at')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->json('reason')->nullable();
                $table->timestamps();

                $table->index(
                    ['tenant_id', 'visible_actor_type', 'visible_actor_id', 'created_at', 'recommendation_id'],
                    'ai_skill_rec_tenant_actor_time_id'
                );
            });
        }
    }

    public function down(): void
    {
        if (MySqlOnlyMigration::skip(__FILE__)) {
            return;
        }

        Schema::dropIfExists('ai_skill_recommendations');
        Schema::dropIfExists('ai_skill_candidates');
        Schema::dropIfExists('ai_skill_training_runs');
        Schema::dropIfExists('ai_skill_experiences');
        Schema::dropIfExists('ai_skill_favorites');
        Schema::dropIfExists('ai_skill_definitions');
    }
};
