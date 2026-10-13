<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\DTOs;

/**
 * 经验采样批。存脱敏引用，不复制整份生产日志；无可靠关联保持 unknown/低可信。
 */
final class EvidenceBatch
{
    /**
     * @param  list<array<string, mixed>>  $items  规范化经验摘要（含 evidence_refs）
     * @param  array<string, int>  $coverage
     */
    public function __construct(
        public readonly array $items,
        public readonly array $coverage = [],
        public readonly ?string $datasetManifestRef = null,
        public readonly string $trustFloor = 'unknown',
    ) {}

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
