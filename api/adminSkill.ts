import axios from 'axios'

export interface GovernedSkillVersion {
  skill_definition_id: string
  skill_key: string
  version: string
  scope_type: 'module' | 'project' | 'tenant'
  status: 'draft' | 'published' | 'disabled'
  content_status?: 'ready' | 'missing_or_corrupt'
}

const base = '/api/v1/admin/ai-skills'

export async function listGovernedSkills(): Promise<GovernedSkillVersion[]> {
  const response = await axios.get(`${base}/versions`, { params: { limit: 100 } })
  return response.data.data
}

export async function changeGovernedSkillStatus(row: GovernedSkillVersion): Promise<GovernedSkillVersion> {
  const response = await axios.patch(`${base}/versions/${encodeURIComponent(row.skill_definition_id)}/status`, {
    expected_status: row.status,
    status: row.status === 'published' ? 'disabled' : 'published',
  })
  return response.data.data
}

export function adminSkillError(error: unknown): string {
  const status = (error as { response?: { status?: number } })?.response?.status
  if (status === 409) return '版本状态或判据已变更，请刷新后重试'
  if (status === 401) return '登录已失效，请重新登录'
  if (status === 403) return '无管理权限，或该租户不在你的授权范围内'
  if (status === 402) return '租户额度不足，本次操作未执行'
  if (status === 422) return '校验未通过：请检查版本正文、判据字段或执行器开关'
  return '操作失败，请稍后重试'
}

/**
 * 训练与评测治理契约（WP-147a/b/c）。
 *
 * - ID 一律不透明字符串，禁止 Number 强转（全局 ID 可超出 JS 安全整数）
 * - tenant_id 必须由操作者显式给出：不存在默认租户，客户端也不得补 0
 * - 采样窗口在登记时冻结，之后训练器只读规范键
 * - 采用/拒绝只回服务端 AdoptionPolicy 的结论，界面不自行判定
 */

/** 不透明 ID：服务端以字符串序列化 */
export type AdminId = string

export type TrainingMode = 'module_docs' | 'history' | 'recommend_optimize' | 'failure_diagnosis'

export interface TrainingDatasetManifest {
  source_types: string[]
  time_from: string
  time_to: string
  field_allowlist: string[]
  max_samples: number
  access_mode: string
}

export interface TrainingRun {
  training_run_id: AdminId
  tenant_id: AdminId
  mode: string
  status: 'queued' | 'running' | 'completed' | 'failed' | 'cancelled' | string
  dataset_manifest?: TrainingDatasetManifest | Record<string, unknown>
  model_ref?: string | null
  budget?: Record<string, unknown> | null
  cost_micro?: number | null
  cost_unknown: boolean
  cost_source?: string | null
  llm_calls?: number | null
  usage?: Record<string, unknown> | null
  started_at?: string | null
  finished_at?: string | null
  error?: string | null
  created_at?: string | null
}

export interface EvalCase {
  eval_case_id?: AdminId
  dataset_id: string
  dataset_version: string
  split: 'discovery' | 'validation'
  skill_key: string
  case_key: string
  input: Record<string, unknown>
  asserts: Record<string, unknown>
  rubric_version?: string
  content_sha256?: string
}

/** 评测事实：由服务端 CandidateEvaluator 单点写入，界面只读 */
export interface CandidateValidation {
  passed?: boolean
  holdout_cases?: number
  holdout_score?: number
  baseline_score?: number | null
  severe_errors?: number
  baseline_definition_id?: number | null
  evaluation?: {
    evidence_level?: string
    evaluator_version?: string
    dataset_id?: string
    dataset_version?: string
    llm_calls?: number
    orchestration_calls?: number
    comparative?: boolean
    failed_cases?: { case: string; reasons: string[] }[]
  }
}

export interface CandidateAdoption {
  decision?: string
  reason?: string
  evidence?: unknown
  limited_tenant_ids?: string[]
}

export interface CandidateFact {
  candidate_id: AdminId
  training_run_id: AdminId
  tenant_id: AdminId
  candidate_key: string
  status: string
  skill_draft: Record<string, unknown>
  validation: CandidateValidation
  adoption?: CandidateAdoption | null
}

/** 采样来源与读取模式：与服务端 ExperienceSourceAdapter 白名单一一对应，客户端不得自造 */
export const DATASET_SOURCES = [
  'audit_logs',
  'ai_audit_logs',
  'agent_tool_logs',
  'task_chain_runs',
  'skill_experiences',
] as const

export const DATASET_ACCESS_MODES = ['metadata_only', 'inspect_body', 'transform_body'] as const

/** metadata_only 禁读的业务正文字段：与服务端拒绝对齐，避免把越权请求发出去 */
export const BODY_FIELDS = ['input', 'output', 'content', 'old_values', 'new_values', 'detail']

function requireTenant(tenantId: AdminId): string {
  const value = String(tenantId ?? '').trim()
  if (value === '' || value === '0') throw new Error('tenant scope required')
  return value
}

export async function listTrainingRuns(tenantId: AdminId, limit = 20): Promise<TrainingRun[]> {
  const response = await axios.get(`${base}/training-runs`, { params: { tenant_id: requireTenant(tenantId), limit } })
  return response.data.data
}

export async function enqueueTraining(payload: {
  tenant_id: AdminId
  mode: TrainingMode
  dataset_manifest: TrainingDatasetManifest
  request_key: string
  model_ref?: string
  budget?: Record<string, unknown>
}): Promise<TrainingRun> {
  const response = await axios.post(`${base}/training-runs`, {
    tenant_id: requireTenant(payload.tenant_id),
    mode: payload.mode,
    dataset_manifest: payload.dataset_manifest,
    request_key: payload.request_key,
    model_ref: payload.model_ref || null,
    budget: payload.budget ?? null,
  })
  return response.data.data
}

export async function listCandidates(tenantId: AdminId, limit = 50): Promise<CandidateFact[]> {
  const response = await axios.get(`${base}/candidates`, { params: { tenant_id: requireTenant(tenantId), limit } })
  return response.data.data
}

export async function listEvalCases(params: {
  tenant_id: AdminId
  dataset_id: string
  dataset_version: string
}): Promise<EvalCase[]> {
  const response = await axios.get(`${base}/eval-cases`, { params: { ...params, tenant_id: requireTenant(params.tenant_id) } })
  return response.data.data
}

export async function freezeEvalCases(payload: {
  tenant_id: AdminId
  dataset_id: string
  dataset_version: string
  split: 'discovery' | 'validation'
  cases: EvalCase[]
}): Promise<{ created: number; unchanged: number }> {
  const response = await axios.post(`${base}/eval-cases`, {
    tenant_id: requireTenant(payload.tenant_id),
    dataset_id: payload.dataset_id,
    dataset_version: payload.dataset_version,
    split: payload.split,
    cases: payload.cases,
  })
  return response.data.data
}

export async function evaluateCandidate(
  candidateId: AdminId,
  datasetId: string,
  datasetVersion: string,
): Promise<CandidateFact> {
  const response = await axios.post(
    `${base}/candidates/${encodeURIComponent(candidateId)}/evaluate`,
    { dataset_id: datasetId, dataset_version: datasetVersion },
  )
  return response.data.data
}

/**
 * 采用/拒绝：只提交动作，不提交「是否跳过评测」「风险等级」这类判定字段。
 * 服务端重算 AdoptionPolicy，界面无权决定结果。
 */
export async function decideCandidate(candidateId: AdminId, action: 'adopt' | 'reject'): Promise<CandidateFact> {
  const response = await axios.post(`${base}/candidates/${encodeURIComponent(candidateId)}/decide`, { action })
  return response.data.data
}

/** 窗口/授权面缺失即登记失败（此时还没花钱），客户端提前拦住并给出可读原因 */
export function trainingWindowError(manifest: TrainingDatasetManifest): string {
  if (manifest.source_types.length === 0) return '至少显式选择一种采样来源'
  if (manifest.source_types.some((s) => !(DATASET_SOURCES as readonly string[]).includes(s))) return '采样来源不在允许清单内'
  if (!(DATASET_ACCESS_MODES as readonly string[]).includes(manifest.access_mode)) return '读取模式不在允许清单内'
  if (manifest.time_from === '' || manifest.time_to === '') return '采样窗口必须填写起止时间（无默认窗口）'
  if (manifest.max_samples < 1 || manifest.max_samples > 500) return '样本上限需在 1..500 之间'
  const from = new Date(manifest.time_from)
  const to = new Date(manifest.time_to)
  if (from.toString() === 'Invalid Date' || to.toString() === 'Invalid Date') return '采样时间无法解析'
  if (from > to) return '采样窗口起止顺序有误'
  if (manifest.access_mode === 'transform_body' && manifest.field_allowlist.length === 0) {
    return 'transform_body 必须给出字段白名单'
  }
  if (manifest.access_mode === 'metadata_only' && manifest.field_allowlist.some((f) => BODY_FIELDS.includes(f))) {
    return 'metadata_only 不能读取业务正文字段'
  }
  return ''
}
