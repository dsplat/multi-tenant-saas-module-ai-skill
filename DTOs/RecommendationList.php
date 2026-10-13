<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\DTOs;

/**
 * 推荐列表。不得暴露调用方无权读取的对象、收藏内容或历史行为。
 */
final class RecommendationList
{
    /**
     * @param  list<RecommendationItem>  $items
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly array $items = [],
        public readonly array $meta = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return count($this->items);
    }
}
