<template>
  <div class="page-container">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>任务技能</span>
          <div class="header-actions">
            <el-input
              v-model="keyword"
              placeholder="搜索标题 / skill_key"
              clearable
              style="width: 220px"
            />
            <el-select v-model="scopeFilter" placeholder="来源" clearable style="width: 120px">
              <el-option label="模块" value="module" />
              <el-option label="项目" value="project" />
              <el-option label="租户" value="tenant" />
            </el-select>
            <el-button @click="reload">刷新</el-button>
          </div>
        </div>
      </template>

      <el-alert
        v-if="items.length >= limit"
        type="info"
        :closable="false"
        show-icon
        :title="`最多加载 ${limit} 条；当前 API 有界返回，无服务端分页/总数。超过容量需先交付分页契约。`"
        style="margin-bottom: 12px"
      />

      <el-table v-loading="loading" :data="filtered" size="default">
        <el-table-column prop="title" label="标题" min-width="160">
          <template #default="{ row }">
            <el-link type="primary" @click="goDetail(row)">{{ row.title || row.skill_key }}</el-link>
          </template>
        </el-table-column>
        <el-table-column prop="skill_key" label="Skill Key" min-width="140" />
        <el-table-column prop="version" label="版本" width="90" />
        <el-table-column label="来源" width="90">
          <template #default="{ row }">
            <el-tag size="small" :type="scopeTag(row.scope_type)">{{ scopeLabel(row.scope_type) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="执行类型" width="110">
          <template #default="{ row }">{{ executionLabel(row.execution) }}</template>
        </el-table-column>
        <el-table-column label="风险" width="90">
          <template #default="{ row }">{{ displayUnknown(row.risk?.level ?? row.risk) }}</template>
        </el-table-column>
        <el-table-column label="可用状态" width="120">
          <template #default="{ row }">
            <el-tag
              size="small"
              :type="row.content_status === 'ready' ? 'success' : 'danger'"
            >
              {{ contentStatusLabel(row.content_status) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="100" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="goDetail(row)">详情</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-empty v-if="!loading && filtered.length === 0" description="暂无可用技能" />
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import {
  listSkills,
  SkillApiError,
  type SkillCatalogItem,
} from '@modules/AiSkill/api/skill'
import {
  contentStatusLabel,
  displayUnknown,
  executionLabel,
  scopeLabel,
} from '@modules/AiSkill/resources/console/utils/labels'

defineOptions({ name: 'SkillCatalog' })

const router = useRouter()
const loading = ref(false)
const items = ref<SkillCatalogItem[]>([])
const keyword = ref('')
const scopeFilter = ref<string>('')
const limit = 50

const filtered = computed(() => {
  const kw = keyword.value.trim().toLowerCase()
  return items.value.filter((row) => {
    if (scopeFilter.value && row.scope_type !== scopeFilter.value) return false
    if (!kw) return true
    return (
      (row.title || '').toLowerCase().includes(kw) ||
      row.skill_key.toLowerCase().includes(kw) ||
      row.version.toLowerCase().includes(kw)
    )
  })
})

function scopeTag(scope: string): 'success' | 'warning' | 'info' {
  if (scope === 'tenant') return 'warning'
  if (scope === 'project') return 'info'
  return 'success'
}

async function reload() {
  loading.value = true
  try {
    items.value = await listSkills(limit)
  } catch (error) {
    const err = error instanceof SkillApiError ? error : null
    ElMessage.error(err?.message || '目录加载失败')
    items.value = []
  } finally {
    loading.value = false
  }
}

function goDetail(row: SkillCatalogItem) {
  if (row.content_status === 'missing_or_corrupt') {
    ElMessage.warning('正文缺失或损坏，不可用')
    return
  }
  router.push({
    name: 'AiSkillDetail',
    params: { skillKey: row.skill_key, version: row.version },
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
