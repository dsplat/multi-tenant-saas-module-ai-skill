<template>
  <div class="page">
    <div class="page-header">
      <h2>平台训练与审核</h2>
    </div>

    <el-alert
      type="warning"
      :closable="false"
      show-icon
      title="平台侧主动训练入口待 Admin 后端冻结。不默认全租户采样；文档上传契约未交付前只规划有界纯文本/已授权材料引用。候选采用裁决只来自服务端 AdoptionPolicy。"
      style="margin-bottom: 16px"
    />

    <el-row :gutter="16">
      <el-col :span="12">
        <el-card shadow="never">
          <template #header><span>主动训练（布局）</span></template>
          <el-form label-width="110px">
            <el-form-item label="授权租户">
              <el-input disabled placeholder="显式选择，禁止默认租户" />
            </el-form-item>
            <el-form-item label="授权来源">
              <el-select disabled multiple placeholder="模块材料 / 历史轨迹 / 收藏 / 失败记录" style="width: 100%" />
            </el-form-item>
            <el-form-item label="时间范围">
              <el-date-picker disabled type="daterange" style="width: 100%" />
            </el-form-item>
            <el-form-item label="样本上限">
              <el-input-number disabled :min="1" :max="500" />
            </el-form-item>
            <el-form-item label="预算上限">
              <el-input disabled placeholder="调用 / token / 费用" />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" disabled>预览脱敏样本</el-button>
              <el-button disabled>启动</el-button>
              <el-button disabled>取消</el-button>
            </el-form-item>
          </el-form>
        </el-card>
      </el-col>

      <el-col :span="12">
        <el-card shadow="never">
          <template #header><span>候选审核（布局）</span></template>
          <el-table :data="[]" empty-text="等待 Admin 审核 API">
            <el-table-column prop="candidate_id" label="候选" width="120" />
            <el-table-column prop="baseline" label="基线版本" width="110" />
            <el-table-column prop="proposed" label="拟采用" width="110" />
            <el-table-column prop="risk" label="权限/风险" min-width="120" />
            <el-table-column prop="decision" label="策略" width="120">
              <template #default>
                <el-tag size="small" type="info">auto / needs_review / denied</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="120">
              <template #default>
                <el-button link disabled>通过</el-button>
                <el-button link disabled>拒绝</el-button>
              </template>
            </el-table-column>
          </el-table>

          <el-alert
            type="info"
            :closable="false"
            show-icon
            title="界面只展示策略结果；不勾选跳过评测、不绕过 AdoptionPolicy。"
            style="margin-top: 12px"
          />
        </el-card>
      </el-col>
    </el-row>

    <el-card shadow="never" style="margin-top: 16px">
      <template #header><span>审核字段清单（契约冻结后逐项接线）</span></template>
      <el-descriptions :column="2" border>
        <el-descriptions-item label="样本来源与脱敏预览">inspect-body / 字段白名单</el-descriptions-item>
        <el-descriptions-item label="留出评测">≥8/10 且不低于基线</el-descriptions-item>
        <el-descriptions-item label="方法/步骤差异">可读 diff，不回放生产副作用</el-descriptions-item>
        <el-descriptions-item label="成本事实">缺失显示 unknown，不显示 0</el-descriptions-item>
        <el-descriptions-item label="状态机">queued / running / completed / failed / cancelled + 结果不明</el-descriptions-item>
        <el-descriptions-item label="采用结果">auto / needs_review / denied + reason</el-descriptions-item>
      </el-descriptions>
    </el-card>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'AiSkillAdminTraining' })
</script>

<style scoped>
.page-header {
  margin-bottom: 16px;
}
</style>
