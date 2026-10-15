<template>
  <div class="page-container">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>租户版本启停</span>
          <el-button @click="reload">刷新</el-button>
        </div>
      </template>

      <el-alert
        type="info"
        :closable="false"
        show-icon
        title="仅可管理当前租户已有 published/disabled 版本。当前目录 API 不返回 disabled，完整停用列表需管理查询 API——未补齐前不伪造「启用已停用」列表。module/project 全局版本不可在此修改。"
        style="margin-bottom: 12px"
      />

      <el-table v-loading="loading" :data="tenantItems">
        <el-table-column prop="title" label="标题" min-width="160" />
        <el-table-column prop="skill_key" label="Skill Key" min-width="140" />
        <el-table-column prop="version" label="版本" width="90" />
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <el-tag size="small" :type="row.status === 'published' ? 'success' : 'info'">
              {{ statusLabel(row.status) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="正文" width="120">
          <template #default="{ row }">
            {{ contentStatusLabel(row.content_status) }}
          </template>
        </el-table-column>
        <el-table-column label="操作" width="120" fixed="right">
          <template #default="{ row }">
            <el-button
              link
              type="danger"
              :disabled="row.status !== 'published'"
              :loading="pendingId === String(row.skill_definition_id)"
              @click="disable(row)"
            >
              停用
            </el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-empty v-if="!loading && tenantItems.length === 0" description="暂无本租户可管理版本" />
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  changeDefinitionStatus,
  listSkills,
  SkillApiError,
  type SkillCatalogItem,
} from '@modules/AiSkill/api/skill'
import { contentStatusLabel, statusLabel } from '@modules/AiSkill/resources/console/utils/labels'

defineOptions({ name: 'SkillVersions' })

const loading = ref(false)
const pendingId = ref('')
const items = ref<SkillCatalogItem[]>([])

/** changeStatus 仅作用于 scope_type=tenant 且 scope_id=本租户 */
const tenantItems = computed(() => items.value.filter((row) => row.scope_type === 'tenant'))

async function reload() {
  loading.value = true
  try {
    items.value = await listSkills(100)
  } catch (error) {
    const err = error instanceof SkillApiError ? error : null
    ElMessage.error(err?.message || '版本加载失败')
    items.value = []
  } finally {
    loading.value = false
  }
}

async function disable(row: SkillCatalogItem) {
  try {
    await ElMessageBox.confirm(
      `停用 ${row.skill_key}@${row.version}？收藏引用会显示失效，已发生动作不重放。`,
      '确认停用',
      { type: 'warning' },
    )
  } catch {
    return
  }
  pendingId.value = String(row.skill_definition_id)
  try {
    await changeDefinitionStatus(row.skill_definition_id, {
      expected_status: 'published',
      status: 'disabled',
    })
    ElMessage.success('已停用')
    await reload()
  } catch (error) {
    const err = error instanceof SkillApiError ? error : null
    if (err?.status === 409) {
      ElMessage.warning('状态已变更，请刷新后重试')
      await reload()
    } else {
      ElMessage.error(err?.message || '停用失败')
    }
  } finally {
    pendingId.value = ''
  }
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
