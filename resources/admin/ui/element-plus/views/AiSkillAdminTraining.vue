<template>
  <div class="page">
    <div class="page-header">
      <h2>平台训练与审核</h2>
      <div class="scope">
        <span class="label">授权租户</span>
        <el-input v-model="tenantId" placeholder="必填：显式填写租户 ID，无默认租户" clearable style="width: 220px" />
        <el-button :disabled="!hasTenant" :loading="loadingRuns" @click="reload">刷新</el-button>
      </div>
    </div>

    <el-alert
      type="warning"
      :closable="false"
      show-icon
      title="登记任务与真实调用是两个动作：执行器开关（ai-skill.training / ai-skill.evaluation）默认关闭，需另行开启；未开启时启动只会留下 queued 记录并返回校验失败，不会花费 token。采用与拒绝只回服务端 AdoptionPolicy 的结论。"
      style="margin-bottom: 16px"
    />
    <el-alert v-if="errorMessage" type="error" :closable="false" :title="errorMessage" style="margin-bottom: 12px" />

    <el-row :gutter="16">
      <el-col :span="12">
        <el-card shadow="never">
          <template #header><span>主动训练（显式授权窗口）</span></template>
          <el-form label-width="108px" :disabled="!canManage">
            <el-form-item label="训练模式">
              <el-select v-model="form.mode" style="width: 100%">
                <el-option label="模块材料（module_docs）" value="module_docs" />
                <el-option label="历史轨迹（history）" value="history" />
                <el-option label="推荐优化（recommend_optimize）" value="recommend_optimize" />
                <el-option label="失败诊断（failure_diagnosis）" value="failure_diagnosis" />
              </el-select>
            </el-form-item>
            <el-form-item label="采样来源">
              <el-select v-model="form.sourceTypes" multiple style="width: 100%" placeholder="至少一种，且不默认全租户">
                <el-option v-for="source in DATASET_SOURCES" :key="source" :label="source" :value="source" />
              </el-select>
            </el-form-item>
            <el-form-item label="读取模式">
              <el-select v-model="form.accessMode" style="width: 100%">
                <el-option label="metadata_only（不读正文）" value="metadata_only" />
                <el-option label="inspect_body（只读正文）" value="inspect_body" />
                <el-option label="transform_body（需字段白名单）" value="transform_body" />
              </el-select>
            </el-form-item>
            <el-form-item label="字段白名单">
              <el-select
                v-model="form.fieldAllowlist"
                multiple
                filterable
                allow-create
                default-first-option
                :disabled="form.accessMode === 'metadata_only'"
                style="width: 100%"
                placeholder="transform_body 必填；metadata_only 禁读业务正文"
              >
                <el-option v-for="field in BODY_FIELDS" :key="field" :label="field" :value="field" />
              </el-select>
            </el-form-item>
            <el-form-item label="采样窗口">
              <el-date-picker
                v-model="form.window"
                type="datetimerange"
                value-format="YYYY-MM-DDTHH:mm:ssZ"
                unlink-panels
                range-separator="至"
                start-placeholder="起始（必填）"
                end-placeholder="结束（必填）"
                style="width: 100%"
              />
            </el-form-item>
            <el-form-item label="样本上限">
              <el-input-number v-model="form.maxSamples" :min="1" :max="500" />
            </el-form-item>
            <el-form-item label="模型引用">
              <el-input v-model="form.modelRef" clearable placeholder="留空则用服务端默认路由" />
            </el-form-item>
            <el-form-item label="预算上限">
              <el-input-number v-model="form.maxTokens" :min="1000" :max="200000" :step="1000" />
              <span class="hint">max_tokens（费用未知时结果按 unknown 显示，不按 0）</span>
            </el-form-item>
            <el-form-item>
              <el-button type="primary" :loading="submitting" :disabled="!hasTenant" @click="startTraining">
                预览脱敏样本并登记
              </el-button>
            </el-form-item>
            <el-form-item v-if="windowHint">
              <el-alert type="info" :closable="false" :title="windowHint" />
            </el-form-item>
          </el-form>
        </el-card>
      </el-col>

      <el-col :span="12">
        <el-card shadow="never">
          <template #header>
            <div class="header">
              <span>训练运行（{{ hasTenant ? tenantId : '未选择租户' }}）</span>
              <el-button link :disabled="!hasTenant" @click="reload">重载</el-button>
            </div>
          </template>
          <el-table v-loading="loadingRuns" :data="runs" empty-text="暂无训练运行">
            <el-table-column prop="mode" label="模式" width="130" />
            <el-table-column prop="status" label="状态" width="110" />
            <el-table-column label="LLM 调用" width="100">
              <template #default="{ row }">{{ row.llm_calls ?? '—' }}</template>
            </el-table-column>
            <el-table-column label="成本" width="120">
              <template #default="{ row }">
                <span v-if="row.cost_unknown">unknown（{{ row.cost_source ?? '无来源' }}）</span>
                <span v-else>{{ row.cost_micro }} 微元</span>
              </template>
            </el-table-column>
            <el-table-column prop="error" label="失败原因" min-width="140" :show-overflow-tooltip="true" />
          </el-table>
        </el-card>
      </el-col>
    </el-row>

    <el-card shadow="never" style="margin-top: 16px">
      <template #header><span>评测判据冻结（只此入口可写，训练器与候选正文改不了）</span></template>
      <el-form inline :disabled="!canManage">
        <el-form-item label="租户">
          <el-input v-model="tenantId" style="width: 140px" placeholder="必填" />
        </el-form-item>
        <el-form-item label="数据集">
          <el-input v-model="judge.datasetId" style="width: 160px" placeholder="如 ds-weekly" />
        </el-form-item>
        <el-form-item label="版本">
          <el-input v-model="judge.datasetVersion" style="width: 120px" placeholder="如 2026-W42" />
        </el-form-item>
        <el-form-item label="分集">
          <el-select v-model="judge.split" style="width: 140px">
            <el-option label="validation" value="validation" />
            <el-option label="discovery" value="discovery" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button :disabled="!hasTenant" @click="loadCases">查看已冻结</el-button>
          <el-button v-if="canManage" type="primary" :loading="freezing" :disabled="!hasTenant" @click="freezeCases">
            冻结判据
          </el-button>
        </el-form-item>
      </el-form>

      <el-alert
        type="info"
        :closable="false"
        show-icon
        title="判据只允许确定性断言字段（contains / not_contains / equals / required_tools / forbidden_tools）。已冻结的 case_key 不可覆写：内容变了请另起 dataset_version，避免「评测时改题」。留出集需 ≥10 例才可自动采用。"
        style="margin-bottom: 12px"
      />

      <el-table :data="judge.rows" size="small" empty-text="未填写判据用例">
        <el-table-column label="case_key" width="180">
          <template #default="{ row }"><el-input v-model="row.case_key" placeholder="case-001" /></template>
        </el-table-column>
        <el-table-column label="skill_key" width="180">
          <template #default="{ row }"><el-input v-model="row.skill_key" placeholder="tenant.123.report" /></template>
        </el-table-column>
        <el-table-column label="输入（JSON）" min-width="200">
          <template #default="{ row }"><el-input v-model="row.inputText" type="textarea" :autosize="{ minRows: 1, maxRows: 4 }" /></template>
        </el-table-column>
        <el-table-column label="断言（JSON）" min-width="200">
          <template #default="{ row }"><el-input v-model="row.assertsText" type="textarea" :autosize="{ minRows: 1, maxRows: 4 }" /></template>
        </el-table-column>
        <el-table-column label="" width="60">
          <template #default="{ $index }"><el-button link @click="judge.rows.splice($index, 1)">删</el-button></template>
        </el-table-column>
      </el-table>
      <el-button link style="margin-top: 8px" @click="judge.rows.push(emptyJudgeRow())">+ 增加用例</el-button>

      <el-table v-if="frozenCases.length" :data="frozenCases" size="small" style="margin-top: 12px">
        <el-table-column prop="case_key" label="case_key" width="180" />
        <el-table-column prop="split" label="分集" width="120" />
        <el-table-column prop="skill_key" label="skill_key" min-width="160" />
        <el-table-column label="摘要" min-width="160">
          <template #default="{ row }">{{ String(row.content_sha256 ?? '').slice(0, 12) }}…</template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-card shadow="never" style="margin-top: 16px">
      <template #header>
        <div class="header">
          <span>候选审核</span>
          <el-button link :disabled="!hasTenant" @click="reload">重载候选</el-button>
        </div>
      </template>
      <el-table v-loading="loadingCandidates" :data="candidates" empty-text="暂无候选">
        <el-table-column label="候选" width="170">
          <template #default="{ row }">
            <div>{{ draftTitle(row) }}</div>
            <div class="sub">{{ row.candidate_key }}</div>
          </template>
        </el-table-column>
        <el-table-column prop="status" label="状态" width="110" />
        <el-table-column label="留出评测" min-width="220">
          <template #default="{ row }">
            <span v-if="!hasValidation(row)">未评测</span>
            <span v-else>
              {{ row.validation.passed ? '达标' : '未达标' }}
              · {{ row.validation.holdout_score }}（基线 {{ row.validation.baseline_score ?? '—' }}）
              · {{ row.validation.holdout_cases }} 例 · 严重错误 {{ row.validation.severe_errors }}
              · LLM {{ row.validation.evaluation?.llm_calls ?? '—' }} 次
              <span v-if="row.validation.evaluation?.comparative">（对比基线）</span>
            </span>
          </template>
        </el-table-column>
        <el-table-column label="策略结论" min-width="160">
          <template #default="{ row }">
            <span v-if="!row.adoption">—</span>
            <span v-else>{{ row.adoption.decision }} / {{ row.adoption.reason }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="220">
          <template #default="{ row }">
            <el-button link :disabled="!canManage || !hasTenant" @click="openDraft(row)">正文</el-button>
            <el-button link type="primary" :disabled="!canManage" @click="runEvaluation(row)">发起评测</el-button>
            <el-button link type="success" :disabled="!canManage || !hasValidation(row)" @click="decide(row, 'adopt')">通过</el-button>
            <el-button link type="danger" :disabled="!canManage" @click="decide(row, 'reject')">拒绝</el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-alert
        type="info"
        :closable="false"
        show-icon
        title="「通过」先验证独立评测证据：未达标拒绝；需审的只读变更由获授权平台人员确认发布并留痕。发布不授予执行权，写能力仍需独立可信验证。"
        style="margin-top: 12px"
      />
    </el-card>

    <el-dialog v-model="draftVisible" title="候选正文（只读）" width="720px">
      <pre class="draft">{{ draftText }}</pre>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useUserStore } from '@multi-tenant-saas/admin/stores/user'
import {
  BODY_FIELDS,
  DATASET_SOURCES,
  adminSkillError,
  decideCandidate,
  enqueueTraining,
  evaluateCandidate,
  freezeEvalCases,
  listCandidates,
  listEvalCases,
  listTrainingRuns,
  trainingWindowError,
  type CandidateFact,
  type EvalCase,
  type TrainingMode,
  type TrainingRun,
} from '../../../../../api/adminSkill'

defineOptions({ name: 'AiSkillAdminTraining' })

/** 判据断言只允许确定性判定器认识的字段（与服务端 SkillAdminService::EVAL_ASSERT_KEYS 对齐） */
const ASSERT_FIELDS = ['contains', 'not_contains', 'equals', 'required_tools', 'forbidden_tools']

interface JudgeRow {
  case_key: string
  skill_key: string
  inputText: string
  assertsText: string
}

const user = useUserStore()
const canManage = computed(() => user.hasPermission('platform.ai.skill.manage'))

const tenantId = ref('')
const hasTenant = computed(() => /^[1-9][0-9]*$/.test(tenantId.value.trim()))
const runs = ref<TrainingRun[]>([])
const candidates = ref<CandidateFact[]>([])
const frozenCases = ref<EvalCase[]>([])
const loadingRuns = ref(false)
const loadingCandidates = ref(false)
const submitting = ref(false)
const freezing = ref(false)
const errorMessage = ref('')
const draftVisible = ref(false)
const draftText = ref('')

const form = reactive({
  mode: 'history' as TrainingMode,
  sourceTypes: [] as string[],
  accessMode: 'metadata_only',
  fieldAllowlist: [] as string[],
  window: [] as string[],
  maxSamples: 100,
  modelRef: '',
  maxTokens: 4000,
})

const judge = reactive({
  datasetId: '',
  datasetVersion: '',
  split: 'validation' as 'validation' | 'discovery',
  rows: [] as JudgeRow[],
})

const windowHint = computed(() => (hasTenant.value ? '' : trainingWindowError(manifest())))

/** 幂等键：同一份输入重复点击必须命中同一条 run，改表才换新键 */
let requestKey = newKey()
watch(form, () => { requestKey = newKey() })

function newKey(): string {
  const cryptoRef = globalThis.crypto as Crypto | undefined
  return cryptoRef?.randomUUID
    ? `admin-${cryptoRef.randomUUID()}`
    : `admin-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
}

function manifest() {
  return {
    source_types: [...form.sourceTypes],
    time_from: form.window?.[0] ?? '',
    time_to: form.window?.[1] ?? '',
    field_allowlist: [...form.fieldAllowlist],
    max_samples: form.maxSamples,
    access_mode: form.accessMode,
  }
}

function emptyJudgeRow(): JudgeRow {
  return { case_key: '', skill_key: '', inputText: '{}', assertsText: '{}' }
}

function hasValidation(row: CandidateFact): boolean {
  return Object.keys(row.validation ?? {}).length > 0
}

function draftTitle(row: CandidateFact): string {
  const draft = row.skill_draft as { title?: string; skill_key?: string }
  return draft?.title || draft?.skill_key || row.candidate_key
}

function fail(error: unknown) {
  errorMessage.value = adminSkillError(error)
}

/** 切租户/换人员期间晚返回的旧响应必须被丢弃，否则会把上一租户的运行显示在当前租户下 */
let generation = 0
watch(tenantId, () => {
  generation++
  runs.value = []
  candidates.value = []
  frozenCases.value = []
  errorMessage.value = ''
})
watch(() => user.user?.user_id, () => {
  generation++
  tenantId.value = ''
  runs.value = []
  candidates.value = []
  frozenCases.value = []
})
onBeforeUnmount(() => { generation++ })

async function reload() {
  if (!hasTenant.value) return
  const current = ++generation
  loadingRuns.value = true
  loadingCandidates.value = true
  errorMessage.value = ''
  try {
    const [runsData, candidateData] = await Promise.all([listTrainingRuns(tenantId.value), listCandidates(tenantId.value)])
    if (current !== generation || !user.isLoggedIn) return
    runs.value = runsData
    candidates.value = candidateData
  } catch (error) {
    if (current === generation) fail(error)
  } finally {
    if (current === generation) {
      loadingRuns.value = false
      loadingCandidates.value = false
    }
  }
}

async function startTraining() {
  if (!canManage.value || submitting.value) return
  const reason = trainingWindowError(manifest())
  if (reason !== '') {
    ElMessage.warning(reason)
    return
  }
  try {
    await ElMessageBox.confirm(
      `将在租户 ${tenantId.value} 上按已冻结窗口登记训练：来源 ${form.sourceTypes.join(', ')}，上限 ${form.maxSamples} 例。执行器开启时会真实调用 LLM。`,
      '登记训练',
    )
  } catch {
    return
  }
  submitting.value = true
  errorMessage.value = ''
  try {
    const run = await enqueueTraining({
      tenant_id: tenantId.value,
      mode: form.mode,
      dataset_manifest: manifest(),
      request_key: requestKey,
      model_ref: form.modelRef,
      budget: { max_tokens: form.maxTokens },
    })
    ElMessage.success(`已登记 ${run.training_run_id}（${run.status}）`)
    await reload()
  } catch (error) {
    fail(error)
  } finally {
    submitting.value = false
  }
}

async function loadCases() {
  if (!hasTenant.value || judge.datasetId === '' || judge.datasetVersion === '') {
    ElMessage.warning('请先填写租户、数据集与版本')
    return
  }
  errorMessage.value = ''
  try {
    frozenCases.value = await listEvalCases({
      tenant_id: tenantId.value,
      dataset_id: judge.datasetId,
      dataset_version: judge.datasetVersion,
    })
  } catch (error) {
    fail(error)
  }
}

function parseJudgeRow(row: JudgeRow, index: number): EvalCase {
  let input: unknown
  let asserts: unknown
  try {
    input = JSON.parse(row.inputText === '' ? '{}' : row.inputText)
    asserts = JSON.parse(row.assertsText === '' ? '{}' : row.assertsText)
  } catch {
    throw new Error(`第 ${index + 1} 行的输入或断言不是合法 JSON`)
  }
  if (typeof input !== 'object' || input === null || Array.isArray(input)) throw new Error(`第 ${index + 1} 行输入需为对象`)
  if (typeof asserts !== 'object' || asserts === null || Array.isArray(asserts)) throw new Error(`第 ${index + 1} 行断言需为对象`)
  const unknown = Object.keys(asserts as Record<string, unknown>).filter((key) => !ASSERT_FIELDS.includes(key))
  if (unknown.length > 0) throw new Error(`第 ${index + 1} 行含不支持的断言字段：${unknown.join(', ')}`)
  if (row.case_key === '' || row.skill_key === '') throw new Error(`第 ${index + 1} 行缺 case_key 或 skill_key`)

  return {
    dataset_id: judge.datasetId,
    dataset_version: judge.datasetVersion,
    split: judge.split,
    case_key: row.case_key,
    skill_key: row.skill_key,
    input: input as Record<string, unknown>,
    asserts: asserts as Record<string, unknown>,
  }
}

async function freezeCases() {
  if (!canManage.value || freezing.value) return
  errorMessage.value = ''
  let cases: EvalCase[]
  try {
    cases = judge.rows.map(parseJudgeRow)
  } catch (error) {
    ElMessage.warning((error as Error).message)
    return
  }
  if (cases.length === 0) {
    ElMessage.warning('至少填写一条判据用例')
    return
  }
  const barHint = judge.split === 'validation' && cases.length < 10 ? '（留出集不足 10 例，自动采用会被策略拒绝）' : ''
  try {
    await ElMessageBox.confirm(`冻结 ${cases.length} 条判据到 ${judge.datasetId}@${judge.datasetVersion} / ${judge.split}${barHint}，冻结后不可覆写。`, '冻结判据')
  } catch {
    return
  }
  freezing.value = true
  try {
    const result = await freezeEvalCases({
      tenant_id: tenantId.value,
      dataset_id: judge.datasetId,
      dataset_version: judge.datasetVersion,
      split: judge.split,
      cases,
    })
    ElMessage.success(`新建 ${result.created} 条，已存在 ${result.unchanged} 条`)
    await loadCases()
  } catch (error) {
    fail(error)
    await loadCases()
  } finally {
    freezing.value = false
  }
}

async function runEvaluation(row: CandidateFact) {
  if (!canManage.value) return
  let datasetVersion = judge.datasetVersion
  let datasetId = judge.datasetId
  try {
    const answer = await ElMessageBox.prompt(
      '评测会按已冻结判据真实执行候选（消耗 LLM）。输入数据集标识与版本，例如 ds-weekly / 2026-W42。',
      '发起独立评测',
      { inputValue: `${datasetId}@${datasetVersion}`, inputPlaceholder: 'dataset_id@dataset_version' },
    )
    const parts = String(answer.value || '').split('@')
    datasetId = parts[0]?.trim() ?? ''
    datasetVersion = parts[1]?.trim() ?? ''
  } catch {
    return
  }
  if (datasetId === '' || datasetVersion === '') {
    ElMessage.warning('需要 dataset_id@dataset_version')
    return
  }
  errorMessage.value = ''
  try {
    await evaluateCandidate(row.candidate_id, datasetId, datasetVersion)
    ElMessage.success('评测任务已入队，完成后可在本页查看 validation')
    await reload()
  } catch (error) {
    fail(error)
  }
}

async function decide(row: CandidateFact, action: 'adopt' | 'reject') {
  if (!canManage.value) return
  try {
    await ElMessageBox.confirm(
      action === 'adopt'
        ? '确认采用当前候选？服务端将复核独立评测和正文版本；需审的只读变更会记录您为审核人。发布后执行仍须另行授权。'
        : '拒绝该候选？已采用/已拒绝的候选不会被改写。',
      action === 'adopt' ? '采用裁决' : '拒绝候选',
    )
  } catch {
    return
  }
  errorMessage.value = ''
  try {
    const result = await decideCandidate(row.candidate_id, action)
    ElMessage.success(`裁决结果：${result.status}`)
    await reload()
  } catch (error) {
    fail(error)
  }
}

function openDraft(row: CandidateFact) {
  draftText.value = JSON.stringify(row.skill_draft ?? {}, null, 2)
  draftVisible.value = true
}

onMounted(() => {
  judge.rows = [emptyJudgeRow(), emptyJudgeRow(), emptyJudgeRow()]
})
</script>

<style scoped>
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
}

.scope {
  display: flex;
  align-items: center;
  gap: 8px;
}

.label {
  font-size: 13px;
  color: var(--el-text-color-secondary);
}

.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.hint {
  margin-left: 8px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.sub {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.draft {
  max-height: 420px;
  overflow: auto;
  font-size: 12px;
}

h2 {
  margin: 0;
}
</style>
