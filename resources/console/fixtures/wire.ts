/**
 * Wire fixtures — 与 AiSkillCatalogApiTest / SkillCatalogController 实际响应同构。
 * 生成依据：controller 成功封装 `{success,data}`、SkillDefinition::toArray（content_body hidden）、
 * 目录附加 content_status、详情额外注入 content_body、收藏 list 附加 reference_status/reason。
 * 禁止按设计文档臆造字段。
 */

import type {
  SkillCatalogItem,
  SkillDefinitionDetail,
  SkillFavorite,
} from '../../api/skill'

export const catalogListSuccess = {
  success: true,
  data: [
    {
      skill_definition_id: '9121000000000001',
      skill_key: 'module.api',
      version: '1.0.0',
      scope_type: 'module',
      scope_id: 0,
      owner_type: null,
      owner_id: null,
      execution: 'instructions',
      title: 'API sample',
      description: null,
      input_schema: null,
      applicability: null,
      tool_deps: null,
      risk: null,
      success_criteria: null,
      content_ref: 'skills/module.api/1.0.0',
      content_sha256: 'a'.repeat(64),
      status: 'published',
      created_at: '2026-10-14T10:00:00.000000Z',
      updated_at: '2026-10-14T10:00:00.000000Z',
      content_status: 'ready',
    },
    {
      skill_definition_id: '9121000000000002',
      skill_key: 'tenant.method',
      version: '1.0.0',
      scope_type: 'tenant',
      scope_id: 9121,
      owner_type: 'operator',
      owner_id: 1001,
      execution: 'chain',
      title: 'Tenant reviewed method',
      description: '本期学习跟进准备',
      input_schema: {
        type: 'object',
        properties: {
          overview_first: {
            type: 'boolean',
            'x-favorite-default': true,
            enum: [true, false],
          },
        },
      },
      applicability: ['cohort_ready'],
      tool_deps: ['cohort.roster.read'],
      risk: { level: 'low' },
      success_criteria: { type: 'checklist' },
      content_ref: 'skills/tenant.method/1.0.0',
      content_sha256: 'b'.repeat(64),
      status: 'published',
      created_at: '2026-10-14T10:00:00.000000Z',
      updated_at: '2026-10-14T10:00:00.000000Z',
      content_status: 'ready',
    },
    {
      skill_definition_id: '9121000000000003',
      skill_key: 'module.broken',
      version: '1.0.0',
      scope_type: 'module',
      scope_id: 0,
      execution: 'mixed',
      title: 'Broken snapshot',
      content_ref: 'skills/module.broken/1.0.0',
      content_sha256: null,
      status: 'published',
      content_status: 'missing_or_corrupt',
    },
  ] satisfies SkillCatalogItem[],
} as const

export const definitionDetailSuccess = {
  success: true,
  data: {
    skill_definition_id: '9121000000000002',
    skill_key: 'tenant.method',
    version: '1.0.0',
    scope_type: 'tenant',
    scope_id: 9121,
    execution: 'chain',
    title: 'Tenant reviewed method',
    description: '本期学习跟进准备',
    input_schema: {
      type: 'object',
      properties: {
        overview_first: {
          type: 'boolean',
          'x-favorite-default': true,
          enum: [true, false],
        },
      },
    },
    applicability: ['cohort_ready'],
    tool_deps: ['cohort.roster.read'],
    risk: { level: 'low' },
    success_criteria: { type: 'checklist' },
    content_ref: 'skills/tenant.method/1.0.0',
    content_sha256: 'b'.repeat(64),
    status: 'published',
    content_status: 'ready',
    content_body: '# 方法\n\n1. 取样\n2. 校对\n',
  } satisfies SkillDefinitionDetail,
} as const

export const favoritesListSuccess = {
  success: true,
  data: [
    {
      skill_favorite_id: '9121000000000101',
      tenant_id: 9121,
      operator_id: 1001,
      scope: 'personal',
      skill_key: 'tenant.method',
      version: '1.0.0',
      allowed_defaults: { overview_first: true },
      label: null,
      created_at: '2026-10-14T11:00:00.000000Z',
      updated_at: '2026-10-14T11:00:00.000000Z',
      reference_status: 'available',
      reason: null,
    },
    {
      skill_favorite_id: '9121000000000102',
      tenant_id: 9121,
      operator_id: 0,
      scope: 'tenant',
      skill_key: 'tenant.method',
      version: '1.0.0',
      allowed_defaults: {},
      label: '共享跟进',
      created_at: '2026-10-14T11:00:00.000000Z',
      updated_at: '2026-10-14T11:00:00.000000Z',
      reference_status: 'unavailable',
      reason: 'version_conflict',
    },
  ] satisfies SkillFavorite[],
} as const

export const favoriteSaveSuccess = {
  success: true,
  data: {
    skill_favorite_id: '9121000000000103',
    tenant_id: 9121,
    operator_id: 1001,
    scope: 'personal',
    skill_key: 'module.api',
    version: '1.0.0',
    allowed_defaults: {},
    label: null,
    created_at: '2026-10-14T11:05:00.000000Z',
    updated_at: '2026-10-14T11:05:00.000000Z',
  },
} as const

export const favoriteDeleteSuccess = { success: true } as const

export const changeStatusSuccess = {
  success: true,
  data: {
    skill_definition_id: '9121000000000002',
    skill_key: 'tenant.method',
    version: '1.0.0',
    scope_type: 'tenant',
    scope_id: 9121,
    execution: 'chain',
    title: 'Tenant reviewed method',
    status: 'disabled',
    content_status: 'ready',
  } satisfies Partial<SkillCatalogItem>,
} as const

export const errorScopeDenied = {
  success: false,
  message: 'scope_denied',
  reason: 'scope_denied',
} as const

export const errorVersionConflict = {
  success: false,
  message: 'version_conflict',
  reason: 'version_conflict',
} as const

export const errorValidation = {
  success: false,
  message: 'The given data was invalid.',
  errors: { limit: ['The limit field must not be greater than 100.'] },
} as const

export const errorBudgetExceeded = {
  success: false,
  message: 'budget_exceeded',
  reason: 'budget_exceeded',
} as const

// ---- C 固定执行 wire（与 SkillRunController::payload 同构） ----

export const runStartSuccess = {
  success: true,
  data: {
    skill_run_id: '9121000000001001',
    skill_key: 'module.api.prepare',
    skill_version: '1.0.0',
    revision: 1,
    status: 'running',
    current_step: 0,
    steps: [
      { name: 'apply_method', type: 'input', status: 'pending', output_key: null },
      { name: 'record_outcome', type: 'verify', status: 'pending', output_key: null },
    ],
    context: {
      variant_key: 'overview_first',
      execution: 'instructions',
      content_sha256: 'b'.repeat(64),
      input: { display: 'compact' },
    },
    outcome: null,
    reason: null,
    next_action: 'advance',
    warnings: [
      {
        code: 'instructions_path',
        message: 'instructions 路径交给小秘书协助，不计入固定链零规划指标。',
      },
    ],
  },
} as const

export const runAdvanceWaitingInput = {
  success: true,
  data: {
    skill_run_id: '9121000000001001',
    skill_key: 'module.api.prepare',
    skill_version: '1.0.0',
    revision: 2,
    status: 'waiting_input',
    current_step: 0,
    steps: [
      { name: 'apply_method', type: 'input', status: 'pending', output_key: null },
      { name: 'record_outcome', type: 'verify', status: 'pending', output_key: null },
    ],
    context: {
      variant_key: 'overview_first',
      execution: 'instructions',
      content_sha256: 'b'.repeat(64),
      input: { display: 'compact' },
    },
    outcome: null,
    reason: null,
    next_action: 'submit_step_input',
    warnings: [],
  },
} as const

export const runAdvanceCompleted = {
  success: true,
  data: {
    skill_run_id: '9121000000001001',
    skill_key: 'module.api.prepare',
    skill_version: '1.0.0',
    revision: 4,
    status: 'completed',
    current_step: 2,
    steps: [
      { name: 'apply_method', type: 'input', status: 'completed', output_key: null },
      { name: 'record_outcome', type: 'verify', status: 'completed', output_key: null },
    ],
    context: {
      variant_key: 'overview_first',
      execution: 'instructions',
      content_sha256: 'b'.repeat(64),
      input: { display: 'compact' },
    },
    outcome: 'success',
    reason: null,
    next_action: null,
    warnings: [],
  },
} as const

export const runUncertain = {
  success: true,
  data: {
    skill_run_id: '9121000000001002',
    skill_key: 'module.api.chain',
    skill_version: '1.0.0',
    revision: 2,
    status: 'uncertain',
    current_step: 0,
    steps: [{ name: 'apply_method', type: 'tool', status: 'pending', output_key: null }],
    context: {
      variant_key: null,
      execution: 'chain',
      content_sha256: 'c'.repeat(64),
      input: {},
    },
    outcome: 'uncertain',
    reason: 'tool_incompatible',
    next_action: 'reconcile',
    warnings: [
      { code: 'result_uncertain', step: 0, message: 'Step result unknown; reconcile before retry.' },
    ],
  },
} as const

export const runCancelSuccess = {
  success: true,
  data: {
    skill_run_id: '9121000000001001',
    skill_key: 'module.api.prepare',
    skill_version: '1.0.0',
    revision: 3,
    status: 'cancelled',
    current_step: 0,
    steps: [],
    context: {},
    outcome: 'cancelled',
    reason: 'cancelled',
    next_action: null,
    warnings: [],
  },
} as const

export const runListSuccess = {
  success: true,
  data: [
    {
      skill_run_id: '9121000000001001',
      skill_key: 'module.api.prepare',
      skill_version: '1.0.0',
      revision: 1,
      status: 'waiting_input',
      current_step: 0,
      steps: [],
      context: { variant_key: 'overview_first', execution: 'instructions' },
      outcome: null,
      reason: null,
      next_action: 'submit_step_input',
      warnings: [],
    },
    {
      skill_run_id: '9121000000001002',
      skill_key: 'module.api.chain',
      skill_version: '1.0.0',
      revision: 2,
      status: 'uncertain',
      current_step: 0,
      steps: [],
      context: { execution: 'chain' },
      outcome: 'uncertain',
      reason: 'tool_incompatible',
      next_action: 'reconcile',
      warnings: [],
    },
  ],
} as const

export const errorRevisionConflict = {
  success: false,
  message: 'Run revision conflict; refresh and retry.',
  reason: 'version_conflict',
} as const

// ---- D 推荐与事件 wire ----

export const recommendationGenerateSuccess = {
  success: true,
  data: {
    items: [
      {
        recommendation_id: '9121000000002001',
        skill_key: 'module.api.prepare',
        version: '1.0.0',
        candidate_id: '9121000000003001',
        variant_key: 'overview_first',
        context: { purpose: 'study_followup', variant_key: 'overview_first' },
        reason: { source: 'validated_candidate', risk_level: 'low', business_write: false },
        position: 0,
        grants_execution: false,
      },
      {
        recommendation_id: '9121000000002002',
        skill_key: 'module.rec.b',
        version: '1.0.0',
        candidate_id: null,
        variant_key: null,
        context: { purpose: 'study_followup' },
        reason: { source: 'favorite', scope: 'personal', grants_execution: false },
        position: 1,
        grants_execution: false,
      },
    ],
    meta: { count: 2, purpose: 'study_followup', grants_execution: false },
  },
} as const

export const recommendationListSuccess = {
  success: true,
  data: [
    {
      recommendation_id: '9121000000002001',
      skill_key: 'module.api.prepare',
      version: '1.0.0',
      candidate_id: '9121000000003001',
      variant_key: 'overview_first',
      context: {},
      reason: { source: 'validated_candidate' },
      position: 0,
      grants_execution: false,
    },
  ],
} as const

export const recommendationDetailAfterExpose = {
  success: true,
  data: {
    recommendation_id: '9121000000002001',
    skill_key: 'module.api.prepare',
    version: '1.0.0',
    candidate_id: '9121000000003001',
    position: 0,
    reason: { source: 'validated_candidate' },
    events: {
      exposed_at: '2026-10-14T10:00:00.000000Z',
      clicked_at: null,
      adopted_at: null,
      ignored_at: null,
      executed_at: null,
      completed_at: null,
    },
    last_event: 'expose',
    grants_execution: false,
  },
} as const

export const recommendationDetailAfterComplete = {
  success: true,
  data: {
    recommendation_id: '9121000000002001',
    skill_key: 'module.api.prepare',
    version: '1.0.0',
    candidate_id: null,
    position: 0,
    reason: { source: 'favorite', last_run_id: '9121000000001001', last_outcome: 'success' },
    events: {
      exposed_at: '2026-10-14T10:00:00.000000Z',
      clicked_at: '2026-10-14T10:01:00.000000Z',
      adopted_at: '2026-10-14T10:02:00.000000Z',
      ignored_at: null,
      executed_at: '2026-10-14T10:03:00.000000Z',
      completed_at: '2026-10-14T10:04:00.000000Z',
    },
    last_event: 'complete',
    grants_execution: false,
  },
} as const

export const trainingStartSuccess = {
  success: true,
  data: { training_run_id: '9121000000004001', status: 'queued' },
} as const

export const trainingListSuccess = {
  success: true,
  data: [
    {
      training_run_id: '9121000000004001',
      mode: 'history',
      status: 'queued',
      dataset_manifest: { source_types: ['audit_logs'], max_samples: 100 },
      config_fingerprint: 'cfg-1',
      prompt_fingerprint: 'prompt-1',
      model_ref: null,
      budget: { max_calls: 10 },
      cost_micro: null,
      cost_unknown: true,
      error: null,
      created_at: '2026-10-14T09:00:00.000000Z',
      updated_at: '2026-10-14T09:00:00.000000Z',
    },
  ],
} as const

export const candidateListSuccess = {
  success: true,
  data: [
    {
      candidate_id: '9121000000003001',
      training_run_id: '9121000000004001',
      candidate_key: 'cand-1',
      status: 'validated',
      skill_draft: {
        skill_key: 'module.api.prepare',
        version: '1.1.0',
        risk: { level: 'low', business_write: false },
      },
      evidence_refs: ['exp:1'],
      validation: { passed: true, holdout_score: 0.9 },
      adoption: null,
      bound_definition_id: null,
    },
  ],
} as const

export const candidateAdopted = {
  success: true,
  data: {
    candidate_id: '9121000000003001',
    training_run_id: '9121000000004001',
    candidate_key: 'cand-1',
    status: 'adopted',
    skill_draft: { skill_key: 'module.api.prepare', version: '1.1.0' },
    evidence_refs: ['exp:1'],
    validation: { passed: true },
    adoption: {
      decision: 'auto',
      reason: 'low_risk_equivalent_readonly',
      limited_tenant_ids: [9401],
    },
    bound_definition_id: null,
  },
} as const

export const candidateNeedsReview = {
  success: false,
  message: 'Candidate is not auto-adoptable; human review required.',
  reason: 'review_required',
} as const
