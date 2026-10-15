<template>
  <div class="page">
    <div class="page-header">
      <h2>AI Skill 治理总览</h2>
    </div>

    <el-alert
      type="info"
      :closable="false"
      show-icon
      title="公共版本页已接平台专用API，支持启停已有发布版本。训练和人工审核仍待处理器及审核契约完成；不自动读取全租户资料。"
      style="margin-bottom: 16px"
    />

    <el-row :gutter="16">
      <el-col :span="12">
        <el-card shadow="never">
          <template #header><span>能力门</span></template>
          <el-descriptions :column="1" border>
            <el-descriptions-item label="公共版本治理">
              <el-tag type="info">已接公共版本API</el-tag>
              <span class="hint">发布/停用需独立平台 RBAC + 审计</span>
            </el-descriptions-item>
            <el-descriptions-item label="显式授权租户训练">
              <el-tag type="info">待 Admin 后端</el-tag>
              <span class="hint">不默认全租户采样</span>
            </el-descriptions-item>
            <el-descriptions-item label="候选审核">
              <el-tag type="warning">服务端策略已备</el-tag>
              <span class="hint">Admin 审核台待对接 AdoptionPolicy</span>
            </el-descriptions-item>
            <el-descriptions-item label="租户 Console（目录/收藏/运行/推荐）">
              <el-tag type="success">已交付</el-tag>
              <span class="hint">走租户 API，不在此复用</span>
            </el-descriptions-item>
            <el-descriptions-item label="平台权限点">
              <el-tag type="warning">待播种</el-tag>
              <span class="hint">platform.ai.skill.view / platform.ai.skill.manage</span>
            </el-descriptions-item>
          </el-descriptions>
        </el-card>
      </el-col>

      <el-col :span="12">
        <el-card shadow="never">
          <template #header><span>后续页面责任（布局占位）</span></template>
          <el-timeline>
            <el-timeline-item timestamp="公共版本" placement="top">
              列表 / 详情只读 + 发布质量字段；写操作等独立 Admin API
            </el-timeline-item>
            <el-timeline-item timestamp="训练工作台" placement="top">
              明确获授权租户 → 脱敏预览 → 预算 → 启动/取消；文档上传契约未交付前只开有界纯文本
            </el-timeline-item>
            <el-timeline-item timestamp="候选审核" placement="top">
              基线/拟采用差异、留出评测、权限风险；auto/needs_review/denied 只读展示
            </el-timeline-item>
            <el-timeline-item timestamp="审计" placement="top">
              采样/采用/发布最小审计脱敏展示；不由 UI 删除事实日志
            </el-timeline-item>
          </el-timeline>
        </el-card>
      </el-col>
    </el-row>

    <el-card shadow="never" style="margin-top: 16px">
      <template #header><span>硬约束（设计不变量）</span></template>
      <ul class="rules">
        <li>不存在默认租户；Admin 选租户必须显式授权，平台公共 scope 不是业务租户 fallback。</li>
        <li>平台身份不自动获得所有租户日志、私人收藏或原始提示词访问权。</li>
        <li>低风险等价只读变体的 auto 采用由服务端 AdoptionPolicy 裁决，界面不自行计算。</li>
        <li>训练完成 ≠ 候选通过 ≠ 上线；点击/曝光 ≠ 成功。</li>
        <li>mock 数据不计入联调证据；本稿仅供布局审阅。</li>
      </ul>
    </el-card>
  </div>
</template>

<script setup lang="ts">
defineOptions({ name: 'AiSkillAdminOverview' })
</script>

<style scoped>
.page-header {
  margin-bottom: 16px;
}
.hint {
  margin-left: 8px;
  color: var(--el-text-color-secondary);
  font-size: 12px;
}
.rules {
  margin: 0;
  padding-left: 18px;
  line-height: 1.9;
  color: var(--el-text-color-regular);
}
</style>
