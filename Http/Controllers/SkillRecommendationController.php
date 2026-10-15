<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Http\Controllers;

use Illuminate\Http\Request;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\AiSkill\DTOs\RecommendationItem;
use MultiTenantSaas\Modules\AiSkill\Services\SkillRecommendationService;

/**
 * 推荐 HTTP（D 包 / BL-145）。推荐不授予执行权；事件分列记录。
 */
class SkillRecommendationController extends BaseController
{
    public function recommend(Request $request, SkillRecommendationService $recommendations)
    {
        $data = $request->validate([
            'purpose' => 'sometimes|nullable|string|max:64',
            'task_ref' => 'sometimes|nullable|string|max:64',
            'variant_key' => 'sometimes|nullable|string|max:50',
        ]);

        $list = $recommendations->recommend([
            'purpose' => $data['purpose'] ?? null,
            'task_ref' => $data['task_ref'] ?? null,
            'variant_key' => $data['variant_key'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'items' => array_map($this->item(...), $list->items),
                'meta' => $list->meta,
            ],
        ]);
    }

    public function index(Request $request, SkillRecommendationService $recommendations)
    {
        $data = $request->validate(['limit' => 'sometimes|integer|min:1|max:50']);
        $items = $recommendations->listOpen((int) ($data['limit'] ?? 20));

        return response()->json([
            'success' => true,
            'data' => array_map($this->item(...), $items),
        ]);
    }

    public function show(string $recommendationId, SkillRecommendationService $recommendations)
    {
        $this->assertId($recommendationId);

        return response()->json([
            'success' => true,
            'data' => $recommendations->describe((int) $recommendationId),
        ]);
    }

    public function event(Request $request, string $recommendationId, SkillRecommendationService $recommendations)
    {
        $this->assertId($recommendationId);
        $data = $request->validate([
            'event' => 'required|in:expose,click,adopt,ignore,execute,complete',
            'run_id' => 'sometimes|nullable|string|max:64',
            'outcome' => 'sometimes|nullable|string|max:20',
        ]);

        return response()->json([
            'success' => true,
            'data' => $recommendations->recordEvent((int) $recommendationId, (string) $data['event'], [
                'run_id' => $data['run_id'] ?? null,
                'outcome' => $data['outcome'] ?? null,
            ]),
        ]);
    }

    private function assertId(string $value): void
    {
        validator(['recommendation_id' => $value], ['recommendation_id' => 'required|integer|min:1'])->validate();
    }

    private function item(RecommendationItem $item): array
    {
        return [
            'recommendation_id' => (string) $item->recommendationId,
            'skill_key' => $item->skillKey,
            'version' => $item->skillVersion,
            'candidate_id' => $item->candidateId === null ? null : (string) $item->candidateId,
            'variant_key' => $item->variantKey,
            'context' => $item->context,
            'reason' => $item->reason,
            'position' => $item->position,
            'grants_execution' => false,
        ];
    }
}
