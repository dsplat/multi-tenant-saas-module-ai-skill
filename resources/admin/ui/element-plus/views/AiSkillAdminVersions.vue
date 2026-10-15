<template>
  <div class="page">
    <h2>公共版本治理</h2>
    <el-alert type="info" :closable="false" title="仅列出公共模块/项目版本。租户资料需要明确授权范围；公共版本不可原地修改正文。" />
    <el-alert v-if="errorMessage" type="error" :closable="false" :title="errorMessage" />
    <el-card style="margin-top: 16px">
      <template #header>
        <div class="header"><span>最多加载100条公共版本</span><el-button :loading="loading" @click="reload">刷新</el-button></div>
      </template>
      <el-table v-loading="loading" :data="rows" empty-text="暂无可见版本">
        <el-table-column prop="skill_key" label="Skill" min-width="200" />
        <el-table-column prop="version" label="版本" width="120" />
        <el-table-column prop="scope_type" label="来源" width="120" />
        <el-table-column prop="status" label="状态" width="120" />
        <el-table-column prop="content_status" label="正文" min-width="140" />
        <el-table-column label="管理" width="120">
          <template #default="{ row }">
            <el-button v-if="canManage && row.status !== 'draft'" link :loading="pending === row.skill_definition_id"
              :disabled="!!pending || (row.status === 'disabled' && row.content_status !== 'ready')" @click="toggle(row)">
              {{ row.status === 'published' ? '停用' : '恢复' }}
            </el-button>
            <span v-else>只读</span>
          </template>
        </el-table-column>
      </el-table>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue'
import { ElMessageBox } from 'element-plus'
import { useUserStore } from '@multi-tenant-saas/admin/stores/user'
import { listGovernedSkills, changeGovernedSkillStatus, adminSkillError, type GovernedSkillVersion } from '../../../../../api/adminSkill'

const user = useUserStore()
const canManage = computed(() => user.hasPermission('platform.ai.skill.manage'))
const rows = ref<GovernedSkillVersion[]>([])
const loading = ref(false)
const pending = ref('')
const errorMessage = ref('')
let generation = 0
watch(() => user.user?.user_id, () => { generation++; rows.value = []; errorMessage.value = ''; loading.value = false })
onBeforeUnmount(() => { generation++; rows.value = [] })
async function reload() {
  const current = ++generation
  loading.value = true
  errorMessage.value = ''
  rows.value = []
  try { const data = await listGovernedSkills(); if (current === generation && user.isLoggedIn) rows.value = data }
  catch (error) { if (current === generation) errorMessage.value = adminSkillError(error) }
  finally { if (current === generation) loading.value = false }
}
async function toggle(row: GovernedSkillVersion) {
  if (pending.value || !canManage.value) return
  try { await ElMessageBox.confirm(`确认${row.status === 'published' ? '停用' : '恢复'} ${row.skill_key}@${row.version}？`, '版本管理') }
  catch { return }
  pending.value = row.skill_definition_id
  try { await changeGovernedSkillStatus(row); await reload() }
  catch (error) { await reload(); errorMessage.value = adminSkillError(error) }
  finally { pending.value = '' }
}
onMounted(reload)
</script>
<style scoped>.header { display:flex; justify-content:space-between; align-items:center } h2 { margin-bottom:16px }</style>
