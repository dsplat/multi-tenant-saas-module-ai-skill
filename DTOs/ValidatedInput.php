<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\DTOs;

/**
 * 已校验输入。tenant/actor 由服务端 resolver 注入，模型/客户端不得覆盖。
 */
final class ValidatedInput
{
    /**
     * @param  array<string, mixed>  $params  符合 SkillDefinition.input_schema 的参数
     * @param  array<string, mixed>  $serverResolved  服务端解析结果（tenant/actor/policy 版本等）
     */
    public function __construct(
        public readonly array $params,
        public readonly array $serverResolved = [],
        public readonly string $schemaVersion = '1',
    ) {}
}
