<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\AiSkill\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Modules\AiSkill\Contracts\ActorResolverContract;
use MultiTenantSaas\Modules\AiSkill\Contracts\ExperienceSourceAdapterContract;
use MultiTenantSaas\Modules\AiSkill\DTOs\AuthorizedDatasetSpec;
use MultiTenantSaas\Modules\AiSkill\DTOs\EvidenceBatch;
use MultiTenantSaas\Modules\AiSkill\DTOs\Outcome;
use MultiTenantSaas\Modules\AiSkill\Exceptions\SkillDomainException;
use MultiTenantSaas\Modules\AiSkill\Models\SkillExperience;

/**
 * 经验采样适配器（A 包）。
 *
 * 只经授权规格读取既有事实源（DB 表名），不 import 其他模块 Model/Service、不搬表。
 * agent_tool_logs 无 tenant 列：必须经 agent_conversations.tenant_id 关联过滤。
 * 主体不可靠 → actor_type=unknown + trust=low；禁止按 auth id/时间邻近补猜。
 * 输出为脱敏引用摘要，不复制整份生产日志。
 */
class ExperienceSourceAdapter implements ExperienceSourceAdapterContract
{
    public const SOURCE_AUDIT_LOGS = 'audit_logs';

    public const SOURCE_AGENT_TOOL_LOGS = 'agent_tool_logs';

    public const SOURCE_TASK_CHAIN_RUNS = 'task_chain_runs';

    public const SOURCE_AI_AUDIT_LOGS = 'ai_audit_logs';

    public const SOURCE_SKILL_EXPERIENCES = 'skill_experiences';

    /** @var list<string> */
    private const ALLOWED_SOURCES = [
        self::SOURCE_AUDIT_LOGS,
        self::SOURCE_AGENT_TOOL_LOGS,
        self::SOURCE_TASK_CHAIN_RUNS,
        self::SOURCE_AI_AUDIT_LOGS,
        self::SOURCE_SKILL_EXPERIENCES,
    ];

    private const MAX_SAMPLE_LIMIT = 500;

    public function __construct(
        private readonly EvidenceDesensitizer $desensitizer = new EvidenceDesensitizer,
        private readonly ?ActorResolverContract $actorResolver = null,
    ) {}

    public function sample(AuthorizedDatasetSpec $spec): EvidenceBatch
    {
        $this->assertSpec($spec);

        $items = [];
        $coverage = [];

        foreach ($spec->sourceTypes as $source) {
            $rows = match ($source) {
                self::SOURCE_AUDIT_LOGS => $this->sampleAuditLogs($spec),
                self::SOURCE_AGENT_TOOL_LOGS => $this->sampleAgentToolLogs($spec),
                self::SOURCE_TASK_CHAIN_RUNS => $this->sampleTaskChainRuns($spec),
                self::SOURCE_AI_AUDIT_LOGS => $this->sampleAiAuditLogs($spec),
                self::SOURCE_SKILL_EXPERIENCES => $this->sampleSkillExperiences($spec),
                default => throw new SkillDomainException('data_incomplete', "unsupported source: {$source}"),
            };
            $coverage[$source] = count($rows);
            foreach ($rows as $row) {
                $items[] = $row;
            }
        }

        return new EvidenceBatch(
            items: $items,
            coverage: $coverage,
            datasetManifestRef: $spec->promptFingerprint,
            trustFloor: 'low',
        );
    }

    private function assertSpec(AuthorizedDatasetSpec $spec): void
    {
        $actor = $this->actorResolver?->resolve();
        if ($actor === null || ($actor['actor_type'] ?? null) !== 'operator'
            || ($actor['actor_id'] ?? 0) <= 0 || ($actor['tenant_id'] ?? 0) <= 0
            || $spec->tenantId === null || ! ctype_digit($spec->tenantId)
            || (int) $spec->tenantId !== (int) $actor['tenant_id']) {
            throw new SkillDomainException('scope_denied', 'Authorized tenant context required.');
        }
        if (! in_array($spec->accessMode, ['metadata_only', 'inspect_body', 'transform_body'], true)) {
            throw new SkillDomainException('scope_denied', 'Invalid dataset access mode.');
        }
        try {
            $from = new \DateTimeImmutable($spec->timeFromIso);
            $to = new \DateTimeImmutable($spec->timeToIso);
        } catch (\Exception) {
            throw new SkillDomainException('data_incomplete', 'Invalid dataset time range.');
        }
        if ($from > $to) {
            throw new SkillDomainException('data_incomplete', 'Invalid dataset time range.');
        }
        if ($spec->sourceTypes === []) {
            throw new SkillDomainException('data_incomplete', 'empty sourceTypes');
        }
        foreach ($spec->sourceTypes as $source) {
            if (! in_array($source, self::ALLOWED_SOURCES, true)) {
                throw new SkillDomainException('scope_denied', "source not allowed: {$source}");
            }
        }
        if ($spec->accessMode === 'transform_body' && $spec->fieldAllowlist === []) {
            throw new SkillDomainException('scope_denied', 'transform_body requires field allowlist');
        }
        // metadata_only 不得读取业务正文字段
        if ($spec->accessMode === 'metadata_only' && $spec->fieldAllowlist !== []) {
            foreach ($spec->fieldAllowlist as $field) {
                if (in_array($field, ['input', 'output', 'content', 'old_values', 'new_values', 'detail'], true)) {
                    throw new SkillDomainException('scope_denied', "metadata_only cannot read {$field}");
                }
            }
        }
    }

    private function limitOf(AuthorizedDatasetSpec $spec): int
    {
        return min(self::MAX_SAMPLE_LIMIT, max(1, count($spec->sampleIds) ?: 50));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sampleAuditLogs(AuthorizedDatasetSpec $spec): array
    {
        if (! $this->tableExists('audit_logs')) {
            return [];
        }

        $q = DB::table('audit_logs')
            ->select(['log_id', 'tenant_id', 'user_id', 'action', 'resource_type', 'resource_id', 'created_at'])
            ->where('created_at', '>=', $spec->timeFromIso)
            ->where('created_at', '<=', $spec->timeToIso)
            ->orderBy('created_at')
            ->orderBy('log_id') // 同秒稳定：唯一键收尾
            ->limit($this->limitOf($spec));

        if ($spec->tenantId !== null) {
            $q->where('tenant_id', (int) $spec->tenantId);
        }
        if ($spec->sampleIds !== []) {
            $q->whereIn('log_id', array_map('intval', $spec->sampleIds));
        }

        $out = [];
        foreach ($q->get() as $row) {
            $out[] = $this->normalize([
                'source' => SkillExperience::SOURCE_MANUAL,
                'evidence_refs' => ['audit_logs.log_id' => (int) $row->log_id],
                'task_ref' => null,
                'run_ref' => null,
                'actor_type' => 'unknown',
                'actor_id' => $row->user_id !== null ? (int) $row->user_id : null,
                'goal' => $row->action,
                'outcome' => Outcome::UNCERTAIN,
                'occurred_at' => (string) $row->created_at,
                'trust' => $spec->actorType !== null ? 'low' : 'unknown',
                'attributes' => [
                    'resource_type' => $row->resource_type,
                    'resource_id' => $row->resource_id,
                ],
            ], $spec);
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sampleAgentToolLogs(AuthorizedDatasetSpec $spec): array
    {
        if (! $this->tableExists('agent_tool_logs') || ! $this->tableExists('agent_conversations')) {
            return [];
        }

        // 无 tenant 列：INNER JOIN 会话取租户；关联失败行自然落空，不补猜
        $q = DB::table('agent_tool_logs')
            ->join('agent_conversations', 'agent_conversations.conversation_id', '=', 'agent_tool_logs.conversation_id')
            ->select([
                'agent_tool_logs.log_id',
                'agent_tool_logs.conversation_id',
                'agent_tool_logs.agent_id',
                'agent_tool_logs.tool_name',
                'agent_tool_logs.duration_ms',
                'agent_tool_logs.status',
                'agent_tool_logs.created_at',
                'agent_conversations.tenant_id',
                'agent_conversations.user_id',
            ])
            ->where('agent_tool_logs.created_at', '>=', $spec->timeFromIso)
            ->where('agent_tool_logs.created_at', '<=', $spec->timeToIso)
            ->orderBy('agent_tool_logs.created_at')
            ->orderBy('agent_tool_logs.log_id')
            ->limit($this->limitOf($spec));

        if ($spec->tenantId !== null) {
            $q->where('agent_conversations.tenant_id', (int) $spec->tenantId);
        }
        if ($spec->sampleIds !== []) {
            $q->whereIn('agent_tool_logs.log_id', array_map('intval', $spec->sampleIds));
        }

        $out = [];
        foreach ($q->get() as $row) {
            $out[] = $this->normalize([
                'source' => SkillExperience::SOURCE_AGENT,
                'evidence_refs' => [
                    'agent_tool_logs.log_id' => (int) $row->log_id,
                    'agent_conversations.conversation_id' => (int) $row->conversation_id,
                ],
                'task_ref' => 'conv:' . $row->conversation_id,
                'run_ref' => null,
                // 工具表无独立主体类型证据 → 不把 user_id 直接标成 Operator
                'actor_type' => 'unknown',
                'actor_id' => $row->user_id !== null ? (int) $row->user_id : null,
                'goal' => $row->tool_name,
                'tool_ref' => $row->tool_name,
                // status=success 是工具输出状态，不等于业务目标达成
                'outcome' => Outcome::UNCERTAIN,
                'occurred_at' => (string) $row->created_at,
                'trust' => 'low',
                'attributes' => [
                    'tool_status' => $row->status,
                    'duration_ms' => (int) $row->duration_ms,
                    'agent_id' => (int) $row->agent_id,
                ],
            ], $spec);
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sampleTaskChainRuns(AuthorizedDatasetSpec $spec): array
    {
        if (! $this->tableExists('task_chain_runs')) {
            return [];
        }

        $q = DB::table('task_chain_runs')
            ->select(['run_id', 'tenant_id', 'conversation_id', 'chain_key', 'status', 'current_step', 'created_at', 'updated_at'])
            ->where('created_at', '>=', $spec->timeFromIso)
            ->where('created_at', '<=', $spec->timeToIso)
            ->orderBy('created_at')
            ->orderBy('run_id')
            ->limit($this->limitOf($spec));

        if ($spec->tenantId !== null) {
            $q->where('tenant_id', (int) $spec->tenantId);
        }
        if ($spec->sampleIds !== []) {
            $q->whereIn('run_id', array_map('intval', $spec->sampleIds));
        }

        $out = [];
        foreach ($q->get() as $row) {
            $out[] = $this->normalize([
                'source' => SkillExperience::SOURCE_SKILL,
                'evidence_refs' => ['task_chain_runs.run_id' => (int) $row->run_id],
                'task_ref' => $row->chain_key,
                'run_ref' => 'run:' . $row->run_id,
                'actor_type' => 'unknown',
                'actor_id' => null,
                'goal' => $row->chain_key,
                // legacy run 不伪造 skill 版本
                'skill_key' => null,
                'skill_version' => null,
                'outcome' => $this->mapChainStatus((string) $row->status),
                'occurred_at' => (string) $row->created_at,
                'trust' => 'low',
                'attributes' => [
                    'chain_status' => $row->status,
                    'current_step' => (int) $row->current_step,
                    'conversation_id' => $row->conversation_id !== null ? (int) $row->conversation_id : null,
                ],
            ], $spec);
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sampleAiAuditLogs(AuthorizedDatasetSpec $spec): array
    {
        if (! $this->tableExists('ai_audit_logs')) {
            return [];
        }

        $q = DB::table('ai_audit_logs')
            ->select(['audit_id', 'tenant_id', 'operator_id', 'agent_id', 'conversation_id', 'action', 'status', 'created_at'])
            ->where('created_at', '>=', $spec->timeFromIso)
            ->where('created_at', '<=', $spec->timeToIso)
            ->orderBy('created_at')
            ->orderBy('audit_id')
            ->limit($this->limitOf($spec));

        if ($spec->tenantId !== null) {
            $q->where('tenant_id', (int) $spec->tenantId);
        }
        if ($spec->sampleIds !== []) {
            $q->whereIn('audit_id', array_map('intval', $spec->sampleIds));
        }

        $out = [];
        foreach ($q->get() as $row) {
            $out[] = $this->normalize([
                'source' => SkillExperience::SOURCE_AGENT,
                'evidence_refs' => ['ai_audit_logs.audit_id' => (int) $row->audit_id],
                'task_ref' => $row->conversation_id !== null ? 'conv:' . $row->conversation_id : null,
                'run_ref' => null,
                // operator_id 不能独立证明 Operator 类型
                'actor_type' => 'unknown',
                'actor_id' => $row->operator_id !== null ? (int) $row->operator_id : null,
                'goal' => $row->action,
                'outcome' => Outcome::UNCERTAIN,
                'occurred_at' => (string) $row->created_at,
                'trust' => 'low',
                'attributes' => [
                    'action' => $row->action,
                    'tool_status' => $row->status,
                    'agent_id' => $row->agent_id !== null ? (int) $row->agent_id : null,
                ],
            ], $spec);
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sampleSkillExperiences(AuthorizedDatasetSpec $spec): array
    {
        $q = SkillExperience::query()
            ->where('occurred_at', '>=', $spec->timeFromIso)
            ->where('occurred_at', '<=', $spec->timeToIso)
            ->orderBy('occurred_at')
            ->orderBy((new SkillExperience)->getKeyName())
            ->limit($this->limitOf($spec));

        if ($spec->tenantId !== null) {
            $q->where('tenant_id', (int) $spec->tenantId);
        }
        if ($spec->sampleIds !== []) {
            $q->whereIn((new SkillExperience)->getKeyName(), array_map('intval', $spec->sampleIds));
        }

        $out = [];
        foreach ($q->get() as $row) {
            $out[] = $this->normalize([
                'source' => $row->source,
                'evidence_refs' => array_merge(
                    ['ai_skill_experiences.experience_id' => (int) $row->experience_id],
                    is_array($row->evidence_refs) ? $row->evidence_refs : [],
                ),
                'task_ref' => $row->task_ref,
                'run_ref' => $row->run_ref,
                'actor_type' => $row->actor_type,
                'actor_id' => $row->actor_id !== null ? (int) $row->actor_id : null,
                'goal' => $row->goal,
                'tool_ref' => $row->tool_ref,
                'skill_key' => $row->skill_key,
                'skill_version' => $row->skill_version,
                'outcome' => $row->outcome,
                'occurred_at' => $row->occurred_at?->toIso8601String(),
                'trust' => $row->trust,
                'attributes' => is_array($row->attributes) ? $row->attributes : [],
            ], $spec);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalize(array $row, AuthorizedDatasetSpec $spec): array
    {
        $allowed = $spec->fieldAllowlist;
        if ($allowed !== []) {
            $row = array_intersect_key($row, array_flip(array_merge(
                $allowed,
                ['source', 'evidence_refs', 'occurred_at', 'trust', 'outcome'],
            )));
        }

        if (isset($row['attributes']) && is_array($row['attributes'])) {
            $row['attributes'] = $this->desensitizer->mask($row['attributes']);
        }

        return $row;
    }

    private function mapChainStatus(string $status): string
    {
        return match ($status) {
            'completed' => Outcome::UNCERTAIN,
            'cancelled' => Outcome::CANCELLED,
            'failed' => Outcome::FAILED,
            default => Outcome::INCOMPLETE,
        };
    }

    /** 表存在性探测（兼容 Logging 可选安装；SQLite 影子 schema 同用） */
    private function tableExists(string $table): bool
    {
        return Schema::hasTable($table);
    }
}
