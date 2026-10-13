<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\DTOs;

/**
 * 推荐项。只是建议，不授予执行权；曝光/采用/忽略/执行完成分开记录。
 */
final class RecommendationItem
{
    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $reason
     */
    public function __construct(
        public readonly int $recommendationId,
        public readonly string $skillKey,
        public readonly string $skillVersion,
        public readonly ?int $candidateId = null,
        public readonly ?string $variantKey = null,
        public readonly array $context = [],
        public readonly array $reason = [],
        public readonly int $position = 0,
    ) {}
}
