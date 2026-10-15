import type {
  ContentStatus,
  FavoriteScope,
  ReferenceStatus,
  SkillExecution,
  SkillScope,
  SkillStatus,
} from '../../api/skill'

export function executionLabel(execution: SkillExecution | string | undefined): string {
  switch (execution) {
    case 'chain':
      return '任务链'
    case 'instructions':
      return '方法说明'
    case 'mixed':
      return '混合'
    default:
      return '未知'
  }
}

export function scopeLabel(scope: SkillScope | string | undefined): string {
  switch (scope) {
    case 'module':
      return '模块'
    case 'project':
      return '项目'
    case 'tenant':
      return '租户'
    default:
      return '未知'
  }
}

export function statusLabel(status: SkillStatus | string | undefined): string {
  switch (status) {
    case 'published':
      return '已发布'
    case 'disabled':
      return '已停用'
    case 'draft':
      return '草稿'
    default:
      return '未知'
  }
}

export function contentStatusLabel(status: ContentStatus | string | undefined): string {
  switch (status) {
    case 'ready':
      return '正文可用'
    case 'missing_or_corrupt':
      return '正文缺失或损坏'
    default:
      return '未知'
  }
}

export function favoriteScopeLabel(scope: FavoriteScope | string | undefined): string {
  switch (scope) {
    case 'personal':
      return '个人'
    case 'tenant':
      return '租户共享'
    default:
      return '未知'
  }
}

export function referenceStatusLabel(status: ReferenceStatus | string | undefined): string {
  switch (status) {
    case 'available':
      return '可用'
    case 'unavailable':
      return '已失效'
    default:
      return '未知'
  }
}

/** instructions 路径按契约只展示「交给小秘书协助」，不暴露固定执行承诺 */
export function executionHint(execution: SkillExecution | string | undefined): string {
  if (execution === 'instructions') return '交给小秘书协助'
  if (execution === 'chain') return '固定任务链'
  if (execution === 'mixed') return '链 + 小秘书协助'
  return '未知'
}

/** 未知值不渲染为成功/0；成本类同理 */
export function displayUnknown(value: unknown, fallback = 'unknown'): string {
  if (value === null || value === undefined || value === '') return fallback
  return String(value)
}
