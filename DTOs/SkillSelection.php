<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\DTOs;

/**
 * Skill 选择：固定到 skill_key + version（或受控不可变引用）。
 *
 * 运行定义在 start 时完整固定；不接受模型在执行中改版本。
 */
final class SkillSelection
{
    public function __construct(
        public readonly string $skillKey,
        public readonly string $version,
        /** overview_first | exceptions_first | …；由 SkillDefinition 声明合法值 */
        public readonly ?string $variantKey = null,
        /** 受控不可变定义引用（模块种子 hash / 已发布快照 id）；与 key+version 至少一者可解析 */
        public readonly ?string $definitionRef = null,
        /** @var array<string, mixed> */
        public readonly array $options = [],
    ) {}
}
