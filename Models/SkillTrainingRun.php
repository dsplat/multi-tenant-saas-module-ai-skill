<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 训练运行。dataset_manifest 冻结数据范围；模型训练完成 ≠ 候选可采用。
 * cost_micro 为 null 表示 unknown，不按 0 计算。
 */
class SkillTrainingRun extends Model
{
    use BelongsToTenant, HasGlobalId;

    public const MODE_MODULE_DOCS = 'module_docs';

    public const MODE_HISTORY = 'history';

    public const MODE_RECOMMEND_OPTIMIZE = 'recommend_optimize';

    public const MODE_FAILURE_DIAGNOSIS = 'failure_diagnosis';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    public const STATUS_COMPLETED = 'completed';

    protected $table = 'ai_skill_training_runs';

    protected $primaryKey = 'training_run_id';

    protected $fillable = [
        'tenant_id',
        'actor_type',
        'actor_id',
        'mode',
        'dataset_manifest',
        'config_fingerprint',
        'prompt_fingerprint',
        'model_ref',
        'budget',
        'cost_micro',
        'status',
        'error',
        'request_key',
        'input_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'dataset_manifest' => 'array',
            'budget' => 'array',
            // 训练事实只读：由处理器/作业写入，不进入批量赋值白名单
            'usage' => 'array',
            'llm_calls' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cost_micro' => 'integer',
            'actor_id' => 'integer',
            'tenant_id' => 'integer',
            'training_run_id' => 'integer',
        ];
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_CANCELLED,
            self::STATUS_FAILED,
            self::STATUS_COMPLETED,
        ], true);
    }

    public function costIsUnknown(): bool
    {
        return $this->cost_micro === null;
    }
}
