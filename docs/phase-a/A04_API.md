# API v1 设计契约

本文保留A04设计基线。2026-10-23已实现NF-B01的创建/元数据接口及所属页面CRUD，精确范围见 [NF-B01验收](../phase-b/NF-B01_ACCEPTANCE.md)；其余接口仍为待实现设计，不得宣称已存在。

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
