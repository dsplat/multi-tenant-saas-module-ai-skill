<template>
  <div class="page-container">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>Skill 运行</span>
          <div>
            <el-button @click="goRuns">运行记录</el-button>
            <el-button @click="goDetail">技能详情</el-button>
          </div>
        </div>
      </template>

      <el-alert
        v-if="errorMessage"
        type="error"
        :title="errorMessage"
        :closable="false"
        show-icon
        style="margin-bottom: 12px"
      />

      <!-- 未启动：输入参数 -->
      <template v-if="!run">
        <el-descriptions :column="2" border style="margin-bottom: 12px">
          <el-descriptions-item label="Skill Key">{{ skillKey }}</el-descriptions-item>
          <el-descriptions-item label="版本">{{ version }}</el-descriptions-item>
          <el-descriptions-item label="展示顺序">
            <el-select v-model="variantKey" clearable style="width: 180px">
              <el-option label="概览优先" value="overview_first" />
              <el-option label="异常优先" value="exceptions_first" />
            </el-select>
          </el-descriptions-item>
          <el-descriptions-item label="内容摘要">
            {{ displayUnknown(detail?.content_sha256) }}
          </el-descriptions-item>
        </el-descriptions>

        <el-form label-width="120px">
          <el-form-item label="运行参数 JSON">
            <el-input v-model="inputJson" type="textarea" :rows="6" placeholder="{}" />
          </el-form-item>
        </el-form>

        <el-alert
          type="info"
          :closable="false"
          show-icon
          title="启动会固定当前数据库版本与正文摘要；推荐/收藏不等于执行授权。instructions 路径交给小秘书协助。"
          style="margin-bottom: 12px"
        />
        <el-button type="primary" :loading="busy" :disabled="detail?.execution === 'instructions'" @click="doStart">启动固定运行</el-button>
      </template>

      <!-- 运行中 -->
      <template v-else>
        <el-descriptions :column="2" border>
          <el-descriptions-item label="Run ID">{{ run.skill_run_id }}</el-descriptions-item>
          <el-descriptions-item label="Revision">{{ run.revision }}</el-descriptions-item>
          <el-descriptions-item label="Skill">{{ run.skill_key }}@{{ run.skill_version }}</el-descriptions-item>
          <el-descriptions-item label="状态">
            <el-tag :type="statusTag(run.status)">{{ run.status }}</el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="结果">
            {{ displayUnknown(run.outcome) }}
          </el-descriptions-item>
          <el-descriptions-item label="原因">
            {{ displayUnknown(run.reason) }}
          </el-descriptions-item>
          <el-descriptions-item label="固定摘要">
            {{ displayUnknown(run.context?.content_sha256) }}
          </el-descriptions-item>
          <el-descriptions-item label="变体">
            {{ displayUnknown(run.context?.variant_key) }}
          </el-descriptions-item>
        </el-descriptions>

        <el-alert
          v-for="(w, i) in run.warnings || []"
          :key="i"
          type="warning"
          :closable="false"
          show-icon
          :title="w.message || w.code || '警告'"
          style="margin-top: 8px"
        />

        <el-alert v-if="run.outcome === 'incomplete'" type="warning" :closable="false"
          title="工具步骤已执行，业务目标尚无独立验证，不能记为任务成功。" />
        <pre v-if="run.context?.result_refs" style="white-space: pre-wrap">{{ JSON.stringify(run.context.result_refs, null, 2) }}</pre>
        <h4 style="margin: 16px 0 8px">步骤</h4>
        <el-steps :active="run.current_step" finish-status="success" align-center>
          <el-step
            v-for="(step, i) in run.steps"
            :key="i"
            :title="step.name"
            :description="step.status"
          />
        </el-steps>

        <div class="actions">
          <template v-if="canAdvance">
            <el-button
              type="primary"
              :loading="busy"
              @click="doAdvance({})"
            >
              推进下一步
            </el-button>
          </template>
          <el-alert v-if="run.status === 'waiting_confirm'" type="warning" :closable="false"
            title="需要可信确认与执行证据，请通过小秘书处理；此页不能手动标记工具成功。" />
          <el-button
            v-if="!isTerminal"
            type="danger"
            plain
            :loading="busy"
            @click="doCancel"
          >
            取消运行
          </el-button>
          <el-button :loading="busy" @click="refresh">刷新</el-button>
          <el-button v-if="run.status === 'uncertain'" type="warning" @click="goRuns">
            去对账
          </el-button>
        </div>

        <el-alert
          v-if="run.status === 'uncertain'"
          type="warning"
          :closable="false"
          show-icon
          title="结果不明：禁止重放副作用，请人工对账后再处理。"
          style="margin-top: 12px"
        />
        <el-alert
          v-if="isTerminal"
          type="info"
          :closable="false"
          show-icon          :title="`运行已结束（${run.status}/${displayUnknown(run.outcome)}），不再自动推进。`"
          style="margin-top: 12px"
        />
      </template>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import {
  advanceRun,
  cancelRun,
  getRun,
  getSkillDefinition,
  makeRequestKey,
  SkillApiError,
  startRun,
  type SkillDefinitionDetail,
  type SkillRunView,
} from '@modules/AiSkill/api/skill'
import { displayUnknown } from '@modules/AiSkill/resources/console/utils/labels'

defineOptions({ name: 'SkillRun' })

const route = useRoute()
const router = useRouter()
const busy = ref(false)
const errorMessage = ref('')
const detail = ref<SkillDefinitionDetail | null>(null)
const run = ref<SkillRunView | null>(null)
const variantKey = ref('')
const inputJson = ref('{}')

const skillKey = computed(() => String(route.params.skillKey || ''))
const version = computed(() => String(route.params.version || ''))
const isTerminal = computed(() =>
  ['completed', 'cancelled', 'uncertain'].includes(String(run.value?.status)),
)
const canAdvance = computed(
  () => !!run.value && run.value.context?.execution !== 'instructions' && run.value.status === 'running',
)

function statusTag(status: string): 'success' | 'info' | 'warning' | 'danger' {
  if (status === 'completed') return 'success'
  if (status === 'uncertain' || status === 'failed') return 'danger'
  if (status === 'cancelled') return 'info'
  return 'warning'
}

function fail(error: unknown) {
  const err = error instanceof SkillApiError ? error : null
  errorMessage.value = err?.message || '操作失败'
  if (err?.status === 409) {
    ElMessage.warning('版本/状态已变更，已刷新现场')
    void refresh()
  }
}

async function loadDetail() {
  if (!skillKey.value || !version.value) return
  try {
    detail.value = await getSkillDefinition(skillKey.value, version.value)
  } catch (error) {
    fail(error)
  }
}

async function doStart() {
  busy.value = true
  errorMessage.value = ''
  try {
    run.value = await startRun({
      skill_key: skillKey.value,
      version: version.value,
      variant_key: variantKey.value || undefined,
      input: JSON.parse(inputJson.value),
      request_key: makeRequestKey('run'),
    })
    ElMessage.success('运行已启动（版本与摘要已固定）')
  } catch (error) {
    fail(error)
  } finally {
    busy.value = false
  }
}

async function doAdvance(stepInput: Record<string, unknown>) {
  if (!run.value) return
  busy.value = true
  errorMessage.value = ''
  try {
    run.value = await advanceRun(run.value.skill_run_id, run.value.revision, stepInput)
  } catch (error) {
    fail(error)
  } finally {
    busy.value = false
  }
}

async function doCancel() {
  if (!run.value) return
  busy.value = true
  errorMessage.value = ''
  try {
    run.value = await cancelRun(run.value.skill_run_id, run.value.revision)
    ElMessage.success('已取消（已发生副作用不回滚）')
  } catch (error) {
    fail(error)
  } finally {
    busy.value = false
  }
}

async function refresh() {
  if (!run.value) return
  try {
    run.value = await getRun(run.value.skill_run_id)
  } catch (error) {
    fail(error)
  }
}

function goDetail() {
  router.push({ name: 'AiSkillDetail', params: { skillKey: skillKey.value, version: version.value } })
}
function goRuns() {
  router.push({ name: 'AiSkillRuns' })
}

onMounted(async () => {
  await loadDetail()
  const resumeId = route.query.run_id ? String(route.query.run_id) : ''
  if (resumeId) {
    try {
      run.value = await getRun(resumeId)
    } catch (error) {
      fail(error)
    }
    return
  }
  applyQueryDefaults()
})

// 业务页跳转时带上服务端算好的入参（?input=<JSON>），运营不必手抄参数。
// 只是预填启动表单：版本、正文摘要、入参合法性仍由服务端固定与校验。
function applyQueryDefaults() {
  const rawInput = route.query.input
  if (typeof rawInput === 'string' && rawInput !== '') {
    try {
      const parsed = JSON.parse(rawInput)
      if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
        inputJson.value = JSON.stringify(parsed, null, 2)
      }
    } catch {
      // 非法预填直接忽略，保持手工输入
    }
  }
  const variant = route.query.variant
  if (typeof variant === 'string' && variant !== '') {
    variantKey.value = variant
  }
}
</script>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.actions {
  margin-top: 20px;
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
</style>
