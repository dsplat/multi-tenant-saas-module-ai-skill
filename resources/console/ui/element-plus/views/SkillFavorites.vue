<template>
  <div class="page-container">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>我的收藏</span>
          <el-button @click="reload">刷新</el-button>
        </div>
      </template>

      <el-tabs v-model="tab">
        <el-tab-pane label="个人收藏" name="personal" />
        <el-tab-pane label="租户共享" name="tenant" />
      </el-tabs>

      <el-table v-loading="loading" :data="visibleRows">
        <el-table-column prop="skill_key" label="Skill Key" min-width="140" />
        <el-table-column prop="version" label="固定版本" width="100" />
        <el-table-column label="范围" width="110">
          <template #default="{ row }">
            <el-tag size="small" :type="row.scope === 'tenant' ? 'warning' : 'info'">
              {{ favoriteScopeLabel(row.scope) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="有限偏好" min-width="140">
          <template #default="{ row }">
            {{ formatDefaults(row.allowed_defaults) }}
          </template>
        </el-table-column>
        <el-table-column label="引用状态" width="110">
          <template #default="{ row }">
            <el-tag
              size="small"
              :type="row.reference_status === 'available' ? 'success' : 'danger'"
            >
              {{ referenceStatusLabel(row.reference_status) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="失效原因" width="140">
          <template #default="{ row }">
            <span v-if="row.reference_status === 'unavailable'">
              {{ displayUnknown(row.reason, 'unknown') }}
            </span>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="160" fixed="right">
          <template #default="{ row }">
            <el-button
              link
              type="primary"
              :disabled="row.reference_status !== 'available'"
              @click="goDetail(row)"
            >
              详情
            </el-button>
            <el-button
              link
              type="danger"
              :loading="removingId === String(row.skill_favorite_id)"
              @click="remove(row)"
            >
              移除
            </el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-empty v-if="!loading && visibleRows.length === 0" description="暂无收藏" />

      <el-alert
        type="info"
        :closable="false"
        show-icon
        title="收藏固定版本引用与有限 enum 默认参数；不保存动态营期 ID、联系方式、自由提示词或确认令牌。租户共享写需要 setting.update。"
        style="margin-top: 12px"
      />
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  listFavorites,
  removeFavorite,
  SkillApiError,
  type SkillFavorite,
} from '@modules/AiSkill/api/skill'
import {
  displayUnknown,
  favoriteScopeLabel,
  referenceStatusLabel,
} from '@modules/AiSkill/resources/console/utils/labels'

defineOptions({ name: 'SkillFavorites' })

const router = useRouter()
const loading = ref(false)
const removingId = ref('')
const rows = ref<SkillFavorite[]>([])
const tab = ref<'personal' | 'tenant'>('personal')

const visibleRows = computed(() => rows.value.filter((row) => row.scope === tab.value))

function formatDefaults(defaults: Record<string, unknown> | null | undefined): string {
  if (!defaults || Object.keys(defaults).length === 0) return '-'
  return Object.entries(defaults)
    .map(([k, v]) => `${k}=${String(v)}`)
    .join(', ')
}

async function reload() {
  loading.value = true
  try {
    rows.value = await listFavorites()
  } catch (error) {
    const err = error instanceof SkillApiError ? error : null
    ElMessage.error(err?.message || '收藏加载失败')
    rows.value = []
  } finally {
    loading.value = false
  }
}

function goDetail(row: SkillFavorite) {
  router.push({
    name: 'AiSkillDetail',
    params: { skillKey: row.skill_key, version: row.version },
  })
}

async function remove(row: SkillFavorite) {
  try {
    await ElMessageBox.confirm(
      `移除收藏 ${row.skill_key}@${row.version}？`,
      '确认',
      { type: 'warning' },
    )
  } catch {
    return
  }
  removingId.value = String(row.skill_favorite_id)
  try {
    await removeFavorite(row.skill_favorite_id)
    ElMessage.success('已移除')
    await reload()
  } catch (error) {
    const err = error instanceof SkillApiError ? error : null
    ElMessage.error(err?.message || '移除失败')
  } finally {
    removingId.value = ''
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
