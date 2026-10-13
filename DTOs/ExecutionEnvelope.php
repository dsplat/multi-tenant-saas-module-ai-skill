<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\DTOs;

/**
 * 执行信封（trace 扩展位）。业务 ID 与跨系统 trace ID 不同字段；无来源 trace 留空，不伪造。
 */
final class ExecutionEnvelope
{
    public const SCHEMA_VERSION = 1;

    /** attributes key 白名单上限与单值序列化上限（集成者可收紧，不可放宽到任意 Prompt/PII） */
    public const MAX_ATTRIBUTES = 32;

    public const MAX_ATTRIBUTE_BYTES = 4096;

    /**
     * @param  array<string, mixed>  $attributes  受限扩展位（key 白名单、脱敏）
     * @param  array<string, mixed>  $sourceRefs  源表主键引用
     */
    public function __construct(
        public readonly ?string $traceId = null,
        public readonly ?string $spanId = null,
        public readonly ?string $parentSpanId = null,
        public readonly ?int $runId = null,
        public readonly ?int $stepIndex = null,
        public readonly ?string $requestRef = null,
        public readonly array $sourceRefs = [],
        public readonly array $attributes = [],
        public readonly int $schemaVersion = self::SCHEMA_VERSION,
    ) {}

    public function withAttribute(string $key, mixed $value): self
    {
        if (count($this->attributes) >= self::MAX_ATTRIBUTES) {
            return $this;
        }
        $attrs = $this->attributes;
        $attrs[$key] = $value;

        return new self(
            traceId: $this->traceId,
            spanId: $this->spanId,
            parentSpanId: $this->parentSpanId,
            runId: $this->runId,
            stepIndex: $this->stepIndex,
            requestRef: $this->requestRef,
            sourceRefs: $this->sourceRefs,
            attributes: $attrs,
            schemaVersion: $this->schemaVersion,
        );
    }
}
