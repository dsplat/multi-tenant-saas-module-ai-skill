<template>
  <div class="page-container">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>运行记录</span>
          <el-button @click="reload">刷新</el-button>
        </div>
      </template>

      <el-alert
        type="info"
        :closable="false"
        show-icon
        title="仅显示未完成/需对账的 run；固定定义，恢复不重读目录。结果不明禁止重放副作用。"
        style="margin-bottom: 12px"
      />

      <el-table v-loading="loading" :data="rows">
        <el-table-column prop="skill_run_id" label="Run ID" min-width="140" />
        <el-table-column label="Skill" min-width="180">
          <template #default="{ row }">
            {{ row.skill_key }}@{{ row.skill_version }}
          </template>
        </el-table-column>
        <el-table-column prop="revision" label="Revision" width="90" />
        <el-table-column label="状态" width="120">
          <template #default="{ row }">
            <el-tag size="small" :type="statusTag(row.status)">{{ row.status }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="结果" width="110">
          <template #default="{ row }">{{ displayUnknown(row.outcome) }}</template>
        </el-table-column>
        <el-table-column label="下一步" width="140">
          <template #default="{ row }">{{ displayUnknown(row.next_action) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="120" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="resume(row)">继续</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-empty v-if="!loading && rows.length === 0" description="暂无进行中的运行" />
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { listRuns, SkillApiError, type SkillRunView } from '@modules/AiSkill/api/skill'
import { displayUnknown } from '@modules/AiSkill/resources/console/utils/labels'

defineOptions({ name: 'SkillRuns' })

const router = useRouter()
const loading = ref(false)
const rows = ref<SkillRunView[]>([])

function statusTag(status: string): 'success' | 'info' | 'warning' | 'danger' {
  if (status === 'completed') return 'success'
  if (status === 'uncertain' || status === 'failed') return 'danger'
  if (status === 'cancelled') return 'info'
  return 'warning'
}

async function reload() {
  loading.value = true
  try {
    rows.value = await listRuns(50)
  } catch (error) {
    const err = error instanceof SkillApiError ? error : null
    ElMessage.error(err?.message || '运行记录加载失败')
    rows.value = []
  } finally {
    loading.value = false
  }
}

function resume(row: SkillRunView) {
  router.push({
    name: 'AiSkillRun',
    params: { skillKey: row.skill_key, version: row.skill_version },
    query: { run_id: String(row.skill_run_id) },
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
</style>
