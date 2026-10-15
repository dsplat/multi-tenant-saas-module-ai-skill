const routes = [
  {
    path: 'ai-skill-admin',
    name: 'ai-skill-admin-overview',
    component: () => import('./ui/element-plus/views/AiSkillAdminOverview.vue'),
    meta: {
      title: 'AI Skill 治理',
      requiresAuth: true,
      module: 'ai-skill',
      // 平台权限由集成者播种；不套用租户 setting.update 冒充平台管理权
      menu: {
        section: 'AI 技能治理',
        label: '治理总览',
        icon: 'Monitor',
        perm: 'platform.ai.skill.view',
      },
    },
  },
  {
    path: 'ai-skill-admin/versions',
    name: 'ai-skill-admin-versions',
    component: () => import('./ui/element-plus/views/AiSkillAdminVersions.vue'),
    meta: {
      title: '公共版本治理',
      requiresAuth: true,
      module: 'ai-skill',
      menu: {
        section: 'AI 技能治理',
        label: '公共版本',
        icon: 'Files',
        perm: 'platform.ai.skill.view',
      },
    },
  },
  {
    path: 'ai-skill-admin/training',
    name: 'ai-skill-admin-training',
    component: () => import('./ui/element-plus/views/AiSkillAdminTraining.vue'),
    meta: {
      title: '平台训练与审核',
      requiresAuth: true,
      module: 'ai-skill',
      menu: {
        section: 'AI 技能治理',
        label: '训练与审核',
        icon: 'DataAnalysis',
        perm: 'platform.ai.skill.view',
      },
    },
  },
]

export default routes
