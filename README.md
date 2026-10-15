# AiSkill：数据库 Skill 与后台

更新：2026-10-15。本文件描述本地实现；生产仍为2026-10-14的B/E基线，未部署本轮代码。总体设计见 `docs/ai-harness-design.md`，后台分工与测试见 `docs/ai-skill-backoffice-playbook.md`。

## 版本库与收藏

module/project/tenant 定义均将完整 `content_body` 与 `content_sha256` 存入数据库，`content_ref=sha256:<digest>`。多Web节点共享数据库；运行不读取Skill资源目录。发布/停用版本语义不可原地修改，不追随最新版。

部署可用 `ai-skill:import <root> --scope=module|project` 导入审核过的 `resources/skills/catalog.json`。模块命名空间来自composer `extra.saas.name`，项目可用 `extra.ai_skill.name`；目录文件仅供导入，不是运行事实源。同版本同正文重放，冲突整批回滚，重放不恢复停用版本。

Console目录/详情/收藏/租户版本管理在 `resources/console/`，typed API在 `api/skill.ts`。`/api/v1/tenant/ai-skills` 复用认证、明确租户、模块开关与 `ai.capability.view`；收藏仅存确定版本和schema允许的有限enum偏好，共享写额外需 `setting.update`。正文损坏明确不可用，不读文件补救。目录最多100条，无服务端无限分页。

## 固定只读执行：真实Harness，默认不开放工具

新增核心公共契约 `TaskChainHarnessContract`，由Ai模块的既有 `TaskChainRunner` 实现，AiSkill不import Ai内部服务/模型。**核心、Ai及AiSkill须匹配升级，最低核心2.13.1**；不能只更新AiSkill到新版本而使用生产旧核心。

可执行正文必须是数据库中的JSON：

```json
{
  "schema_version": 1,
  "chain": {
    "steps": [
      {"name": "读取事实", "type": "tool", "tool": "approved_read_tool", "args": {}, "output_key": "facts"}
    ]
  }
}
```

- 首批只支持tool步骤，最多50步。工具必须在Skill的tool_deps声明、实际注册为L1并面向operator/both、租户启用且在服务端 `ai.task_chains.pinned_read_tools` 审核白名单内。白名单默认空；L1标签本身不能证明只读/无模型费用，不由候选或客户端扩白名单。
- start固定正文摘要、输入、方法及计划；TaskChainRun的steps_state保存执行定义快照和工具实现/参数schema指纹。恢复使用快照，不读现行Registry定义；工具风险/实现变化拒绝继续。
- `ai_skill_runs` 是版本/主体/revision/幂等封套，`task_chain_run_id` 指向既有执行状态。advance通过Runner和ToolRegistry真实执行，不接收浏览器tool_status/pass/output作为证据；旧任务链回填入口不能推进固定Skill运行。
- 运行写操作额外需 `ai.capability.execute`。目录可见不授予执行权；读取、推进和取消限当前租户及运行本人。服务端计算包含Skill/版本/方法/输入/主体的指纹，忽略客户端自报指纹。
- instructions保留为小秘书方法说明；此固定运行台不能将其手动标记成功。既有助手探索、建议下一步和原任务链入口保留。
- L2、delegate、upload暂不开放到此固定路径，缺可信确认/预算/执行证据适配时拒绝，不增加假确认按钮。原小秘书确认链不受影响。
- 工具调用完成仅证明执行步骤完成，业务结果仍为incomplete/verification_missing；没有独立业务判据校验器时不能算success。异常结果不明置uncertain，禁止自动重放。

API：POST `/runs`、GET `/runs`及`/runs/{id}`、POST `/runs/{id}/advance|cancel`，写状态携带expected_revision；同request_key同输入重放，异输入409。Console运行台读取服务端结果引用/输出，推进发送空step_input，不自行编排工具或回填成功。

## 候选采用与数据库发布

租户主动训练/取消/候选裁决写API额外需 `setting.update`，目录权限不授训练采用权。训练任务登记、候选/推荐API已实现；**异步LLM训练处理器与独立评测尚未接齐**。UI明确“登记训练任务”，queued不代表模型已运行。测试用受控登记候选不代表真实模型闭环。

`evaluate`只评估；`adopt`才可发布。AdoptionPolicy从持久化validation指向的 `baseline_definition_id` 读取可见、已发布且正文校验通过的基线，忽略HTTP提交的baseline/限定租户。自动采用至少要求非空证据、passed、留出样本≥10、评分≥0.8且不低于基线、严重错误=0、风险已知且只读/不扩工具范围。写变更或范围/确认变化需审；缺证据拒绝。这里的评测字段须由后续受控独立评测器写入，不能让模型自评；当前没有宣称评测闭环完成。

CandidatePublication在候选行锁和同一事务内发布 `tenant.<tenant_id>.*` 新版本，生成数据库正文、SHA256及content_ref，绑定bound_definition_id后才标adopted。不能替租户发布公共module/project版本。目标版本已存在报409并回滚，不覆盖/重启停用版本；重复采用不重复创建，已裁决候选不能被训练重试覆盖。自动采用与运行授权分开。

推荐曝光/点击/采用/忽略分别留痕，不作为业务成功证据；execute/complete必须匹配本租户、本人员、本Skill版本的真实Harness run，完成结果取服务端记录，忽略客户端success。缺run或instructions伪回填拒绝。当前排序/事件不是基于真实效果训练出的推荐算法。

## Admin公共版本与范围授权

公共版本页已真实接线 `/api/v1/admin/ai-skills/versions` 和PATCH `/versions/{id}/status`，提供列表、乐观启停、冲突刷新与安全错误提示；不提供无审核的新版本发布按钮。管理按钮需 `platform.ai.skill.manage`，列表需view；后端再次检查平台Operator及权限，模块关闭时拒绝。Admin训练/审核页仍是禁用布局，不记为完成。

新增增量权限迁移 `2026_10_15_000001_add_ai_skill_admin_permissions`，与PermissionSeeder一致，仅给super_admin/platform_admin全局角色，不授tenant_admin/member平台权限。

平台权限不隐含所有租户数据权限。租户版本/候选/训练操作另需明确tenant_id及服务端 `ai-skill.platform_tenant_scopes.<platform_operator_id>` 整数租户白名单，默认空。无目标租户拒绝，不自动读取全部候选。公共scope_id=0不是默认业务租户。治理写操作和最小审计处于同一事务，审计失败不静默放行。

## 发布与验收边界

备份→增量迁移（运行、训练request_key、推荐事件、平台权限）→匹配核心/Ai/AiSkill包→SCRM锁文件→种子导入→Admin/Console构建→缓存/队列。默认关闭，显式试点范围与工具白名单经核验后开放；不得因本地测试通过自动生产启用。回退关入口/训练并保留数据，不drop表、不重做已发生动作。

本轮有真实Runner/ToolRegistry测试、HTTP伪造结果拒绝、采用与发布原子性/幂等、后台客户端及构建验证；精确结果见台账最新记录。**仍未完成**：真实浏览器、现场试点、L2可信确认适配、独立业务结果验证、真实LLM候选与评测、人工审核通过路径、SCRM活动页入口。MySQL并发锁门已在本机隔离库执行通过（见下方放行标准表与 `tests/AiSkillMySqlConcurrencyTest.php`）。旧HTTP测试接受浏览器pass=true的“闭环”已改为拒绝断言，不再作为BL-147完成证据。

本轮最终本地回归：PHP170 tests / 598 assertions（含原Agent/Headless/任务链/角色权限），无失败，8项MySQL跳过、14项弃用提示；Console60、Admin24例；双端Element Plus、Console Bootstrap构建及全量架构守卫/Pint通过。隔离MySQL另跑 8 tests / 101 assertions 全绿。单测和构建不替代真实浏览器、跨仓消费与现场验收。

## 必须执行的测试与放行标准

| 测试门 | 执行要求 | 通过标准 / 当前状态 |
|---|---|---|
| PHP相关回归 | 执行下方根目录PHP回归命令 | 无失败；伪造结果/越权/过期版本拒绝、真实工具调用、原探索/确认入口不退化；本轮170项/598断言，8项MySQL跳过（须另按下方MySQL门真跑），不能视作全部环境已验收 |
| 前端单测 | 分别进入 `resources/js/console`、`resources/js/admin` 执行 `npm test` | 本轮60/24项通过；检查不透明ID、错误脱敏、revision与API契约；客户端mock不证明实际页面可用 |
| 构建 | Console `npm run build:element-plus`、`npm run build:bootstrap`；Admin `npm run build` | 均成功；模块路由与资源能解析，Bootstrap回退页面不复制业务逻辑 |
| 静态/文档 | `vendor/bin/pint --test`（本轮PHP文件）；`python3 scripts/architecture_guard.py --all`；`python3 scripts/gen_module_catalog.py --check`；`python3 scripts/docs_manifest.py --check`；`git diff --check` | 无新增失败，文档与目录登记一致；本轮通过 |
| 隔离MySQL，发布阻断门 | 隔离实例执行真实迁移两次；8路同键start/采用、异输入、并发advance/cancel；注入发布失败和事务回滚（命令见下） | 只创建1个运行/发布版本；同键同输入重放、异输入409；旧revision不推进；不重复调用工具；失败不出现adopted却无绑定版本。旧B导入并发证据不能替代新C/D测试。本轮已在本机 MySQL 8.4.8 专用库 `multi_tenant_saas_test` 全绿：`AiSkillMySqlIntegrationTest` 3例/32断言 + `AiSkillMySqlConcurrencyTest` 5例/69断言（真实 HTTP + `pcntl_fork` 8路并发）；拆掉 `lockForUpdate` 的变异可复现 1062 重复键 5xx 与 8 路全 200，证明断言有牙齿。**脏库不作数**：残留行使迁移用例首跑假失败，须在干净实例上跑；生产实例现场迁移与试点仍未执行 |
| 真实后台，试点阻断门 | 实际Admin/Console会话：目录→详情→私人/共享收藏；公共启停及409；真实只读运行/刷新/取消；切租户/换人员期间旧请求晚返回；无权限直接访问API | 同租户他人私人信息及跨租户信息均不可见；无默认租户；无执行权/训练管理权拒绝；损坏/停用版本不执行，真实页面无假成功；保存截图及HTTP/版本证据。本轮未完成 |
| 跨仓升级/回退，发布阻断门 | 实际split包与匹配核心≥2.13.1、Ai/AiSkill同步安装；SCRM构建；迁移/导入幂等；关闭新入口并回退匹配程序 | 提供者绑定、目录及权限正常，原助手/原链仍可用；不drop数据、不重放已执行动作。本轮未完成 |
| 训练与效果，BL-147关闭门 | 受控真实LLM候选、独立评测、自动采用及需审核拒绝/通过；保留发现集外至少10例，同路径前后各3次 | 事实正确≥8/10且不低于基线，新增严重错误/越权/隐私泄露=0；固定路径编排LLM=0，可比编排调用减少≥50%；费用未知不宣称省钱。假样本/状态流转不证明效果；本轮未完成 |

并发、浏览器、跨仓和训练效果任一对应门未通过，不能将其标记完成或开放对应试点。执行方交付命令、环境/版本、通过/失败/跳过数及失败证据；待办状态仅更新主台账 [backlog](../../../docs/governance/backlog.md)，不得以本文件另建第二份任务列表。

PHP回归命令（框架根目录）：

```bash
vendor/bin/phpunit --filter 'AiSkill|TaskChainTest|RolePermissionSeederTest|AgentRuntimeTest|AgentRuntimeConfirmTest|HeadlessAgentTest|ModuleDependencyGateTest'
```

隔离MySQL门命令（须指向专用测试库；库名不以 `_test`/`_testing` 结尾时 `TestCase` 会直接拒绝，防误清开发库）：

```bash
TESTING_DB_CONNECTION=mysql DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=<隔离库> DB_USERNAME=<测试账号> DB_PASSWORD=<测试口令> \
php -d memory_limit=512M vendor/bin/phpunit \
  tests/AiSkillMySqlIntegrationTest.php tests/AiSkillMySqlConcurrencyTest.php
```

实测：`TESTING_DB_CONNECTION=mysql` 缺省时 `TestCase` 钉回 sqlite、用例静默跳过（1 skipped），设置后即真实连 MySQL（PHP CLI 会填 `$_SERVER`，无需额外 `php -d` 开关）。库名守卫只放行 `_test`/`_testing` 结尾或 `TESTING_ALLOWED_MYSQL_DATABASES` 显式列出的库。
