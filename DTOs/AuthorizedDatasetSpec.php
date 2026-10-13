<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\DTOs;

/**
 * 授权数据采样规格。仅通过授权身份/字段/时间范围取数；
 * 查询与采样由服务端完成，不给 LLM 任意 SQL；用户提示词不得扩大训练权限。
 */
final class AuthorizedDatasetSpec
{
    /**
     * @param  list<string>  $sourceTypes  audit_logs | agent_tool_logs | task_chain_runs | behavior_event | …
     * @param  list<string>  $fieldAllowlist
     * @param  list<string>  $sampleIds
     */
    public function __construct(
        public readonly array $sourceTypes,
        public readonly string $timeFromIso,
        public readonly string $timeToIso,
        public readonly array $fieldAllowlist = [],
        public readonly array $sampleIds = [],
        public readonly ?string $tenantId = null,
        public readonly ?string $actorType = null,
        public readonly ?string $actorId = null,
        /** metadata_only | inspect_body | transform_body */
        public readonly string $accessMode = 'metadata_only',
        public readonly ?string $promptFingerprint = null,
    ) {}
}
