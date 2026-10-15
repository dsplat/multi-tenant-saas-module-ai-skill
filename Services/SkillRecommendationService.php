<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Services;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Modules\AiSkill\Contracts\ActorResolverContract;
use MultiTenantSaas\Modules\AiSkill\Contracts\SkillFavoriteServiceContract;
use MultiTenantSaas\Modules\AiSkill\Contracts\SkillRecommendationServiceContract;
use MultiTenantSaas\Modules\AiSkill\DTOs\RecommendationItem;
use MultiTenantSaas\Modules\AiSkill\DTOs\RecommendationList;
use MultiTenantSaas\Modules\AiSkill\Exceptions\SkillDomainException;
use MultiTenantSaas\Modules\AiSkill\Models\SkillCandidate;
use MultiTenantSaas\Modules\AiSkill\Models\SkillRecommendation;
use MultiTenantSaas\Modules\AiSkill\Models\SkillRun;

/**
 * 推荐服务（D 包 / BL-145）。
 *
 * 结合适用性与结果事实排序，不以热度代替质量；推荐只是建议，不授予执行权。
 * 曝光/点击/采用/忽略/执行/完成分别记事件，点击 ≠ 成功。
 * 不暴露调用方无权读取的对象、收藏内容或历史行为。
 */
final class SkillRecommendationService implements SkillRecommendationServiceContract
{
    public const EVENT_EXPOSE = 'expose';

    public const EVENT_CLICK = 'click';

    public const EVENT_ADOPT = 'adopt';

    public const EVENT_IGNORE = 'ignore';

    public const EVENT_EXECUTE = 'execute';

    public const EVENT_COMPLETE = 'complete';

    public const EVENTS = [
        self::EVENT_EXPOSE,
        self::EVENT_CLICK,
        self::EVENT_ADOPT,
        self::EVENT_IGNORE,
        self::EVENT_EXECUTE,
        self::EVENT_COMPLETE,
    ];

    private const MAX_RECOMMENDATIONS = 20;

    public function __construct(
        private readonly ActorResolverContract $actors,
        private readonly SkillCatalog $catalog,
        private readonly SkillFavoriteServiceContract $favorites,
    ) {}

    /**
     * @param  array<string, mixed>  $authorizedContext  tenant/actor 已解析；可含 purpose/context_ref/variant_key
     */
    public function recommend(array $authorizedContext): RecommendationList
    {
        $actor = $this->actor();
        $tenantId = (int) $actor['tenant_id'];
        $actorId = (int) $actor['actor_id'];
        $contextRef = [
            'purpose' => $authorizedContext['purpose'] ?? null,
            'task_ref' => $authorizedContext['task_ref'] ?? null,
            'variant_key' => $authorizedContext['variant_key'] ?? null,
        ];

        return DB::transaction(function () use ($tenantId, $actorId, $contextRef, $authorizedContext): RecommendationList {
            // 清掉本主体/同上下文未终结的旧推荐，避免列表堆积陈旧项
            SkillRecommendation::query()->where('tenant_id', $tenantId)
                ->where('visible_actor_type', 'operator')->where('visible_actor_id', $actorId)
                ->whereNull('adopted_at')->whereNull('executed_at')->whereNull('completed_at')
                ->delete();

            $scored = $this->scoreCandidates($tenantId, $actorId, $authorizedContext);
            $items = [];
            foreach (array_slice($scored, 0, self::MAX_RECOMMENDATIONS) as $position => $row) {
                $rec = SkillRecommendation::create([
                    'tenant_id' => $tenantId,
                    'visible_actor_type' => 'operator',
                    'visible_actor_id' => $actorId,
                    'context_ref' => $contextRef,
                    'skill_key' => $row['skill_key'],
                    'version' => $row['version'],
                    'candidate_id' => $row['candidate_id'],
                    'position' => $position,
                    'reason' => $row['reason'],
                ]);
                $items[] = new RecommendationItem(
                    recommendationId: (int) $rec->getKey(),
                    skillKey: (string) $rec->skill_key,
                    skillVersion: (string) $rec->version,
                    candidateId: $rec->candidate_id !== null ? (int) $rec->candidate_id : null,
                    variantKey: $row['variant_key'],
                    context: $contextRef,
                    reason: (array) $rec->reason,
                    position: $position,
                );
            }

            return new RecommendationList($items, [
                'count' => count($items),
                'purpose' => $contextRef['purpose'],
                // 推荐不授予执行权
                'grants_execution' => false,
            ]);
        }, 3);
    }

    /**
     * @return list<RecommendationItem>
     */
    public function listOpen(int $limit = 20): array
    {
        $actor = $this->actor();
        $limit = min(50, max(1, $limit));
        $rows = SkillRecommendation::query()
            ->where('tenant_id', (int) $actor['tenant_id'])
            ->where('visible_actor_type', 'operator')
            ->where('visible_actor_id', (int) $actor['actor_id'])
            ->orderBy('position')
            ->orderByDesc((new SkillRecommendation)->getKeyName())
            ->limit($limit)->get();

        return $rows->map(function (SkillRecommendation $rec): RecommendationItem {
            return new RecommendationItem(
                recommendationId: (int) $rec->getKey(),
                skillKey: (string) $rec->skill_key,
                skillVersion: (string) $rec->version,
                candidateId: $rec->candidate_id !== null ? (int) $rec->candidate_id : null,
                variantKey: (is_array($rec->context_ref) ? ($rec->context_ref['variant_key'] ?? null) : null),
                context: (array) $rec->context_ref,
                reason: (array) $rec->reason,
                position: (int) $rec->position,
            );
        })->all();
    }

    /**
     * 记录推荐事件。曝光/点击/采用/执行/完成分列；已发生事件不回滚。
     *
     * @return array<string, mixed>
     */
    public function recordEvent(int $recommendationId, string $event, array $payload = []): array
    {
        if (! in_array($event, self::EVENTS, true)) {
            throw new SkillDomainException('data_incomplete', 'Unknown recommendation event.');
        }

        return DB::transaction(function () use ($recommendationId, $event, $payload): array {
            $actor = $this->actor();
            $rec = SkillRecommendation::query()->whereKey($recommendationId)
                ->where('tenant_id', (int) $actor['tenant_id'])
                ->where('visible_actor_type', 'operator')
                ->where('visible_actor_id', (int) $actor['actor_id'])
                ->lockForUpdate()->first();
            if ($rec === null) {
                throw new SkillDomainException('scope_denied', 'Recommendation not found.');
            }

            if (in_array($event, [self::EVENT_EXECUTE, self::EVENT_COMPLETE], true)) {
                $runId = $payload['run_id'] ?? ($rec->reason['last_run_id'] ?? null);
                $run = SkillRun::whereKey($runId)
                    ->where('tenant_id', $actor['tenant_id'])->where('operator_id', $actor['actor_id'])
                    ->where('skill_key', $rec->skill_key)->where('skill_version', $rec->version)->first();
                if ($run === null || $run->task_chain_run_id === null
                    || ($event === self::EVENT_COMPLETE && $run->status !== 'completed')) {
                    throw new SkillDomainException('verification_missing', 'A matching trusted run is required.');
                }
                $payload['run_id'] = (string) $run->getKey();
                $payload['outcome'] = $run->outcome;
            }
            $now = now();
            switch ($event) {
                case self::EVENT_EXPOSE:
                    $rec->exposed_at = $rec->exposed_at ?? $now;
                    break;
                case self::EVENT_CLICK:
                    $rec->clicked_at = $rec->clicked_at ?? $now;
                    break;
                case self::EVENT_ADOPT:
                    // 采用 ≠ 执行完成；仍需本次授权
                    $rec->adopted_at = $rec->adopted_at ?? $now;
                    $rec->ignored_at = null;
                    break;
                case self::EVENT_IGNORE:
                    if ($rec->adopted_at === null && $rec->executed_at === null) {
                        $rec->ignored_at = $rec->ignored_at ?? $now;
                    }
                    break;
                case self::EVENT_EXECUTE:
                    $rec->executed_at = $rec->executed_at ?? $now;
                    if (isset($payload['run_id'])) {
                        $reason = (array) $rec->reason;
                        $reason['last_run_id'] = (string) $payload['run_id'];
                        $rec->reason = $reason;
                    }
                    break;
                case self::EVENT_COMPLETE:
                    $rec->completed_at = $rec->completed_at ?? $now;
                    if (isset($payload['outcome'])) {
                        $reason = (array) $rec->reason;
                        $reason['last_outcome'] = (string) $payload['outcome'];
                        $rec->reason = $reason;
                    }
                    break;
            }
            $rec->save();

            return $this->eventPayload($rec, $event);
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(int $recommendationId): array
    {
        $actor = $this->actor();
        $rec = SkillRecommendation::query()->whereKey($recommendationId)
            ->where('tenant_id', (int) $actor['tenant_id'])
            ->where('visible_actor_type', 'operator')
            ->where('visible_actor_id', (int) $actor['actor_id'])->first();
        if ($rec === null) {
            throw new SkillDomainException('scope_denied', 'Recommendation not found.');
        }

        return $this->eventPayload($rec, null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scoreCandidates(int $tenantId, int $actorId, array $context): array
    {
        $scored = [];
        $seen = [];

        // 1) 验证/已采用候选：优先（有评测证据）；已采用与低风险只读排前
        $candidates = SkillCandidate::query()->where('tenant_id', $tenantId)
            ->whereIn('status', [SkillCandidate::STATUS_VALIDATED, SkillCandidate::STATUS_ADOPTED])
            ->orderByDesc((new SkillCandidate)->getKeyName())
            ->limit(10)->get();
        foreach ($candidates as $candidate) {
            $draft = (array) $candidate->skill_draft;
            $key = (string) ($draft['skill_key'] ?? $candidate->candidate_key);
            $version = (string) ($draft['version'] ?? '0.0.0');
            $id = $key . '@' . $version;
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $businessWrite = (bool) ($draft['risk']['business_write'] ?? false);
            $score = match (true) {
                $candidate->status === SkillCandidate::STATUS_ADOPTED && ! $businessWrite => 120,
                ! $businessWrite => 100,
                default => 70,
            };
            $scored[] = [
                'skill_key' => $key,
                'version' => $version,
                'candidate_id' => (int) $candidate->getKey(),
                'variant_key' => $draft['variant_key'] ?? $context['variant_key'] ?? null,
                'score' => $score,
                'reason' => [
                    'source' => 'validated_candidate',
                    'candidate_status' => (string) $candidate->status,
                    'risk_level' => $draft['risk']['level'] ?? null,
                    'business_write' => $businessWrite,
                ],
            ];
        }

        // 2) 可用收藏（固定版本，不追随最新版）
        foreach ($this->favorites->list() as $favorite) {
            if (($favorite['reference_status'] ?? '') !== 'available') {
                continue;
            }
            $id = $favorite['skill_key'] . '@' . $favorite['version'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $scored[] = [
                'skill_key' => (string) $favorite['skill_key'],
                'version' => (string) $favorite['version'],
                'candidate_id' => null,
                'variant_key' => $context['variant_key'] ?? null,
                'score' => 80,
                'reason' => [
                    'source' => 'favorite',
                    'scope' => $favorite['scope'] ?? null,
                    'grants_execution' => false,
                ],
            ];
        }

        // 3) 可见已发布目录（有界；metadata-only）
        foreach ($this->catalog->list(self::MAX_RECOMMENDATIONS) as $item) {
            if (($item['content_status'] ?? '') !== 'ready') {
                continue;
            }
            $id = $item['skill_key'] . '@' . $item['version'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $scored[] = [
                'skill_key' => (string) $item['skill_key'],
                'version' => (string) $item['version'],
                'candidate_id' => null,
                'variant_key' => $context['variant_key'] ?? null,
                'score' => 50,
                'reason' => [
                    'source' => 'catalog',
                    'execution' => $item['execution'] ?? null,
                    'risk' => $item['risk'] ?? null,
                ],
            ];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $scored;
    }

    private function actor(): array
    {
        $actor = $this->actors->resolve();
        if (($actor['actor_type'] ?? null) !== 'operator' || (int) ($actor['actor_id'] ?? 0) <= 0
            || (int) ($actor['tenant_id'] ?? 0) <= 0) {
            throw new SkillDomainException('scope_denied');
        }

        return $actor;
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(SkillRecommendation $rec, ?string $event): array
    {
        return [
            'recommendation_id' => (string) $rec->getKey(),
            'skill_key' => (string) $rec->skill_key,
            'version' => (string) $rec->version,
            'candidate_id' => $rec->candidate_id === null ? null : (string) $rec->candidate_id,
            'position' => (int) $rec->position,
            'reason' => (array) $rec->reason,
            'events' => [
                'exposed_at' => $rec->exposed_at?->toISOString(),
                'clicked_at' => $rec->clicked_at?->toISOString(),
                'adopted_at' => $rec->adopted_at?->toISOString(),
                'ignored_at' => $rec->ignored_at?->toISOString(),
                'executed_at' => $rec->executed_at?->toISOString(),
                'completed_at' => $rec->completed_at?->toISOString(),
            ],
            'last_event' => $event,
            // 采用/执行仍需本次授权；完成才是成功事实
            'grants_execution' => false,
        ];
    }
}
