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

租户主动训练/取消/候选裁决写API额外需 `setting.update`，目录权限不授训练采用权。训练任务登记、候选/推荐API已实现；异步LLM训练处理器（`SkillTrainer` + `RunSkillTrainingJob`，WP-147a）与独立评测器（`CandidateEvaluator` + `RunCandidateEvaluationJob`，WP-147b）代码已接齐，但由 `ai-skill.training.enabled` / `ai-skill.evaluation.enabled` 两个执行器开关驱动，默认关闭——**登记任务与真实烧token是两个动作**，开关未开时补记不投递、评测入口直接拒绝。UI明确“登记训练任务”，queued不代表模型已运行。测试用受控登记候选不代表真实模型闭环。

采样窗口在登记时冻结：`TrainingDataset::freeze()` 把请求里的自由格式 manifest 归一成 `source_types/time_from/time_to/field_allowlist/max_samples/access_mode/authorized_tenant_id`，缺窗口即 `data_incomplete` 拒绝，`remote_url`/`file_path` 一律 `scope_denied`；训练器只能按该冻结窗口取证据，授权面不在执行时决定。可用来源见 `ExperienceSourceAdapter`：`audit_logs`、`agent_tool_logs`、`task_chain_runs`、`ai_audit_logs`、`skill_experiences`。

冻结窗口存 ISO-8601（带 `T` 与 `Z`/偏移），绑定进 SQL 前必须换成应用时区的 `Y-m-d H:i:s`：直接绑自由格式串会退化成字典序（`2026-10-13 09:30:00` < `2026-10-13T00:00:00+08:00`），与样本同一天的窗口恒空，训练会静默拿到 0 条证据却不报错。`ExperienceSourceAdapter::sqlBound()` 是唯一的转换点，五个采样来源共用；换算后的时刻参与比较，跨时区表达同一窗口必须命中同一批样本（回归：`AiSkillModelsAndAdapterTest`）。

`evaluate`只评估；`adopt`才可发布。AdoptionPolicy从持久化validation指向的 `baseline_definition_id` 读取可见、已发布且正文校验通过的基线，忽略HTTP提交的baseline/限定租户。自动采用至少要求非空证据、passed、留出样本≥10、评分≥0.8且不低于基线、严重错误=0、风险已知且只读/不扩工具范围。写变更或范围/确认变化需审；缺证据拒绝。

**validation 的唯一写入方是 `CandidateEvaluator`**：训练器 `proposeCandidate` 只写空数组，任何 HTTP 路径都不接收 validation 字段（源码检索确认只有评测器在候选行锁下赋值）。判据本身由平台治理入口冻结在 `ai_skill_eval_cases`（Admin `POST /api/v1/admin/ai-skills/eval-cases`，需 `platform.ai.skill.manage` 与显式租户范围）：同一 `case_key` 摘要相同幂等、不同即 409 要求另起 `dataset_version`，断言只允许 `contains/not_contains/equals/required_tools/forbidden_tools`，不接受「让模型自己评」；审计只记引用与数量，不落样本正文。评测执行前逐条按 `content_sha256` 复核（改题即 `verification_missing`），执行后复核候选正文摘要（正文被改写则结果作废、不落 validation），写能力候选与非白名单工具在执行前拒绝且零token消耗。评分全为确定性断言，不用 LLM 给 LLM 打分。这仍不等于效果验收：本地用例只证明判据可信与门会拒，真实LLM候选与留出集测量见下方关闭门。

CandidatePublication在候选行锁和同一事务内发布 `tenant.<tenant_id>.*` 新版本，生成数据库正文、SHA256及content_ref，绑定bound_definition_id后才标adopted。不能替租户发布公共module/project版本。目标版本已存在报409并回滚，不覆盖/重启停用版本；重复采用不重复创建，已裁决候选不能被训练重试覆盖。自动采用与运行授权分开。

推荐曝光/点击/采用/忽略分别留痕，不作为业务成功证据；execute/complete必须匹配本租户、本人员、本Skill版本的真实Harness run，完成结果取服务端记录，忽略客户端success。缺run或instructions伪回填拒绝。当前排序/事件不是基于真实效果训练出的推荐算法。

## Admin公共版本与范围授权

公共版本页已真实接线 `/api/v1/admin/ai-skills/versions` 和PATCH `/versions/{id}/status`，提供列表、乐观启停、冲突刷新与安全错误提示；不提供无审核的新版本发布按钮。管理按钮需 `platform.ai.skill.manage`，列表需view；后端再次检查平台Operator及权限，模块关闭时拒绝。

训练与审核页（`AiSkillAdminTraining.vue`，WP-147c）已从禁用布局改为真实入口，覆盖四件事：显式填写授权租户（无默认租户、空值不发请求）、按冻结窗口登记训练并回显运行事实（`GET /admin/ai-skills/training-runs`：状态、`llm_calls`、`cost_micro`/`cost_unknown`/`cost_source`、起止时间、失败原因）、在治理入口冻结判据并列出已冻结用例、对候选发起评测与请求采用/拒绝。客户端只做两件事：把不合规的窗口/断言挡在发出请求之前（缺起止时间、来源越清单、`metadata_only` 带正文字段、`transform_body` 无白名单、断言含 `llm_judge`），以及用 `contains` 断言不发送任何判定字段（`skill_draft`/`validation`/`risk`/跳过评测都不在请求体里）；裁决结论只回自服务端 AdoptionPolicy。切租户或换人员时旧请求晚返回会被代次计数器丢弃，不会把上一个租户的运行显示在当前页面上。

判据冻结/列表（`/admin/ai-skills/eval-cases`）与评测触发（`/admin/ai-skills/candidates/{id}/evaluate`）与上述页面同权限同范围；页面接线不等于验收：真实后台会话、开关开启后的实际花费与效果测量仍是下方试点阻断门，未完成。

新增增量权限迁移 `2026_10_15_000001_add_ai_skill_admin_permissions`，与PermissionSeeder一致，仅给super_admin/platform_admin全局角色，不授tenant_admin/member平台权限。

平台权限不隐含所有租户数据权限。租户版本/候选/训练操作另需明确tenant_id及服务端 `ai-skill.platform_tenant_scopes.<platform_operator_id>` 整数租户白名单，默认空。无目标租户拒绝，不自动读取全部候选。公共scope_id=0不是默认业务租户。治理写操作和最小审计处于同一事务，审计失败不静默放行。

## 发布与验收边界

备份→增量迁移（运行、训练request_key、推荐事件、平台权限）→匹配核心/Ai/AiSkill包→SCRM锁文件→种子导入→Admin/Console构建→缓存/队列。默认关闭，显式试点范围与工具白名单经核验后开放；不得因本地测试通过自动生产启用。回退关入口/训练并保留数据，不drop表、不重做已发生动作。

本轮有真实Runner/ToolRegistry测试、HTTP伪造结果拒绝、采用与发布原子性/幂等、后台客户端及构建验证；精确结果见台账最新记录。**仍未完成**：真实浏览器、现场试点、L2可信确认适配、独立业务结果验证、真实LLM候选与评测、人工审核通过路径、SCRM活动页入口。MySQL并发锁门已在本机隔离库执行通过（见下方放行标准表与 `tests/AiSkillMySqlConcurrencyTest.php`）。旧HTTP测试接受浏览器pass=true的“闭环”已改为拒绝断言，不再作为BL-147完成证据。

本轮最终本地回归：PHP193 tests / 745 assertions（含原Agent/Headless/任务链/角色权限），无失败，8项MySQL跳过、14项弃用提示；WP-147a/b/c 专项42 tests / 184 assertions；Console60、Admin29例；双端Element Plus、Console Bootstrap、Admin三端构建及全量架构守卫/Pint通过；全量套件分4片复跑 5576 例通过 / 18432 断言 / 35 跳过 / 0 失败。隔离MySQL在 `DROP`+`CREATE` 复位（表数0）后的专用库重跑 8 tests / 102 assertions 全绿。单测和构建不替代真实浏览器、跨仓消费与现场验收。

## 必须执行的测试与放行标准

| 测试门 | 执行要求 | 通过标准 / 当前状态 |
|---|---|---|
| PHP相关回归 | 执行下方根目录PHP回归命令 | 无失败；伪造结果/越权/过期版本拒绝、真实工具调用、原探索/确认入口不退化；本轮193项/745断言，8项MySQL跳过（须另按下方MySQL门真跑），不能视作全部环境已验收 |
| 训练与评测实现门（WP-147a/b/c） | `php artisan test tests/AiSkillTrainingTrainerTest.php tests/AiSkillCandidateEvaluationTest.php tests/AiSkillAdminApiTest.php tests/AiSkillTrainingApiTest.php` | 本轮42项/184断言全绿。全绿即证明：训练/评测投递受 `training.enabled`/`evaluation.enabled` 双开关门（关闭时 `Queue::assertNothingPushed`）；训练器永不写 validation、重复投递只领取一次、预算/窗口在执行前拒绝且零token、跨租户与非白名单工具丢弃而非入库、取消后事实仍落库；评测器是 validation 唯一写入方（源码检索确认）、冻结样本改题即 `verification_missing`、正文中途改写则结果作废、写能力/非白名单工具候选执行前拒绝、留出<10例拒绝、固定链路径 `llm_calls=0`/`orchestration_calls=0` 且无token结算；Admin判据冻结幂等、同 `case_key` 改断言409、断言字段白名单拒绝 `llm_judge`、审计不落样本正文、跨租户判据读取靠显式授权而非租户scope；Admin训练运行只读列表 `GET /admin/ai-skills/training-runs` 必带显式 `tenant_id`（缺失422、未授权403、无租户fallback），并把执行事实与状态分开显示：登记后未开启执行器时 `llm_calls` 为 null 且成本 `cost_unknown=true`，跑过才回 `llm_calls`/`cost_micro`/`cost_source`。**变异取证**：拆掉 `commit()` 正文摘要门 → 中途改写用例转红；拆掉冻结摘要复核 → 改题用例转红；拆掉 `withoutGlobalScopes()` → 跨租户读取用例返回0行转红。这些只证明判据可信与门会拒，不证明真实模型效果 |
| 前端单测 | 分别进入 `resources/js/console`、`resources/js/admin` 执行 `npm test` | 本轮60/29项通过；检查不透明ID、错误脱敏、revision与API契约；Admin侧新增治理入口用例：空/0租户一律在发请求前拒绝、幂等键与冻结窗口原样上送、断言字段白名单在前端也拦 `llm_judge`、采用/拒绝请求体不含任何判定字段（`skill_draft`/`validation`/`risk`/跳过评测）、候选ID保持不透明字符串不做数值转换。客户端mock不证明实际页面可用 |
| 构建 | Console `npm run build:element-plus`、`npm run build:bootstrap`；Admin `npm run build` | 均成功；模块路由与资源能解析，Bootstrap回退页面不复制业务逻辑。注意 `vite build` 不跑 vue-tsc，SFC 类型错误不会被本门捕获 |
| 静态/文档 | `vendor/bin/pint --test`（本轮PHP文件）；`python3 scripts/architecture_guard.py --all`；`python3 scripts/gen_module_catalog.py --check`；`python3 scripts/docs_manifest.py --check`；`git diff --check` | 无新增失败，文档与目录登记一致；本轮通过 |
| 隔离MySQL，发布阻断门 | 隔离实例执行真实迁移两次；8路同键start/采用、异输入、并发advance/cancel；注入发布失败和事务回滚（命令见下） | 只创建1个运行/发布版本；同键同输入重放、异输入409；旧revision不推进；不重复调用工具；失败不出现adopted却无绑定版本。旧B导入并发证据不能替代新C/D测试。本轮在**复位后的干净专用库**重跑全绿：本机 MySQL 8.4.8 `multi_tenant_saas_test` 先 `DROP`+`CREATE`（表数 0）再执行，`AiSkillMySqlIntegrationTest` 3例 + `AiSkillMySqlConcurrencyTest` 5例 = 8 tests / 102 assertions，0 失败 0 跳过；拆掉 `lockForUpdate` 的变异可复现 1062 重复键 5xx 与 8 路全 200，证明断言有牙齿。**脏库不作数**：残留行使迁移用例首跑假失败，须在干净实例上跑；生产实例现场迁移与试点仍未执行 |
| 真实后台，试点阻断门 | 实际Admin/Console会话：目录→详情→私人/共享收藏；公共启停及409；真实只读运行/刷新/取消；切租户/换人员期间旧请求晚返回；无权限直接访问API；Admin训练页需走完「显式授权租户→登记训练→查看运行事实→冻结≥10例判据→发起评测→查看 validation→采用/拒绝」 | 同租户他人私人信息及跨租户信息均不可见；无默认租户；无执行权/训练管理权拒绝；损坏/停用版本不执行，真实页面无假成功；保存截图及HTTP/版本证据。入口页面与API本轮已接线（旧版是禁用布局），但真实浏览器会话仍未执行 |
| 跨仓升级/回退，发布阻断门 | 实际split包与匹配核心≥2.13.1、Ai/AiSkill同步安装；SCRM构建；迁移/导入幂等；关闭新入口并回退匹配程序 | 提供者绑定、目录及权限正常，原助手/原链仍可用；不drop数据、不重放已执行动作。本轮未完成 |
| 训练与效果，BL-147关闭门 | 受控真实LLM候选、独立评测、自动采用及需审核拒绝/通过；保留发现集外至少10例，同路径前后各3次 | 事实正确≥8/10且不低于基线，新增严重错误/越权/隐私泄露=0；固定路径编排LLM=0，可比编排调用减少≥50%；费用未知不宣称省钱。假样本/状态流转不证明效果；本轮未完成。已核实的现场前置（只读取证，未做任何写操作）：试点定为 `club.mtedu.com`，生产 vendor 落后一代（ai-skill `feabd18724`）、仅 `2026_10_13_000001` 已跑（batch 31）、`ai_skill_runs` 与本轮两张 `2026_10_16_*` 迁移未执行、`tenant_modules` 无该租户 ai-skill 行；证据面 `agent_tool_logs` 32 / `audit_logs` 50 / `ai_audit_logs` 50 行可用，`task_chain_runs`、`ai_skill_experiences` 为 0 行，故首轮真实训练只能取 `source_types: [audit_logs, ai_audit_logs, agent_tool_logs]` 的历史窗口；真实LLM可用（`AI_BAILIAN_API_KEY`/`AI_MODEL` 已配置，2026-10 用量 471,798 tokens）。开启顺序固定为：迁移→`tenant_modules` 物化→`ai-skill.enabled`→`platform_tenant_scopes`→`ai.task_chains.pinned_read_tools` 与 `ai-skill.*.tool_allowlist`→`training.enabled`→登记训练→冻结≥10例判据→`evaluation.enabled`→评测→采用；每步都要留 HTTP/DB 证据，费用未回算前 `cost_micro` 保持 null 并按 unknown 表述 |

并发、浏览器、跨仓和训练效果任一对应门未通过，不能将其标记完成或开放对应试点。执行方交付命令、环境/版本、通过/失败/跳过数及失败证据；待办状态仅更新主台账 [backlog](../../../docs/governance/backlog.md)，不得以本文件另建第二份任务列表。

PHP回归命令（框架根目录）：

```bash
vendor/bin/phpunit --filter 'AiSkill|TaskChainTest|RolePermissionSeederTest|AgentRuntimeTest|AgentRuntimeConfirmTest|HeadlessAgentTest|ModuleDependencyGateTest'
```

隔离MySQL门命令（须指向专用测试库；库名不以 `_test`/`_testing` 结尾时 `TestCase` 会直接拒绝，防误清开发库）。**先复位隔离库再跑**：残留表会让 `AiSkillMySqlIntegrationTest` 的“全新安装”首跑假失败，脏库结果不作数；口令只从 `.env` 读进环境变量，不出现在命令行与记录里。

```bash
export MYSQL_PWD="$(grep -E '^DB_PASSWORD=' .env | cut -d= -f2-)"
mysql -h 127.0.0.1 -u "$(grep -E '^DB_USERNAME=' .env | cut -d= -f2-)" -e \
  "DROP DATABASE IF EXISTS multi_tenant_saas_test; CREATE DATABASE multi_tenant_saas_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

TESTING_DB_CONNECTION=mysql DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=<隔离库> DB_USERNAME=<测试账号> DB_PASSWORD=<测试口令> \
php -d memory_limit=512M vendor/bin/phpunit \
  tests/AiSkillMySqlIntegrationTest.php tests/AiSkillMySqlConcurrencyTest.php
```

实测：`TESTING_DB_CONNECTION=mysql` 缺省时 `TestCase` 钉回 sqlite、用例静默跳过（1 skipped），设置后即真实连 MySQL（PHP CLI 会填 `$_SERVER`，无需额外 `php -d` 开关）。库名守卫只放行 `_test`/`_testing` 结尾或 `TESTING_ALLOWED_MYSQL_DATABASES` 显式列出的库。

## 发布与升级流程（2026-10-16 排定，未执行完毕）

| 步 | 动作 | 通过标准 | 状态 |
|---|---|---|---|
| R1 | `git push origin main` | 5 个本地提交上远端 | **阻塞：本机 github.com:443 不通** |
| R2 | CI `split.yml` 全池拆包 | 各 `dsplat/multi-tenant-saas-module-*` 分支更新，含 ai / ai-skill / ai-streaming | 待 R1 |
| R3 | SCRM `composer update` + 提交 `composer.lock` | vendor 追上 `c3ebc33c` 一代；核心 ≥2.13 | 待 R2 |
| R4 | 生产增量部署 + 迁移 | `ai_skill_runs`、`2026_10_16_*`、`make_ai_request_cost_nullable` 均 Ran | 待 R3 |
| R5 | 种子导入 + 权限播种 | `platform.ai.skill.view/manage`；Skill 正文摘要一致 | 待 R4 |
| R6 | 试点启用（严格顺序） | 见下方测试计划 P0 | 待 R5 |
| R7 | 回退演练 | 关 `ai-skill.enabled` / training / evaluation，旧助手可用，不 drop | 与 R6 同场 |

**回退**：关新开关与新入口，保留数据与已发生动作；程序按版本回退，不重放业务。

## 试点测试计划（club.mtedu.com）

**原则**：每步留 HTTP/DB 证据；失败即停；不把登记当执行、不把点击当成功；`cost_unknown` 不宣称省钱。

### P0 启用与门控（发布阻断）

1. 迁移可重复执行两次 → 第二次 0 新表  
2. `tenant_modules` 物化 ai-skill 行（fail-closed）  
3. `ai-skill.enabled=true` → 目录/详情 200；匿名 401；外租户 403  
4. 未开 `training.enabled` 时登记训练不推队列  
5. 工具白名单 / `platform_tenant_scopes` 按显式租户配置  

### P1 Console 租户面

| # | 场景 | 期望 |
|---|---|---|
| 1 | 目录→详情→个人收藏→共享收藏 | 有界列表；正文损坏标不可用；共享写需 setting.update |
| 2 | 版本停用→收藏失效→409 刷新 | 不静默换新版 |
| 3 | 固定执行 start→advance→完成 | 摘要钉定；revision 冲突 409；取消不可重放 |
| 4 | 推荐生成→曝光/点击/采用/执行/完成 | 事件分列；grants_execution=false |
| 5 | 切租户/登出 | 旧缓存丢弃；私人收藏不残留 |

### P2 Admin 治理面

| # | 场景 | 期望 |
|---|---|---|
| 1 | 公共版本列表/启停 | 默认不列全租户；409 不覆盖 |
| 2 | 显式 tenant_id 登记训练 | 缺 422；未授权 403；无 fallback |
| 3 | 冻结 ≥10 判据→评测→采用/拒绝 | 需审不自动 adopted |
| 4 | 成本显示 | 未跑 = unknown，跑过才计 |

### P3 真实 LLM 闭环（BL-147 关闭门）

1. history 窗口 `[audit_logs, ai_audit_logs, agent_tool_logs]` 登记训练  
2. 冻结留出 **≥10** 例（不参与训练）  
3. 评测：事实正确 **≥8/10** 且不低于基线  
4. 至少 1 例 auto 采用 + 1 例 needs_review 拒绝  
5. 固定路径编排 LLM=**0**；可比编排调用减半  
6. 0 新增严重错误/越权/隐私泄露  

### P4 回退

关 training/evaluation/ai-skill 入口 → 旧小秘书与链路可用 → 已执行 run 保持终态不重放。

### 测试计划与放行门的关系

- 上表 P0–P2 对应「真实后台 / 跨仓」放行门；P3 对应 BL-147 关闭门。  
- 本地 PHP/前端/构建/隔离 MySQL 门已在上表执行完毕，**不能替代** P0–P4。  
- 执行结果回填 [backlog](../../../docs/governance/backlog.md) 与 LEDGER，不另建第二任务表。


## 2026-10-17 本地收口接线补充（尚未发布）

- 独立业务结果：部署配置 `ai-skill.outcome_verifiers` 以精确Skill key登记 `['class' => SkillOutcomeVerifierContract实现, 'version' => 业务判据版本]`。start将判据及校验器版本/文件摘要固定到plan；advance只使用服务端工具结果和已解析的租户/人员。校验器应只读核对权威业务事实，不能把工具自报成功当结论。无校验器/无判据为incomplete，校验器漂移/异常为uncertain；成功必须携带有限证据引用。生产默认登记为空，本轮未替项目伪造成功。
- 终态经验：执行结果与SkillExperience在同一事务内写入，引用原run，不复制名单/提示词；重复推进或取消不重复写。success/incomplete/failed/uncertain/cancelled分别保留，可信来源不等于业务成功。
- 受控人审：原Admin adopt在needs_review时要求当前独立评测的正文摘要、冻结数据集与评分器信息，且仅允许已验证只读变更；记录审核人、时刻、正文和manifest摘要后事务发布。写能力缺可信评测仍拒绝，人审发布不授予执行权。新评测记录写入draft_sha256，旧缺证据记录不能用于人审绕过。
- ContextPlan旧AiSkill命名空间保留，复用core DTO；PHP文本chat/complete/stream与Node两类resolve接入同一ContextPlanner。未知模型窗口保持null，使用有限65536字节保守估计准入上限（非provider真实窗口、非精确token数），可在部署配置明确model_windows。系统规则和工具schema计入预算，历史按整轮裁减，当前输入/工具事实过大明确拒绝；工具调用/结果必须成对。Node使用PHP下发的context_messages并对每次provider请求执行context_bounds，旧PHP响应仍兼容。
- 委派：Headless增加可选共享DelegateBudget；每次外呼前按输入估计＋输出预留扣准入额度，默认4次/262144估计单位，任务链共享16次/1048576。默认额度以有限上下文上限×调用次数配置，不是实际费用。链上先持久化分配与inflight标记，再发外呼，失败/崩溃不自动退款重放；旧人工回填恢复入口保留。仍需独立MySQL并发/崩溃恢复与跨入口完整覆盖验证，不能据这些机制测试关闭BL-148。

实施状态、准确验收数字和接续工作只维护在 `docs/governance/backlog.md` 最新BL-150交接段。此补充不证明真实业务试点、全部写操作、全量回归或正式版本交付已经完成。
