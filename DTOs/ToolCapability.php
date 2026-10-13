<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\DTOs;

/**
 * 工具执行能力摘要。由服务端工具元数据确定，模型不得自行声明。
 * 标签是约束依据之一，不是安全证明。缺失一律 unknown。
 */
final class ToolCapability
{
    public const READ = 'read';

    public const WRITE = 'write';

    public const EXTERNAL_NETWORK = 'external_network';

    public const CODE_EXECUTION = 'code_execution';

    public const UNKNOWN = 'unknown';

    /**
     * @param  list<string>  $capabilities
     */
    public function __construct(
        public readonly string $toolSlug,
        public readonly array $capabilities = [self::UNKNOWN],
        /** sandbox_policy 未配置时，code_execution / 任意网络能力必须拒绝 */
        public readonly ?string $sandboxPolicyRef = null,
    ) {}

    public function isReadOnlyVerified(): bool
    {
        return $this->capabilities === [self::READ];
    }

    public function requiresSandbox(): bool
    {
        foreach ($this->capabilities as $cap) {
            if (in_array($cap, [self::CODE_EXECUTION, self::EXTERNAL_NETWORK], true)) {
                return true;
            }
        }

        return false;
    }

    public function allowsWithoutSandbox(): bool
    {
        return ! $this->requiresSandbox() && $this->sandboxPolicyRef === null;
    }
}
