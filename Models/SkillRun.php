<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * Skill 固定执行运行（C 包）。
 *
 * start 固定 skill 版本与正文摘要；advance/cancel 以 revision 乐观锁；
 * request_key 幂等。恢复使用本表 plan，不重新读目录或改取最新版。
 */
class SkillRun extends Model
{
    use BelongsToTenant;
    use HasGlobalId;
    use SerializesFriendlyDates;

    public const STATUS_RUNNING = 'running';

    public const STATUS_WAITING_INPUT = 'waiting_input';

    public const STATUS_WAITING_CONFIRM = 'waiting_confirm';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_UNCERTAIN = 'uncertain';

    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_INCOMPLETE = 'incomplete';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_CANCELLED = 'cancelled';

    public const OUTCOME_UNCERTAIN = 'uncertain';

    protected $table = 'ai_skill_runs';

    protected $primaryKey = 'skill_run_id';

    protected $fillable = [
        'tenant_id',
        'operator_id',
        'skill_key',
        'skill_version',
        'skill_definition_id',
        'content_sha256',
        'execution',
        'variant_key',
        'input_params',
        'server_resolved',
        'plan',
        'request_key',
        'input_fingerprint',
        'revision',
        'current_step',
        'status',
        'outcome',
        'reason',
        'warnings',
        'result_refs',
        'task_chain_run_id',
    ];

    protected function casts(): array
    {
        return [
            'input_params' => 'array',
            'server_resolved' => 'array',
            'plan' => 'array',
            'warnings' => 'array',
            'result_refs' => 'array',
            'revision' => 'integer',
            'current_step' => 'integer',
            'operator_id' => 'integer',
            'tenant_id' => 'integer',
            'skill_definition_id' => 'integer',
            'task_chain_run_id' => 'integer',
        ];
    }

    /**
     * 主键字符串序列化（全局 ID 可能超出 JS 安全整数）。
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        $key = $this->getKeyName();
        if (array_key_exists($key, $array) && $array[$key] !== null) {
            $array[$key] = (string) $array[$key];
        }

        return $array;
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
            self::STATUS_UNCERTAIN,
        ], true);
    }

    public function isTerminalOutcome(): bool
    {
        return $this->outcome !== null;
    }
}
