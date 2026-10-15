<template>
  <div class="page-container">
    <el-card v-loading="loading">
      <template #header>
        <div class="card-header">
          <span>{{ detail?.title || skillKey }}</span>
          <el-button @click="goBack">返回目录</el-button>
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

      <template v-if="detail">
        <el-descriptions :column="2" border>
          <el-descriptions-item label="Skill Key">{{ detail.skill_key }}</el-descriptions-item>
          <el-descriptions-item label="版本">{{ detail.version }}</el-descriptions-item>
          <el-descriptions-item label="来源">{{ scopeLabel(detail.scope_type) }}</el-descriptions-item>
          <el-descriptions-item label="执行类型">{{ executionHint(detail.execution) }}</el-descriptions-item>
          <el-descriptions-item label="适用条件">
            {{ displayUnknown(formatJson(detail.applicability)) }}
          </el-descriptions-item>
          <el-descriptions-item label="工具依赖">
            {{ displayUnknown(formatJson(detail.tool_deps)) }}
          </el-descriptions-item>
          <el-descriptions-item label="风险">
            {{ displayUnknown(formatJson(detail.risk)) }}
          </el-descriptions-item>
          <el-descriptions-item label="结果判据">
            {{ displayUnknown(formatJson(detail.success_criteria)) }}
          </el-descriptions-item>
          <el-descriptions-item label="状态">{{ statusLabel(detail.status) }}</el-descriptions-item>
          <el-descriptions-item label="正文状态">
            {{ contentStatusLabel(detail.content_status) }}
          </el-descriptions-item>
        </el-descriptions>

        <el-alert
          v-if="detail.content_status === 'missing_or_corrupt'"
          type="error"
          :closable="false"
          show-icon
          title="正文缺失或损坏，该版本不可用。不从文件目录补齐。"
          style="margin: 12px 0"
        />

        <template v-else>
          <h4 style="margin: 16px 0 8px">方法正文</h4>
          <!-- eslint-disable-next-line vue/no-v-html — renderSkillMarkdown 已转义并只输出受控标签 -->
          <div class="skill-body" v-html="bodyHtml" />

          <div class="actions">
            <el-button
              type="primary"
              :disabled="detail.content_status !== 'ready' || detail.execution === 'instructions'"
              @click="goRun"
            >
              {{ detail.execution === 'instructions' ? '请通过小秘书协助' : '开始固定运行' }}
            </el-button>
            <el-button
              type="primary"
              plain
              :loading="saving"
              :disabled="detail.content_status !== 'ready'"
              @click="favoritePersonal"
            >
              收藏到我的
            </el-button>
            <el-button
              :loading="saving"
              :disabled="detail.content_status !== 'ready'"
              @click="favoriteTenant"
            >
              保存到租户共享
            </el-button>
            <el-button @click="goFavorites">查看收藏</el-button>
          </div>
          <el-alert
            type="info"
            :closable="false"
            show-icon
            title="收藏仅固定当前版本引用与有限默认参数，不构成执行授权。instructions 路径交给小秘书协助探索。"
            style="margin-top: 12px"
          />
        </template>
      </template>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import {
  getSkillDefinition,
  saveFavorite,
  SkillApiError,
  type FavoriteScope,
  type SkillDefinitionDetail,
} from '@modules/AiSkill/api/skill'
import {
  contentStatusLabel,
  displayUnknown,
  executionHint,
  scopeLabel,
  statusLabel,
} from '@modules/AiSkill/resources/console/utils/labels'
import { renderSkillMarkdown } from '@modules/AiSkill/resources/console/utils/markdownSafe'

defineOptions({ name: 'SkillDetail' })

const route = useRoute()
const router = useRouter()
const loading = ref(false)
const saving = ref(false)
const detail = ref<SkillDefinitionDetail | null>(null)
const errorMessage = ref('')

const skillKey = computed(() => String(route.params.skillKey || ''))
const version = computed(() => String(route.params.version || ''))
const bodyHtml = computed(() => renderSkillMarkdown(detail.value?.content_body))

function formatJson(value: unknown): string {
  if (value === null || value === undefined) return ''
  if (typeof value === 'string') return value
  try {
    return JSON.stringify(value)
  } catch {
    return ''
  }
}

async function reload() {
  if (!skillKey.value || !version.value) {
    errorMessage.value = '缺少技能引用'
    return
  }
  loading.value = true
  errorMessage.value = ''
  try {
    detail.value = await getSkillDefinition(skillKey.value, version.value)
  } catch (error) {
    const err = error instanceof SkillApiError ? error : null
    errorMessage.value = err?.message || '详情加载失败'
    detail.value = null
  } finally {
    loading.value = false
  }
}

async function favorite(scope: FavoriteScope) {
  if (!detail.value) return
  saving.value = true
  try {
    await saveFavorite({
      skill_key: detail.value.skill_key,
      version: detail.value.version,
      definition_ref: String(detail.value.skill_definition_id),
      scope,
    })
    ElMessage.success(scope === 'personal' ? '已收藏到我的' : '已保存到租户共享')
  } catch (error) {
    const err = error instanceof SkillApiError ? error : null
    ElMessage.error(err?.message || '收藏失败')
  } finally {
    saving.value = false
  }
}

function favoritePersonal() {
  return favorite('personal')
}
function favoriteTenant() {
  return favorite('tenant')
}

function goBack() {
  router.push({ name: 'AiSkillCatalog' })
}
function goFavorites() {
  router.push({ name: 'AiSkillFavorites' })
}
function goRun() {
  router.push({
    name: 'AiSkillRun',
    params: { skillKey: skillKey.value, version: version.value },
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
.skill-body {
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 6px;
  padding: 12px 16px;
  line-height: 1.7;
  overflow: auto;
}
.skill-body :deep(pre) {
  background: var(--el-fill-color-light);
  padding: 8px 12px;
  border-radius: 4px;
  overflow: auto;
}
.skill-body :deep(code) {
  background: var(--el-fill-color-light);
  padding: 1px 4px;
  border-radius: 3px;
}
.actions {
  margin-top: 16px;
  display: flex;
  gap: 8px;
}
</style>
