<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 冻结评测样本（BL-144 独立评测入口 / WP-147b）。
 *
 * 判据必须独立于候选生成：样本与断言只由平台治理入口写入，训练器与候选正文都无法修改。
 * content_sha256 冻结 input+asserts，评测前后按摘要复核，防止「评测时改题」。
 */
class SkillEvalCase extends Model
{
    use BelongsToTenant, HasGlobalId;

    public const SPLIT_DISCOVERY = 'discovery';

    public const SPLIT_VALIDATION = 'validation';

    protected $table = 'ai_skill_eval_cases';

    protected $primaryKey = 'eval_case_id';

    protected $fillable = [
        'tenant_id',
        'dataset_id',
        'dataset_version',
        'split',
        'skill_key',
        'case_key',
        'input',
        'asserts',
        'rubric_version',
        'content_sha256',
        'created_by_operator_id',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'asserts' => 'array',
            'tenant_id' => 'integer',
            'created_by_operator_id' => 'integer',
            'eval_case_id' => 'integer',
        ];
    }

    /** 冻结摘要：键序归一，同一逻辑样本恒得同一摘要。 */
    public static function digestFor(array $input, array $asserts): string
    {
        return hash('sha256', (string) json_encode(
            self::canonical(['input' => $input, 'asserts' => $asserts]),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
    }

    private static function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map(self::canonical(...), $value);
        }

        return $value;
    }
}
