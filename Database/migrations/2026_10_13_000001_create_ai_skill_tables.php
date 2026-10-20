<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Support\MySqlOnlyMigration;

/**
 * 2026_10_13_000001_create_ai_skill_tables 合并基线（2026-10-20 归并）
 *
 * 本文件把 2026_10_13_000001_create_ai_skill_tables 及其后续增量按原执行顺序内联到 steps()；每块保留原
 * up()/down()、私有方法与驱动守卫。结构基线在存量库已记录，故存量库整体跳过；全新
 * 安装按 steps() 顺序一次建齐。后续迁移仍以独立文件随发布包下发，并在下个发布线并入本基线。
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->steps() as $step) {
            $step->up();
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->steps()) as $step) {
            $step->down();
        }
    }

    /**
     * 历史迁移块（原文件名 => 迁移），顺序即原执行顺序。
     *
     * @return array<string, Migration>
     */
    private function steps(): array
    {
        return [
            // src/Modules/AiSkill/Database/migrations/2026_10_13_000001_create_ai_skill_tables.php
            /**
             * AiSkill 最小持久对象（A 包 / BL-141＋142）。
             *
             * 表名与索引见 docs/decisions/2026-10-13-aiskill-schema-mapping.md（冻结候选）。
             * 主键全局 ID，列名取领域名（禁止 id）；截断排序必须唯一键收尾。
             * 生产绑定/ServiceProvider 由集成者接线，本迁移仅建表。
             */
            '2026_10_13_000001_create_ai_skill_tables' => new class extends Migration
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
                            $table->unsignedBigInteger('scope_id')->default(0);
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

                            $table->unique(['tenant_id', 'scope', 'operator_id', 'skill_key', 'version'], 'ai_skill_fav_tenant_op_key_ver_unique');
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
            },
            // src/Modules/AiSkill/Database/migrations/2026_10_13_000002_add_skill_content_snapshots.php
            /** Runtime Skill content is authoritative in the database; legacy references are not backfilled implicitly. */
            '2026_10_13_000002_add_skill_content_snapshots' => new class extends Migration
            {
                public function up(): void
                {
                    if (! Schema::hasTable('ai_skill_definitions')) {
                        return;
                    }
                    if (! Schema::hasColumn('ai_skill_definitions', 'content_body')) {
                        Schema::table('ai_skill_definitions', fn (Blueprint $table) => $table->longText('content_body')->nullable());
                    }
                    if (! Schema::hasColumn('ai_skill_definitions', 'content_sha256')) {
                        Schema::table('ai_skill_definitions', fn (Blueprint $table) => $table->string('content_sha256', 64)->nullable());
                    }
                }

                public function down(): void
                {
                    if (! Schema::hasTable('ai_skill_definitions')) {
                        return;
                    }
                    foreach (['content_body', 'content_sha256'] as $column) {
                        if (Schema::hasColumn('ai_skill_definitions', $column)) {
                            Schema::table('ai_skill_definitions', fn (Blueprint $table) => $table->dropColumn($column));
                        }
                    }
                }
            },
            // src/Modules/AiSkill/Database/migrations/2026_10_14_000001_create_ai_skill_runs.php
            /**
             * Skill 固定执行运行表（C 包 / BL-143）。
             *
             * start 将数据库快照（skill_key/version/content_sha256/方法/输入）固定到本行；
             * advance/cancel 以 revision 乐观锁；request_key 幂等（同键同指纹重放，同键异指纹 409）。
             * 执行状态机在本表；结构化链推进可挂 task_chain_runs.run_id，不复制执行器。
             */
            '2026_10_14_000001_create_ai_skill_runs' => new class extends Migration
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
            },
            // src/Modules/AiSkill/Database/migrations/2026_10_14_000002_add_training_request_key.php
            /**
             * 训练任务幂等键（D 包 / BL-145+146 后端）。
             * enqueue 必须携带 RequestKey：同键同指纹重放，同键异指纹 409。
             * 不改冻结表名/既有索引，仅追加可空列（与 B 包正文快照同模式）。
             */
            '2026_10_14_000002_add_training_request_key' => new class extends Migration
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
            },
            // src/Modules/AiSkill/Database/migrations/2026_10_14_000003_add_recommendation_event_timestamps.php
            /**
             * 推荐事件时间戳补全（D 包）：曝光/点击/采用/忽略/执行/完成分列，点击 ≠ 成功。
             * 仅追加可空列，不改冻结表名/既有索引。
             */
            '2026_10_14_000003_add_recommendation_event_timestamps' => new class extends Migration
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
            },
            // src/Modules/AiSkill/Database/migrations/2026_10_16_000001_add_skill_training_llm_facts.php
            /**
             * 异步训练事实列（WP-147a / BL-144+147）。
             *
             * 训练任务从「只登记 queued」变为可被队列处理器真实执行：必须留下调用与用量的事实，
             * 否则效果门无法区分「模型跑过」与「状态流转过」。
             * cost_micro 仍为 null=unknown（无价格来源时不按 0 记）；cost_source 标明事实来源。
             * 仅追加可空列，不改冻结表名与既有索引（与 D 包 request_key 同模式）。
             */
            '2026_10_16_000001_add_skill_training_llm_facts' => new class extends Migration
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
            },
            // src/Modules/AiSkill/Database/migrations/2026_10_16_000002_create_ai_skill_eval_cases.php
            /**
             * 冻结评测样本表（WP-147b / BL-144 独立评测入口）。
             *
             * 判据必须独立于候选生成：样本与断言由平台治理入口写入并冻结（content_sha256），
             * 训练处理器与候选正文都无法修改。validation 分集不参与候选抽取，只用于采用门。
             * 主键走 IdGenerator 全局 ID；截断查询以唯一主键收尾。
             */
            '2026_10_16_000002_create_ai_skill_eval_cases' => new class extends Migration
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
            },
        ];
    }
};
