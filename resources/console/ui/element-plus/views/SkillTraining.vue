<template>
  <div class="page-container">
    <el-card style="margin-bottom: 16px">
      <template #header>
        <div class="card-header">
          <span>训练工作台</span>
          <el-button :loading="busy" @click="reload">刷新</el-button>
        </div>
      </template>

      <el-alert
        type="info"
        :closable="false"
        show-icon
        title="当前仅登记任务与候选；异步LLM训练处理器尚未接线。训练完成 ≠ 候选可采用；采用裁决只来自服务端 AdoptionPolicy。成本缺失显示 unknown。文档上传契约未交付前仅支持授权来源引用。"
        style="margin-bottom: 12px"
      />

      <el-form :inline="true" style="margin-bottom: 12px">
        <el-form-item label="来源">
          <el-select v-model="form.sources" multiple collapse-tags style="width: 220px">
            <el-option label="audit_logs" value="audit_logs" />
            <el-option label="skill_experiences" value="skill_experiences" />
            <el-option label="task_chain_runs" value="task_chain_runs" />
          </el-select>
        </el-form-item>
        <el-form-item label="样本上限">
          <el-input-number v-model="form.maxSamples" :min="1" :max="500" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="busy" @click="start">登记训练任务</el-button>
        </el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="runs">
        <el-table-column prop="training_run_id" label="Run ID" min-width="140" />
        <el-table-column prop="mode" label="模式" width="110" />
        <el-table-column label="状态" width="110">
          <template #default="{ row }">
            <el-tag size="small" :type="statusTag(row.status)">{{ row.status }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="成本" width="110">
          <template #default="{ row }">
            <span v-if="row.cost_unknown">unknown</span>
            <span v-else>{{ row.cost_micro }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="120" fixed="right">
          <template #default="{ row }">
            <el-button
              link
              type="danger"
              :disabled="['cancelled', 'failed', 'completed'].includes(row.status)"
              :loading="busy"
              @click="cancel(row)"
            >
              取消
            </el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-card>
      <template #header>
        <div class="card-header">
          <span>候选</span>
          <el-button :loading="busy" @click="loadCandidates">刷新候选</el-button>
        </div>
      </template>

      <el-table v-loading="loadingCandidates" :data="candidates">
        <el-table-column prop="candidate_key" label="Key" width="120" />
        <el-table-column label="Skill 草案" min-width="180">
          <template #default="{ row }">
            {{ row.skill_draft?.skill_key }}@{{ row.skill_draft?.version }}
          </template>
        </el-table-column>
        <el-table-column label="风险" width="100">
          <template #default="{ row }">
            {{ displayUnknown(row.skill_draft?.risk?.level) }}
          </template>
        </el-table-column>
        <el-table-column label="状态" width="110">
          <template #default="{ row }">
            <el-tag size="small" :type="candTag(row.status)">{{ row.status }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="策略" min-width="140">
          <template #default="{ row }">
            {{ row.adoption ? `${row.adoption.decision} / ${row.adoption.reason}` : '-' }}
          </template>
        </el-table-column>
        <el-table-column label="操作" width="160" fixed="right">
          <template #default="{ row }">
            <el-button
              link
              type="success"
              :disabled="row.status !== 'validated'"
              :loading="busy"
              @click="decide(row, 'adopt')"
            >
              采用
            </el-button>
            <el-button
              link
              type="danger"
              :disabled="['adopted', 'rejected'].includes(row.status)"
              :loading="busy"
              @click="decide(row, 'reject')"
            >
              拒绝
            </el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-empty v-if="!loadingCandidates && candidates.length === 0" description="暂无候选" />
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import {
  cancelTrainingRun,
  decideCandidate,
  listCandidates,
  listTrainingRuns,
  makeRequestKey,
  SkillApiError,
  startTraining,
  type TrainingCandidate,
  type TrainingRun,
} from '@modules/AiSkill/api/skill'
import { displayUnknown } from '@modules/AiSkill/resources/console/utils/labels'

defineOptions({ name: 'SkillTraining' })

const busy = ref(false)
const loading = ref(false)
const loadingCandidates = ref(false)
const runs = ref<TrainingRun[]>([])
const candidates = ref<TrainingCandidate[]>([])
const form = ref({
  sources: ['audit_logs', 'skill_experiences'] as string[],
  maxSamples: 100,
})

function statusTag(status: string): 'success' | 'info' | 'warning' | 'danger' {
  if (status === 'completed') return 'success'
  if (status === 'failed') return 'danger'
  if (status === 'cancelled') return 'info'
  return 'warning'
}

function candTag(status: string): 'success' | 'info' | 'warning' | 'danger' {
  if (status === 'adopted') return 'success'
  if (status === 'rejected') return 'danger'
  if (status === 'validated') return 'warning'
  return 'info'
}

async function reload() {
  loading.value = true
  try {
    runs.value = await listTrainingRuns(undefined, 20)
  } catch (error) {
    const err = error instanceof SkillApiError ? error : null
    ElMessage.error(err?.message || '训练列表加载失败')
  } finally {
    loading.value = false
  }
}

async function loadCandidates() {
  loadingCandidates.value = true
  try {
    candidates.value = await listCandidates(undefined, 20)
  } catch (error) {
    const err = error instanceof SkillApiError ? error : null
    ElMessage.error(err?.message || '候选加载失败')
  } finally {
    loadingCandidates.value = false
  }
}

async function start() {
  busy.value = true
  try {
    await startTraining({
      mode: 'history',
      dataset_manifest: {
        source_types: form.value.sources,
        max_samples: form.value.maxSamples,
      },
      request_key: makeRequestKey('train'),
    })
    ElMessage.success('训练已入队（queued）')
    await reload()
  } catch (error) {
    const err = error instanceof SkillApiError ? error : null
    ElMessage.error(err?.message || '启动失败')
  } finally {
    busy.value = false
  }
}

async function cancel(row: TrainingRun) {
  busy.value = true
  try {
    await cancelTrainingRun(row.training_run_id)
    ElMessage.success('已取消')
    await reload()
  } catch (error) {
    const err = error instanceof SkillApiError ? error : null
    ElMessage.error(err?.message || '取消失败')
  } finally {
    busy.value = false
  }
}

async function decide(row: TrainingCandidate, action: 'adopt' | 'reject') {
  busy.value = true
  try {
    await decideCandidate(row.candidate_id, action, {
      baseline: { risk: { level: 'low', business_write: false }, tool_deps: [] },
    })
    ElMessage.success(action === 'adopt' ? '已采用（策略 auto）' : '已拒绝')
    await loadCandidates()
  } catch (error) {
    const err = error instanceof SkillApiError ? error : null
    if (err?.status === 422) {
      ElMessage.warning('需人工审核，未自动采用')
    } else {
      ElMessage.error(err?.message || '裁决失败')
    }
  } finally {
    busy.value = false
  }
}

onMounted(() => {
  void reload()
  void loadCandidates()
})
</script>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
</style>
