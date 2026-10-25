# API v1 设计契约

本文保留A04设计基线。2026-10-23已实现NF-B01的创建/元数据接口及所属页面CRUD，精确范围见 [NF-B01验收](../phase-b/NF-B01_ACCEPTANCE.md)；NF-B02同步结构CRUD及版本读取也已实现，见文末；其他核心接口仍为待实现设计，不得宣称已存在。

## 通用协议

根路径 `/api/v1`。同源浏览器会话鉴权，修改动作要求CSRF；未登录401，拒绝403，查不到或越权资源统一404防枚举。小说授权、基线和POV过滤在服务内执行。分页使用不透明cursor，limit默认20、最大100（本设计决定，非框架内建值）。IDs为十进制字符串；时间含时区；金额字符串。

业务写操作携带 `Idempotency-Key`（非空，最多128个ASCII可打印字符）和需要的expected_version/state_version。键域为actor+novel+command，规范请求hash相同返回原结果；异载荷409。提交键同时满足同书唯一，成功提交键长期保留，不因一般请求缓存到期重复生效。只读POST search不需要幂等键。

成功包：`{data,request_id,version:"v1"}`；异步202附`run_id,status_url`；同步创建201、读取/控制200。错误包：`{error:{code,message,fields,retryable,suggested_action},request_id,version}`。suggested_action仅描述允许动作，不执行模型返回的工具指令。未知内部错误500仅返回trace引用。

| 方法与路径（均相对根路径） | 必需输入/约束 | 成功结果 | 责任 |
| --- | --- | --- | --- |
| POST /novels | title,genre,premise_raw,length_policy,target_chapters；预算可先候选 | 201 novel+unresolved_requirements | B01 |
| GET /novels/{id} | 授权小说 | 200工作台摘要，不返回密钥 | B06 |
| PATCH /novels/{id} | expected_version；允许的草稿元数据；不能写state_version/正式指针 | 200新row_version | B01 |
| POST /novels/{id}/blueprint-runs | requirement_version,route_version,budget_policy | 202 run | E01 |
| POST /blueprint-versions/{id}/approve | expected_version,coverage_hash,reason | 200冻结版本；缺覆盖422 | B04 |
| POST /novels/{id}/outline-runs | blueprint_version,range,route_version | 202 run | E01 |
| POST /novels/{id}/generation-runs | range,plan_version,route_version,style_version,budget_policy,expected_revision/state_version | 202 run；前序缺失409 | F01 |
| GET /generation-runs/{id} | 授权run | 200状态、阶段、检查点、允许动作 | C07 |
| POST /generation-runs/{id}/pause | expected_version,reason | 200已请求暂停；可能仍有在途费用 | C06 |
| POST /generation-runs/{id}/resume | expected_version,checkpoint_artifact_id,reason | 202新尝试/续跑引用；依赖stale时拒绝原样重用 | C06 |
| POST /generation-runs/{id}/cancel | expected_version,reason | 200取消请求；禁止新调度 | C06 |
| POST /chapters/{id}/versions | source_version_id?,body,title,expected_version | 201候选版本和body_hash | E02 |
| POST /chapter-versions/{id}/review-runs | policy_version,context_manifest_id | 202 run | E04 |
| POST /chapter-versions/{id}/revision-runs | issue_ids,allowed_scope,preserve_facts,policy_version | 202 run | E05 |
| POST /chapters/{id}/commits | A04提交协议字段；expected_revision/state_version | 201 commit或相同键200原commit | E06 |
| GET /novels/{id}/state | chapter,revision | 200对应历史状态与证据 | D01/E06 |
| GET /novels/{id}/foreshadows | revision,window,status,cursor | 200账本分页 | D03 |
| POST /foreshadows/{id}/plan-revisions | expected_version,proposed_change,reason | 201计划候选；拒绝paid_off直写 | D03 |
| POST /novels/{id}/search | query,revision,chapter_cutoff,pov_id,types,limit≤100 | 200来源+通道+分数+过滤说明；分词通道按CR001后期再启用 | D05 |
| GET /generation-steps/{id}/context | 授权step | 200脱敏manifest，隐藏密钥和越权来源 | D06 |
| POST /exports | novel_id,revision,range,format,options | 202 export run+export_id | E08 |
| GET /exports/{id}/download | 授权且未过期 | 200文件流；无长期公开链接 | E08 |

本表覆盖PRD §31核心接口。页面细化CRUD由所属模块遵循同一规范，不在本轮虚构不存在的服务类或SDK参数。

## 错误及重试

| HTTP | code | 处理 |
| --- | --- | --- |
| 409 | STALE_STATE / CANONICAL_CONFLICT | 重建依赖和复评，不能盲目重试 |
| 409 | REVIEW_STALE / INVALID_TRANSITION / IDEMPOTENCY_CONFLICT | 修复对应版本/动作/请求身份 |
| 422 | UNAPPROVED_ENTITY / EVIDENCE_INVALID | 批准候选或重新提取，不能伪造原文 |
| 422 | CONTEXT_OVER_CAPACITY | 裁剪可选记忆或拆任务；硬约束不可删 |
| 409 | BUDGET_EXCEEDED / DEPENDENCY_NOT_READY | deferred等待预算或依赖，保留产物 |
| 503 | PROVIDER_UNAVAILABLE | 有限技术重试；账单未知先对账 |
| 429 | RATE_LIMITED | 遵循供应商/系统退避提示 |
| 401/403/404/422 | AUTH_REQUIRED / FORBIDDEN / NOT_FOUND / VALIDATION_FAILED | 统一输入与授权处理 |

HTTP错误是新接口设计选择；具体供应商错误通过适配器映射，不能直接透传供应商认证头或原始堆栈。

## NF-B02 已实现页面接口（2026-10-23）

前缀 `/api/v1/novels/{novel}/structure`：GET根路径读取完整工作树；GET `/versions` 游标分页；GET `/versions/{outline}` 读取历史；POST `/node`、`/thread` 保存工作节点/线索（200）；POST `/snapshot` 保存不可变结构版本（201）。写入遵循会话/CSRF、Idempotency-Key、expected_version和reason规则。完整字段见[NF-B02验收记录](../phase-b/NF-B02_ACCEPTANCE.md)。结构版本不代表生成审批，所有结构读取generation_eligible=false。

## NF-B03 已实现页面接口（2026-10-23）

前缀 `/api/v1/novels/{novel}/world`：GET根列表、`/entities/{entity}`详情、`/versions/{definition}`历史及`/versions/{definition}/reference`引用资格；POST `/definition`、`/approve`、`/accept-minor`、`/policy`、`/merge-aliases`、`/retire`，全部同步200。列表遵循cursor默认20/最大100；写入遵循会话/CSRF、Idempotency-Key、expected_version、reason。具体字段与状态见[NF-B03记录](../phase-b/NF-B03_ACCEPTANCE.md)。本接口不写正式动态状态，`canonical_state=null`不是事实值。

## NF-B04 已实现页面接口（2026-10-23）

前缀 `/api/v1/novels/{novel}/blueprints`：GET根列表（status、cursor、limit），GET `/{blueprint}`历史；POST `/save`追加候选（201），`/validate`检查（200），`/approve`批准（200）。所有写操作需要expected_version、reason和Idempotency-Key；批准另需blueprint_version_id、validation_id、report_hash及acknowledge_impact=true。此处将原设计中的coverage_hash细化为包含覆盖内容及所有依赖的检查报告hash，批准时重算；未宣称原示意路径已注册。具体字段见[NF-B04记录](../phase-b/NF-B04_ACCEPTANCE.md)。蓝图批准不改变生成资格或正式事实。

## NF-B05 已注册接口（2026-10-23）

`/api/v1/novels/{novel}/plans`：GET列表（limit≤100、before降序游标、status全局派生状态筛选）、GET/{plan}；POST save/validate/freeze/split/merge/reorder。同源会话/CSRF、Idempotency-Key、expected_version、reason及统一响应沿用现有协议。save同时捕获新结构快照和覆盖证据；freeze须rolling_plan_id/validation_id/report_hash，重验后追加。具体载荷、错误码和后台动作见../phase-b/NF-B05_ACCEPTANCE.md。B01 intent/requirements已返回真实覆盖证据、计划链接及规划门禁，generation_eligible仍false。

## NF-B06 已注册接口（2026-10-23）

GET /api/v1/novels/{novel} 已实现为只读工作台摘要，支持章范围、规划状态、issues_only、page及limit=10/25/50/100。共享小说行锁下复用B05报告，返回真实缺项、最新计划和当前有效冻结窗口，不创建审计或命令。未接入的正式章数/费用/下一章/运行等返回null，generation_eligible=false。响应字段和页面契约见../phase-b/NF-B06_ACCEPTANCE.md。

### NF-C01 接口落地边界（2026-10-23）

新增 PHP 应用服务 GenerationService::start/read/advance 与 StageExecutor::execute(array): StageResult。start/advance 强制 testing，read 验证管理员及本书归属；所有输出业务 ID 为十进制字符串。start 的首次响应以 generation.test.start 命令域幂等保存，重试通过明确 retryOf 尝试号去重。没有新 HTTP 路由；POST /generation-runs 属于 F01，运行查询页面/API 属于 C07，暂停/取消/迁移属于 C06。不能将内部测试入口视为生产生成资格。

### NF-C02 内部接口（2026-10-23）

OutboxService::startQueued/enqueue/publish/recover/consume 和 GenerationService::claim/complete/heartbeat 是内部服务接口；新增 novelforge:dispatch CLI 与 Redis Job。没有新 HTTP 入口，生产 start/pause/resume/cancel 与运行中心仍按 F01/C06/C07 实施。重试意图用 run+scope+retryOf 唯一键，正常完成回执与产物同事务。CLI 操作和消费者更新流程见 ../phase-c/NF-C02_OPERATIONS.md。

### NF-C03 配置接口（2026-10-24）

新增 GET /api/v1/providers，以及 POST /api/v1/providers/provider、/model，均要求管理员同源会话，写接口沿用CSRF。配置命令独立provider_commands幂等，actor+key唯一；含敏感输入只存HMAC，响应不含密钥/secret_ref。字段、筛选和分页见 ../phase-c/NF-C03_ACCEPTANCE.md。原生后台表单以command_key提交相同幂等身份，成功重定向；错误不闪存旧密钥。

ProviderGateway::execute/read/evidenceText为内部接口，不新增真实调用、能力探测或证据下载路由。模型声明状态不能写为verified/available；所有真实请求仍被BUDGET_GATE_REQUIRED拒绝。

## NF-C04 已实现接口（2026-10-24）

- GET `/api/v1/configurations/{prompt|route|style}`：管理员、同源会话；q/stage/language/status/page 筛选，20项/页，含版本正文、最后回归、默认指针及引用运行数。
- POST 同路径加 `/save`：`key,stage,language,expected_version,reason,content`。键/阶段/语言为稳定身份；所有编辑产生下一版本。content 按类型严格白名单；示例由 `App\Support\ConfigurationExamples` 提供。
- POST 同路径加 `/test`、`/publish`、`/retire`：仅 `version_id`。需要 Idempotency-Key；后台原生表单使用 command_key。所有状态变化共用应用服务。回归失败正常返回 draft + report.passed=false；直接发布返回 REGRESSION_REQUIRED。后台表单通过 payload_json 传完整配置，错误不回显提交正文。
- 错误包括 STALE_VERSION、IDEMPOTENCY_CONFLICT、UNSAFE_TEMPLATE、INVALID_VARIABLES、CONTRACT_MISMATCH、UNPUBLISHED_REFERENCE、CAPABILITY_UNVERIFIED、CAPABILITY_MISMATCH；保持 A04 错误 envelope。
- 运行冻结与主备切换接在既有 testing-only 服务接口，没有新增生产生成 HTTP 路由。运行中心与生产控制等待 C06。

## NF-C05 已实现接口（2026-10-24）

GET `/api/v1/billing` 支持novel_id/run_id/model_profile_version_id/date_from/date_to/category/currency/request_status/status/page，25笔请求/页；按原币列已核销金额、风险预留和未知笔数。GET `/api/v1/billing/export` 同筛选导出最多一万笔CSV费用摘要，未知实际费用为空。

POST `/api/v1/billing/price`：model_version_id、expected_version、currency、unit_prices（input/output/cache_read/cache_write/reasoning，每百万Token十进制字符串）、cache_mode/reasoning_mode（none/included/separate）、effective_at（带时区ISO时间）、evidence_ref。

POST `/api/v1/billing/policy`：key、expected_version、currency、limits（request/chapter/run/book/day十进制字符串）、warning_percent/stop_percent、timezone=UTC、reason。

POST `/api/v1/billing/reconcile`：request_id、external_charge_id、evidence_ref，另提供usage或amounts；amounts必须含全部五种独立类别十进制金额。只有金额的账单可传usage=null，Token保留未知。操作使用Idempotency-Key；不允许对仍pending的请求人工结算。更正追加历史而非修改原始响应。

同源管理员、CSRF及A04错误格式不变；没有新增真实调用或生产运行控制接口。后台原生POST支持command_key和payload_json。配置响应仅保留录入的费用信息，没有供应商密钥。

## NF-C06 已实现恢复接口

`GET /api/v1/novels/{novel}/runs/{run}/recovery` 返回节点恢复范围、原产物、分类、费用风险、决定和隔离证据；可选 `changed_scopes[]` 预览变更节点及下游。

`POST .../recovery/{pause|resume|cancel|manual|retry|reextract|change_plan}` 必须提供 `Idempotency-Key`、`reason`、`preview_hash`；`retry/reextract` 指定 `scope`，`retry` 可选冻结批准清单中的 `model_version_id`；`change_plan` 指定 `changed_scopes`。沿用同源管理员会话/CSRF/小说归属；409用于陈旧预览、运行不可执行、未知结果或已有重试意图。没有强制通过、真实模型调用、直接正文编辑或费用清零接口。reextract/change_plan 登记结果明确标为 awaiting_stage_implementation，离线重放适配点没有生产HTTP入口。

## NF-C07 运行观测接口

GET `/api/v1/runs`（novel_id/status/error/trace_id/date_from/date_to/page）；GET `/api/v1/novels/{novel}/runs/{run}`（request_page）；GET `/api/v1/audit` 和 `/api/v1/audit/export`（novel_id/run_id/trace_id/action/object_type/status/high_risk/date_from/date_to/page）；GET `/api/v1/operations/health`。

受控附件：GET `/api/v1/novels/{novel}/runs/{run}/artifacts/{object}`；GET `/api/v1/novels/{novel}/runs/{run}/requests/{object}/evidence/{request|response}`。必须管理员且同书同运行，校验完整哈希，附件no-store/nosniff，无任意路径参数。审计导出为当前25条筛选页，不表示完整历史。服务端HTTP追踪号通过X-Trace-Id返回；API错误含trace_id。没有生产启动或新增写审计接口。

## NF-D02 候选时间线接口

`/api/v1/novels/{novel}/story-contexts`：GET 最近50个版本；POST 使用 Idempotency-Key、expected_version、reason、entries/dependencies/knowledge/relationships/rules 保存新版本并检测。GET `/{context}` 读取封存配置、证据与报告；GET `/{context}/pov?knower_id=…&chapter=…` 返回该章候选知识与可用状态。全部沿用同源会话、CSRF、管理员和同书授权，输出 ID 为十进制字符串。报告 consistent/ambiguous/conflict 与 HTTP 成功保存分开；均为 candidate，不开放正式提交。字段及边界见 ../phase-d/NF-D02_ACCEPTANCE.md。

## NF-D03 伏笔账本（2026-10-24）

同源会话、CSRF、管理员和小说归属检查；所有ID响应为十进制字符串，响应沿用data/request_id/version与private,no-store。

| 方法 / 路径（前缀 `/api/v1/novels/{novel}/foreshadows`） | 参数 / 结果 |
| --- | --- |
| GET `/` | 可选chapter、required、due、overdue、no_evidence、entity_id、from、to、lifecycle、blueprint_version_id、thread_kind。布尔查询用0/1。返回items、threads、ending、row_version及author_preview/unspecified |
| GET `/{plan}` | 任意同书历史版本、同谱系history/freezes/proposals和明确为空的正式occurrences |
| POST `/create` | plan完整白名单载荷；返回plan |
| POST `/freeze` | plan_id；须最新有效版本及蓝图已批准；返回freeze_id/plan |
| POST `/propose` | plan_id、kind=reschedule/abandon；改期传完整plan且仅窗口/强化允许变化，放弃必传alternative；返回proposal_id/effective_plan_id |
| POST `/decide` | proposal_id、decision=approve/reject；批准生成新plan，拒绝plan=null |

写命令均要求Idempotency-Key、expected_version=novels.row_version、reason；返回最新row_version。未知字段、直接生命周期或正式来源注入拒绝。关键冲突：STALE_STATE、STALE_PLAN、IDEMPOTENCY_CONFLICT、ALREADY_DECIDED、ALREADY_FROZEN、BLUEPRINT_NOT_APPROVED、INVALID_WINDOW、INVALID_REVISION、INVALID_REFERENCE。无正式occurrence写接口。

plan字段、SQL映射、状态和UI边界见 ../phase-d/NF-D03_ACCEPTANCE.md。预期知情不是实际知识，前置候选不是正式满足，未指定chapter不会猜测due/overdue。

## NF-D04 分层记忆接口（2026-10-24）

前缀 `/api/v1/novels/{novel}/memories`，同源会话/CSRF、管理员与小说归属检查，响应data/request_id/version及private,no-store。ID为十进制字符串。

| 方法/路径 | 输入与结果 |
| --- | --- |
| GET `/` | 必填scope=candidate、branch_id；可选kind/status/index_status/chapter/character_id/page；50条一页，返回items/total/next_page。formal明确返回FORMAL_MEMORY_UNAVAILABLE，不回退 |
| GET `/{memory}` | 同上明确范围；返回原文来源、精确证据、事实、父摘要、分块与分支失效记录；允许作者审计stale历史 |
| POST `/fact` | source_version_id、evidence_id、category、statement、introduced_chapter；statement必须为同来源引文片段 |
| POST `/save` | kind/name/summary/introduced_chapter/visibility/importance、source_ids/fact_ids/parent_ids；可选collection_id/retry_of_id/chunk_size；失败也保存不可变报告，旧活动版保留 |
| POST `/activate` | memory_id；重验来源链、校验结果、最新版本；仅候选分支激活 |
| POST `/invalidate` | source_version_id与memory_id二选一；保留理由，依赖派生记忆立即stale |

所有写命令必带branch_id、expected_version=novels.row_version、reason与Idempotency-Key。索引和正式scope不能由客户端写入。关键错误：STALE_STATE、IDEMPOTENCY_CONFLICT、INVALID_SOURCE、INVALID_EVIDENCE、STALE_SOURCE、STALE_MEMORY、MEMORY_VALIDATION_FAILED、VISIBILITY_WIDENED、INVALID_RETRY、FORMAL_MEMORY_UNAVAILABLE。具体字段、状态与未开放边界见 ../phase-d/NF-D04_ACCEPTANCE.md。

## NF-D05 检索接口（2026-10-24）

GET `/api/v1/novels/{novel}/search-indexes` 获取档案、代次及评测摘要；POST其下 `/profile`、`/build`、`/document`、`/validate`、`/activate`，均要求Idempotency-Key、expected_version=novels.row_version和reason。写入幂等先于版本比较。GET `/api/v1/novels/{novel}/search-evaluations/{evaluation}` 读取同书完整报告。

POST `/api/v1/novels/{novel}/search` 为只读查询：scope、branch_id、build_id、profile_id、query、chapter、audience必填，POV另须pov_id/context_id；limit默认20、最大100，vector默认true，可显式关闭并返回降级原因。不同分支/活动代次/档案请求报INDEX_PROFILE_MISMATCH；formal报FORMAL_SEARCH_UNAVAILABLE；超10000候选报SEARCH_SCOPE_TOO_LARGE。响应含来源/证据/事件/记忆块、范围与每通道排名，均private/no-store。完整字段与UI24见[验收报告](../phase-d/NF-D05_ACCEPTANCE.md)。


## NF-D06 上下文清单接口落地（2026-10-24）

GET `/api/v1/generation-steps/{step}/context` 读取最新冻结清单；`manifest` 选择历史，`compare` 比较同阶段另一清单。POST 同路径使用 Idempotency-Key 追加组装清单，输入 scope=candidate、plan_id、scene_id、pov_id、configuration、billing、safety_margin、expected_version、reason；context_id/search 可选。沿用同源会话、CSRF、管理员及小说所有权，响应 private/no-store。formal 返回 FORMAL_CONTEXT_UNAVAILABLE，不回退候选。清单返回 assembled/over_capacity/missing_required，另以 current_status=stale 表示当前依赖过期，冻结文本不变。接口没有发送模型请求或写正式剧情的副作用。完整字段、估算限制及 UI23 见 [NF-D06 验收](../phase-d/NF-D06_ACCEPTANCE.md)。


## NF-E01 离线规划接口落地（2026-10-24）

统一阶段入口 GET/POST `/api/v1/novels/{novel}/planning-runs`、GET `/{planning}`、POST `/{planning}/adopt` 细化原blueprint-runs/outline-runs示意，原示意路径未注册。phase为intake/blueprint/outline/chapter_plan/scene_plan；前驱parent_id绑定作者采纳回执，章/场景须start_chapter/end_chapter且每次最多十章。route_version_id、planning类别billing、明确离线offline_output、expected_version、reason均须提供。启动202附run_id/status_url；采纳须artifact_id、expected_version、reason。写入Idempotency-Key按actor+novel+command精确重放原响应，异载荷409；管理员/同书/CSRF/private-no-store沿用。只有fake供应商和冻结回放适配器可执行，无真实AI调用。候选编辑/比较/采纳入口联通UI05–08；批准与冻结仍走原B01–B05服务。字段、Schema和边界见[NF-E01验收](../phase-e/NF-E01_ACCEPTANCE.md)。

## NF-E03 实施接口（2026-10-25）

实际入口 GET/POST `/api/v1/novels/{novel}/extractions`、GET `/{extraction}`、POST `/runs`。POST根路径为同步离线响应验证（201）；runs为冻结配置、预算与明确fake响应的注册提取阶段（202）。写入要求Idempotency-Key，同源会话、CSRF、管理员同书授权与private/no-store保持。正文版本/哈希、书级输入及正式状态版本必须显式绑定；可指定同正文parent_id、前章baseline_id、作者rule_context_id，响应不得提供规则。接纳后的内容错误保存为blocked记录；来源或并发准入错误直接拒绝。

UI16入口 `/admin/extraction-ledger` 支持原文、候选事件、补丁、冲突定位、历史、候选回放/重建和仅重提取。C06对注册E03运行返回awaiting_offline_response与精确原文入口，尚需提交修正响应，不表示已调用模型。完整字段及候选资格见[NF-E03验收](../phase-e/NF-E03_ACCEPTANCE.md)。

## NF-E02 已实施接口细化（2026-10-24）

正文候选实际入口为 `/api/v1/novels/{novel}/drafts`，细化上文设计示意；未注册的示意接口不应视为可用。GET 根列表；POST `/runs` 启动场景离线回放（202）；POST `/chapters/{chapter}/assemble` 组装（201）；GET `/{kind}/versions/{version}` 读取；POST `/{kind}/{node}/versions` 编辑（201），kind仅scene/chapter。所有写入要求 Idempotency-Key，保留同源会话、CSRF、管理员同书授权与 private/no-store。

启动字段：plan_id、scene_id、pov_id、route_version_id、billing、offline_output.body、expected_version（书级）、expected_candidate_version（节点级）、reason；billing须category=generation且chapter_id精确匹配。组装字段：plan_id、scene_version_ids（完整有序）、title、expected_version（章节候选版号，首次0）、reason。编辑字段：plan_id、source_version_id（当前候选）、body、title、expected_version（节点版号）、reason。未知字段拒绝。原始正文不trim，换行规范后计算body_hash；自动保存只追加候选版本。无真实模型、评审或正式写入能力。详情见 [NF-E02验收](../phase-e/NF-E02_ACCEPTANCE.md)。
