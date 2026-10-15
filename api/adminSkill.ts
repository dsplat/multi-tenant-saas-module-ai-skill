import axios from 'axios'

export interface GovernedSkillVersion {
  skill_definition_id: string
  skill_key: string
  version: string
  scope_type: 'module' | 'project' | 'tenant'
  status: 'draft' | 'published' | 'disabled'
  content_status?: 'ready' | 'missing_or_corrupt'
}

const base = '/api/v1/admin/ai-skills'

export async function listGovernedSkills(): Promise<GovernedSkillVersion[]> {
  const response = await axios.get(`${base}/versions`, { params: { limit: 100 } })
  return response.data.data
}

export async function changeGovernedSkillStatus(row: GovernedSkillVersion): Promise<GovernedSkillVersion> {
  const response = await axios.patch(`${base}/versions/${encodeURIComponent(row.skill_definition_id)}/status`, {
    expected_status: row.status,
    status: row.status === 'published' ? 'disabled' : 'published',
  })
  return response.data.data
}

export function adminSkillError(error: unknown): string {
  const status = (error as { response?: { status?: number } })?.response?.status
  if (status === 409) return '版本状态已变更，请刷新后重试'
  if (status === 401) return '登录已失效，请重新登录'
  if (status === 403) return '无管理权限或功能尚未启用'
  if (status === 422) return '版本正文校验未通过，无法启用'
  return '操作失败，请稍后重试'
}
