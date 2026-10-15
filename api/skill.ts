import { http, type ApiResponse } from '@multi-tenant-saas/console/shared/http'

/**
 * AiSkill Console typed client（B 契约：/api/v1/tenant/ai-skills）
 *
 * 约定：
 * - 成功体 `{ success: true, data }`；DELETE 成功仅 `{ success: true }`
 * - 错误体 `{ success: false, message, reason? }`，status 401/402/403/409/422
 * - ID 一律视为不透明引用（string | number），禁止 Number 强转参与运算
 * - 收藏固定 skill_key/version，不追随最新版；allowed_defaults 仅 schema 显式 enum
 */

/** 不透明 ID：服务端以字符串序列化（全局 ID 可能超出 JS 安全整数），禁止 Number 强转 */
export type IdRef = string

export type SkillScope = 'module' | 'project' | 'tenant'
export type SkillExecution = 'chain' | 'instructions' | 'mixed'
export type SkillStatus = 'draft' | 'published' | 'disabled'
export type ContentStatus = 'ready' | 'missing_or_corrupt'
export type FavoriteScope = 'personal' | 'tenant'
export type ReferenceStatus = 'available' | 'unavailable'

export type SkillReasonCode =
  | 'cohort_unresolved'
  | 'scope_denied'
  | 'data_incomplete'
  | 'skill_not_applicable'
  | 'version_conflict'
  | 'tool_incompatible'
  | 'budget_exceeded'
  | 'verification_missing'
  | 'review_required'
  | 'insufficient_evidence'

export interface SkillCatalogItem {
  skill_definition_id: IdRef
  skill_key: string
  version: string
  scope_type: SkillScope
  scope_id: number
  owner_type?: string | null
  owner_id?: number | null
  execution: SkillExecution
  title?: string | null
  description?: string | null
  input_schema?: Record<string, unknown> | null
  applicability?: unknown[] | Record<string, unknown> | null
  tool_deps?: unknown[] | null
  risk?: Record<string, unknown> | null
  success_criteria?: unknown[] | Record<string, unknown> | null
  content_ref?: string | null
  content_sha256?: string | null
  status: SkillStatus
  created_at?: string
  updated_at?: string
  /** 仅目录/详情附加；metadata-only 列表不得回 content_body */
  content_status?: ContentStatus
}

export interface SkillDefinitionDetail extends SkillCatalogItem {
  content_body: string
}

export interface SkillFavorite {
  skill_favorite_id: IdRef
  tenant_id: number
  operator_id: number
  scope: FavoriteScope
  skill_key: string
  version: string
  allowed_defaults?: Record<string, string | number | boolean> | null
  label?: string | null
  created_at?: string
  updated_at?: string
  /** list() 附加 */
  reference_status?: ReferenceStatus
  reason?: SkillReasonCode | string | null
}

export interface SaveFavoriteInput {
  skill_key: string
  version: string
  definition_ref?: string
  scope: FavoriteScope
  allowed_defaults?: Record<string, string | number | boolean>
}

export interface ChangeStatusInput {
  expected_status: 'published' | 'disabled'
  status: 'published' | 'disabled'
}

export class SkillApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly reason: SkillReasonCode | null,
    message: string,
  ) {
    super(message)
    this.name = 'SkillApiError'
  }
}

const REASON_LABELS: Record<string, string> = {
  scope_denied: '没有权限执行该操作',
  version_conflict: '版本或状态已变更，请刷新后重试',
  budget_exceeded: '预算不足',
  cohort_unresolved: '无法解析营期上下文',
  data_incomplete: '数据不完整或已损坏',
  skill_not_applicable: '当前条件不适用该技能',
  tool_incompatible: '工具依赖不兼容',
  verification_missing: '内容校验缺失或损坏',
  review_required: '需要人工审核',
  insufficient_evidence: '证据不足',
}

/** 将 HTTP 错误统一为 SkillApiError；屏蔽 SQL/路径/堆栈，保留稳定 reason */
export function toSkillApiError(error: unknown): SkillApiError {
  const anyErr = error as {
    response?: { status?: number; data?: { message?: string; reason?: string } }
    message?: string
  }
  const status = anyErr?.response?.status ?? 0
  const rawReason = anyErr?.response?.data?.reason
  const reason = (rawReason && rawReason in REASON_LABELS ? rawReason : null) as SkillReasonCode | null
  // 401/402/403/409 用稳定用户提示覆盖服务端英文/原因码文案，避免泄漏内部措辞
  const statusHint =
    status === 401
      ? '登录已失效，请重新登录'
      : status === 403
        ? REASON_LABELS.scope_denied
        : status === 402
          ? REASON_LABELS.budget_exceeded
          : status === 409
            ? REASON_LABELS.version_conflict
            : null
  const message =
    (reason && REASON_LABELS[reason]) ||
    statusHint ||
    (status === 422 ? '输入无效，请检查参数' : null) ||
    '请求失败，请稍后重试'
  return new SkillApiError(status, reason, message)
}

function unwrap<T>(res: ApiResponse<T>): T {
  const body = res as unknown as { success?: boolean; data?: T }
  if (body && body.success === true) {
    return body.data as T
  }
  // DELETE 成功仅 { success: true }；其余形态按契约缺口处理
  return body as unknown as T
}

async function call<T>(fn: () => Promise<ApiResponse<T>>): Promise<T> {
  try {
    return unwrap(await fn())
  } catch (error) {
    throw toSkillApiError(error)
  }
}

const BASE = '/tenant/ai-skills'

export function listSkills(limit = 50): Promise<SkillCatalogItem[]> {
  const raw = Number.isFinite(limit) ? Math.floor(limit) : 50
  const bounded = Math.min(100, Math.max(1, raw))
  return call(() => http.get<SkillCatalogItem[]>(BASE, { params: { limit: bounded } }))
}

export function getSkillDefinition(skillKey: string, version: string): Promise<SkillDefinitionDetail> {
  const key = encodeURIComponent(skillKey)
  const ver = encodeURIComponent(version)
  return call(() => http.get<SkillDefinitionDetail>(`${BASE}/${key}/versions/${ver}`))
}

export function listFavorites(): Promise<SkillFavorite[]> {
  return call(() => http.get<SkillFavorite[]>(`${BASE}/favorites`))
}

export function saveFavorite(input: SaveFavoriteInput): Promise<SkillFavorite> {
  return call(() => http.post<SkillFavorite>(`${BASE}/favorites`, input))
}

export async function removeFavorite(favoriteId: IdRef): Promise<void> {
  await call(async () => {
    const res = await http.delete<unknown>(`${BASE}/favorites/${encodeURIComponent(String(favoriteId))}`)
    return res
  })
}

export function changeDefinitionStatus(
  definitionId: IdRef,
  input: ChangeStatusInput,
): Promise<SkillCatalogItem> {
  return call(() =>
    http.patch<SkillCatalogItem>(
      `${BASE}/definitions/${encodeURIComponent(String(definitionId))}/status`,
      input,
    ),
  )
}

// ========== C 固定执行（BL-143） ==========

export type SkillRunStatus =
  | 'running'
  | 'waiting_input'
  | 'waiting_confirm'
  | 'completed'
  | 'failed'
  | 'cancelled'
  | 'uncertain'

export type SkillRunOutcome = 'success' | 'incomplete' | 'failed' | 'cancelled' | 'uncertain'

export type SkillRunStepType = 'input' | 'tool' | 'delegate' | 'verify' | 'upload'

export interface SkillRunStep {
  name: string
  type: SkillRunStepType | string
  status: string
  output_key?: string | null
}

export interface SkillRunView {
  skill_run_id: IdRef
  skill_key: string
  skill_version: string
  revision: number
  status: SkillRunStatus | string
  current_step: number
  steps: SkillRunStep[]
  context: {
    variant_key?: string | null
    execution?: SkillExecution | string
    content_sha256?: string | null
    input?: Record<string, unknown>
    result_refs?: Record<string, unknown>
  }
  outcome?: SkillRunOutcome | string | null
  reason?: SkillReasonCode | string | null
  next_action?: string | null
  warnings?: Array<{ code?: string; step?: number; message?: string }>
}

export interface StartRunInput {
  skill_key: string
  version: string
  variant_key?: string | null
  definition_ref?: string | null
  input?: Record<string, unknown>
  options?: Record<string, unknown>
  request_key: string
  input_fingerprint?: string | null
}

export function startRun(input: StartRunInput): Promise<SkillRunView> {
  return call(() => http.post<SkillRunView>(`${BASE}/runs`, input))
}

export function getRun(runId: IdRef): Promise<SkillRunView> {
  return call(() => http.get<SkillRunView>(`${BASE}/runs/${encodeURIComponent(String(runId))}`))
}

export function listRuns(limit = 50): Promise<SkillRunView[]> {
  const raw = Number.isFinite(limit) ? Math.floor(limit) : 50
  const bounded = Math.min(100, Math.max(1, raw))
  return call(() => http.get<SkillRunView[]>(`${BASE}/runs`, { params: { limit: bounded } }))
}

export function advanceRun(
  runId: IdRef,
  expectedRevision: number,
  stepInput: Record<string, unknown> = {},
): Promise<SkillRunView> {
  return call(() =>
    http.post<SkillRunView>(`${BASE}/runs/${encodeURIComponent(String(runId))}/advance`, {
      expected_revision: expectedRevision,
      step_input: stepInput,
    }),
  )
}

export function cancelRun(runId: IdRef, expectedRevision: number): Promise<SkillRunView> {
  return call(() =>
    http.post<SkillRunView>(`${BASE}/runs/${encodeURIComponent(String(runId))}/cancel`, {
      expected_revision: expectedRevision,
    }),
  )
}

/** 稳定请求键：客户端生成，同输入重放用同一 key；改输入必须换 key */
export function makeRequestKey(prefix = 'ui'): string {
  const rand =
    typeof crypto !== 'undefined' && 'randomUUID' in crypto
      ? crypto.randomUUID()
      : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`
  return `${prefix}-${rand}`
}

// ========== D 推荐与事件（BL-145） ==========

export type RecommendationEvent =
  | 'expose'
  | 'click'
  | 'adopt'
  | 'ignore'
  | 'execute'
  | 'complete'

export interface RecommendationItemWire {
  recommendation_id: IdRef
  skill_key: string
  version: string
  candidate_id?: IdRef | null
  variant_key?: string | null
  context: Record<string, unknown>
  reason: Record<string, unknown>
  position: number
  grants_execution: boolean
}

export interface RecommendationListWire {
  items: RecommendationItemWire[]
  meta: {
    count?: number
    purpose?: string | null
    grants_execution?: boolean
  }
}

export interface RecommendationEventState {
  exposed_at?: string | null
  clicked_at?: string | null
  adopted_at?: string | null
  ignored_at?: string | null
  executed_at?: string | null
  completed_at?: string | null
}

export interface RecommendationDetail {
  recommendation_id: IdRef
  skill_key: string
  version: string
  candidate_id?: IdRef | null
  position: number
  reason: Record<string, unknown>
  events: RecommendationEventState
  last_event?: string | null
  grants_execution: boolean
}

export interface GenerateRecommendationInput {
  purpose?: string | null
  task_ref?: string | null
  variant_key?: string | null
}

export function generateRecommendations(
  input: GenerateRecommendationInput = {},
): Promise<RecommendationListWire> {
  return call(() => http.post<RecommendationListWire>(`${BASE}/recommendations`, input))
}

export function listRecommendations(limit = 20): Promise<RecommendationItemWire[]> {
  return call(() =>
    http.get<RecommendationItemWire[]>(`${BASE}/recommendations`, { params: { limit } }),
  )
}

export function getRecommendation(recommendationId: IdRef): Promise<RecommendationDetail> {
  return call(() =>
    http.get<RecommendationDetail>(
      `${BASE}/recommendations/${encodeURIComponent(String(recommendationId))}`,
    ),
  )
}

export function recordRecommendationEvent(
  recommendationId: IdRef,
  event: RecommendationEvent,
  payload: { run_id?: string | null; outcome?: string | null } = {},
): Promise<RecommendationDetail> {
  return call(() =>
    http.post<RecommendationDetail>(
      `${BASE}/recommendations/${encodeURIComponent(String(recommendationId))}/events`,
      { event, ...payload },
    ),
  )
}

// ========== D 训练（BL-145+146） ==========

export type TrainingStatus = 'queued' | 'running' | 'cancelled' | 'failed' | 'completed'

export interface TrainingRun {
  training_run_id: IdRef
  mode: string
  status: TrainingStatus | string
  dataset_manifest: Record<string, unknown>
  config_fingerprint?: string | null
  prompt_fingerprint?: string | null
  model_ref?: string | null
  budget?: Record<string, unknown> | null
  cost_micro?: number | null
  cost_unknown: boolean
  // 执行事实：区分「模型真跑过」与「状态流转过」
  llm_calls?: number | null
  usage?: Record<string, unknown> | null
  cost_source?: string | null
  started_at?: string | null
  finished_at?: string | null
  error?: string | null
  created_at?: string | null
  updated_at?: string | null
}

export interface TrainingCandidate {
  candidate_id: IdRef
  training_run_id: IdRef
  candidate_key: string
  status: 'proposed' | 'validated' | 'adopted' | 'rejected' | string
  skill_draft: Record<string, unknown>
  evidence_refs: unknown[]
  validation: Record<string, unknown>
  adoption?: {
    decision?: string | null
    reason?: string | null
    limited_tenant_ids?: unknown[]
  } | null
  bound_definition_id?: IdRef | null
}

export interface StartTrainingInput {
  mode: string
  dataset_manifest: Record<string, unknown>
  config_fingerprint?: string | null
  prompt_fingerprint?: string | null
  model_ref?: string | null
  budget?: Record<string, unknown> | null
  request_key: string
  input_fingerprint?: string | null
}

export function startTraining(input: StartTrainingInput): Promise<Pick<TrainingRun, 'training_run_id' | 'status'>> {
  return call(() => http.post(`${BASE}/training-runs`, input))
}

export function listTrainingRuns(status?: string, limit = 50): Promise<TrainingRun[]> {
  const params: Record<string, unknown> = { limit }
  if (status) params.status = status
  return call(() => http.get<TrainingRun[]>(`${BASE}/training-runs`, { params }))
}

export function getTrainingRun(trainingRunId: IdRef): Promise<TrainingRun> {
  return call(() =>
    http.get<TrainingRun>(`${BASE}/training-runs/${encodeURIComponent(String(trainingRunId))}`),
  )
}

export function cancelTrainingRun(
  trainingRunId: IdRef,
): Promise<Pick<TrainingRun, 'training_run_id' | 'status'>> {
  return call(() =>
    http.post(`${BASE}/training-runs/${encodeURIComponent(String(trainingRunId))}/cancel`),
  )
}

export function listCandidates(trainingRunId?: IdRef, limit = 50): Promise<TrainingCandidate[]> {
  const params: Record<string, unknown> = { limit }
  if (trainingRunId != null) params.training_run_id = trainingRunId
  return call(() => http.get<TrainingCandidate[]>(`${BASE}/candidates`, { params }))
}

export function decideCandidate(
  candidateId: IdRef,
  action: 'evaluate' | 'adopt' | 'reject',
  context: Record<string, unknown> = {},
): Promise<TrainingCandidate> {
  return call(() =>
    http.post<TrainingCandidate>(
      `${BASE}/candidates/${encodeURIComponent(String(candidateId))}/decide`,
      { action, context },
    ),
  )
}
