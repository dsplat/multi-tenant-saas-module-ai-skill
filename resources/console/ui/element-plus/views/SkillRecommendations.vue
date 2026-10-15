<template>
  <div class="page-container">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>技能推荐</span>
          <div class="header-actions">
            <el-button type="primary" :loading="busy" @click="regenerate">刷新推荐</el-button>
            <el-button @click="reload">加载现有</el-button>
          </div>
        </div>
      </template>

      <el-alert
        type="info"
        :closable="false"
        show-icon
        title="推荐只是建议，不授予执行权。曝光/点击/采用/忽略/执行/完成分列记录；点击 ≠ 成功，采用 ≠ 完成。"
        style="margin-bottom: 12px"
      />

      <el-table v-loading="loading" :data="rows">
        <el-table-column prop="position" label="#" width="50" />
        <el-table-column label="Skill" min-width="200">
          <template #default="{ row }">
            <el-link type="primary" @click="goDetail(row)">{{ row.skill_key }}@{{ row.version }}</el-link>
          </template>
        </el-table-column>
        <el-table-column label="来源" width="130">
          <template #default="{ row }">
            {{ displayUnknown(row.reason?.source) }}
          </template>
        </el-table-column>
        <el-table-column label="变体" width="120">
          <template #default="{ row }">{{ displayUnknown(row.variant_key) }}</template>
        </el-table-column>
        <el-table-column label="事件状态" min-width="160">
          <template #default="{ row }">
            <el-tag v-if="events(row).completed_at" size="small" type="success">已完成</el-tag>
            <el-tag v-else-if="events(row).executed_at" size="small" type="warning">已执行</el-tag>
            <el-tag v-else-if="events(row).adopted_at" size="small" type="primary">已采用</el-tag>
            <el-tag v-else-if="events(row).ignored_at" size="small" type="info">已忽略</el-tag>
            <el-tag v-else-if="events(row).clicked_at" size="small">已点击</el-tag>
            <el-tag v-else-if="events(row).exposed_at" size="small" type="info">已曝光</el-tag>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="280" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" :loading="busy" @click="emit(row, 'expose')">曝光</el-button>
            <el-button link type="primary" :loading="busy" @click="emit(row, 'click')">点击</el-button>
            <el-button link type="success" :loading="busy" @click="emit(row, 'adopt')">采用</el-button>
            <el-button
              link
              type="info"
              :disabled="!!(events(row).adopted_at || events(row).executed_at)"
              :loading="busy"
              @click="emit(row, 'ignore')"
            >
              忽略
            </el-button>
            <el-button link type="warning" :loading="busy" @click="goRun(row)">去运行</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-empty v-if="!loading && rows.length === 0" description="暂无推荐，可点「刷新推荐」" />

      <el-alert
        type="warning"
        :closable="false"
        show-icon
        title="去运行仅跳转运行台；仍需本次授权与参数检查。执行完成后请回写「执行/完成」事件。"
        style="margin-top: 12px"
      />
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import {
  generateRecommendations,
  getRecommendation,
  listRecommendations,
  recordRecommendationEvent,
  SkillApiError,
  type RecommendationEvent,
  type RecommendationEventState,
  type RecommendationItemWire,
} from '@modules/AiSkill/api/skill'
import { displayUnknown } from '@modules/AiSkill/resources/console/utils/labels'

defineOptions({ name: 'SkillRecommendations' })

const router = useRouter()
const loading = ref(false)
const busy = ref(false)
const rows = ref<RecommendationItemWire[]>([])
const eventCache = ref<Record<string, RecommendationEventState>>({})

function events(row: RecommendationItemWire): RecommendationEventState {
  return eventCache.value[String(row.recommendation_id)] || {}
}

async function reload() {
  loading.value = true
  try {
    rows.value = await listRecommendations(20)
  } catch (error) {
    const err = error instanceof SkillApiError ? error : null
    ElMessage.error(err?.message || '推荐加载失败')
    rows.value = []
  } finally {
    loading.value = false
  }
}

async function regenerate() {
  busy.value = true
  try {
    const list = await generateRecommendations({ purpose: 'study_followup' })
    rows.value = list.items
    ElMessage.success(`已生成 ${list.items.length} 条推荐`)
  } catch (error) {
    const err = error instanceof SkillApiError ? error : null
    ElMessage.error(err?.message || '推荐生成失败')
  } finally {
    busy.value = false
  }
}

async function emit(row: RecommendationItemWire, event: RecommendationEvent) {
  busy.value = true
  try {
    const detail = await recordRecommendationEvent(row.recommendation_id, event)
    eventCache.value[String(row.recommendation_id)] = detail.events
    ElMessage.success(`已记录 ${event}`)
  } catch (error) {
    const err = error instanceof SkillApiError ? error : null
    ElMessage.error(err?.message || '事件记录失败')
  } finally {
    busy.value = false
  }
}

function goDetail(row: RecommendationItemWire) {
  void getRecommendation(row.recommendation_id).then((detail) => {
    eventCache.value[String(row.recommendation_id)] = detail.events
  })
  router.push({
    name: 'AiSkillDetail',
    params: { skillKey: row.skill_key, version: row.version },
  })
}

function goRun(row: RecommendationItemWire) {
  // 曝光/点击不等于执行授权；跳转运行台由用户显式启动
  void emit(row, 'click').then(() => {
    router.push({
      name: 'AiSkillRun',
      params: { skillKey: row.skill_key, version: row.version },
    })
  })
}

onMounted(reload)
</script>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.header-actions {
  display: flex;
  gap: 8px;
}
</style>
