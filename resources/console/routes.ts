import type { RouteRecordRaw } from 'vue-router'
import { view } from '@multi-tenant-saas/console/module-loader'

/**
 * AiSkill Console 路由（F1）
 *
 * 能力门（服务端强制，前端仅防误触）：
 * - 读：`ai.capability.view` + `ai-skill.enabled`
 * - 租户共享收藏写 / 版本启停：`setting.update`
 * 页面责任见 docs/ai-skill-backoffice-playbook.md §3 第一批。
 */
const routes: RouteRecordRaw[] = [
  {
    path: 'ai-skills',
    name: 'AiSkillCatalog',
    component: view('AiSkill', 'SkillCatalog'),
    meta: { title: '任务技能', requiresAuth: true, module: 'ai-skill', permission: 'ai.capability.view' },
  },
  {
    path: 'ai-skills/favorites',
    name: 'AiSkillFavorites',
    component: view('AiSkill', 'SkillFavorites'),
    meta: { title: '我的收藏', requiresAuth: true, module: 'ai-skill', permission: 'ai.capability.view' },
  },
  {
    path: 'ai-skills/versions',
    name: 'AiSkillVersions',
    component: view('AiSkill', 'SkillVersions'),
    meta: { title: '租户版本', requiresAuth: true, module: 'ai-skill', permission: 'ai.capability.view' },
  },
  {
    path: 'ai-skills/runs',
    name: 'AiSkillRuns',
    component: view('AiSkill', 'SkillRuns'),
    meta: { title: '运行记录', requiresAuth: true, module: 'ai-skill', permission: 'ai.capability.view' },
  },
  {
    path: 'ai-skills/recommendations',
    name: 'AiSkillRecommendations',
    component: view('AiSkill', 'SkillRecommendations'),
    meta: { title: '技能推荐', requiresAuth: true, module: 'ai-skill', permission: 'ai.capability.view' },
  },
  {
    path: 'ai-skills/training',
    name: 'AiSkillTraining',
    component: view('AiSkill', 'SkillTraining'),
    meta: { title: '训练工作台', requiresAuth: true, module: 'ai-skill', permission: 'ai.capability.view' },
  },
  {
    path: 'ai-skills/:skillKey/:version/run',
    name: 'AiSkillRun',
    component: view('AiSkill', 'SkillRun'),
    meta: { title: 'Skill 运行', requiresAuth: true, module: 'ai-skill', permission: 'ai.capability.view' },
  },
  {
    path: 'ai-skills/:skillKey/:version',
    name: 'AiSkillDetail',
    component: view('AiSkill', 'SkillDetail'),
    meta: { title: '技能详情', requiresAuth: true, module: 'ai-skill', permission: 'ai.capability.view' },
  },
]

export default routes
