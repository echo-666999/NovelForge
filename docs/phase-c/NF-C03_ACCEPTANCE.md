# NF-C03 供应商网关、能力档案与安全配置：实施与验收

日期：2026-10-24（Asia/Shanghai）。仅实施 NF-C03；新增代码含中文注释，所有新增数据库表和字段含中文 COMMENT。不推进路由/Prompt（C04）、价格预算（C05）、生产启动（F01）或中文分词，不调用真实模型。

## 依赖与交付边界

NF-A02 在任务清单中仍为 todo：正式最小权限账户、部署与备份目标等尚未验证。本轮没有代为执行 A02，而是实现并验证本地离线网关；A02 仍是上线前条件。当前数据库连接不能被宣称已经满足正式最小权限要求。

交付包括供应商与密钥配置历史、模型能力声明版本、网关逻辑请求/证据、安全端点策略、通用 JSON 协议 HTTP 离线支架、确定性 Fake Provider、结构化输出校验、管理员 API 与 Filament 配置页。真实调用和能力探测由服务端硬拒绝：需要 A02 部署条件、C05 预算门禁及明确外部调用授权。没有可通过配置开关绕过的“允许真实调用”选项。

json_http 是本工程的通用测试协议，不声称兼容某个真实供应商产品/API。现有 HTTP 支架只允许 testing 且强制 preventStrayRequests，必须由 Http::fake 提供响应；生产发送入口抛 BUDGET_GATE_REQUIRED。PublicDnsResolver 与 curl 固定解析地址机制为传输基础；本轮没有真实 DNS/供应商连通或 TLS 端到端验证。

## 数据库、索引及中文备注

迁移：2026_10_24_000008_create_provider_gateway.php。

| 表 | 用途与主要约束 |
| --- | --- |
| providers | 稳定供应商身份，名称唯一、不可变 |
| provider_secrets | 密钥版本加密保存，同供应商复合身份；只追加 |
| provider_versions | 端点/精确主机白名单、密钥版本引用、1–30秒超时、连接超时、每分钟速率/并发上限、启停、变更原因及管理员；provider+version唯一；密钥同供应商复合FK |
| model_profiles | 供应商真实模型标识声明，provider+provider_model_id唯一 |
| model_profile_versions | 容量/结构化/流式/推理计量的声明与来源说明；数据库限定verification_status=unverified；不可变版本 |
| ai_requests | 本书本运行本阶段复合FK、冻结供应商/模型版本、逻辑键/输入hash、C02令牌、状态/错误/问题路径、供应商请求ID、HTTP码、Usage及计费可信标记；step+logical_key唯一 |
| ai_request_evidence | 请求与响应的加密脱敏原文、hash与脱敏标记；request+kind唯一；不可覆盖/删除 |
| provider_commands | 管理配置命令的幂等键、加钥摘要HMAC、安全响应；actor+key唯一；不保存密钥明文载荷 |

ai_requests(provider_id,created_at) 用于一分钟限流；pending/unknown 条件索引用于在途额度。所有新表和每个字段均通过 COMMENT ON TABLE/COLUMN 写入中文备注；测试直接读取 pg_class/pg_attribute/obj_description/col_description，逐项要求存在中文，而非仅检查迁移源码注释。

秘密引用不返回 API/页面；配置历史以版本新增，不原地更新。请求完成后不可改写历史，pending 可一次完成为 succeeded/failed/unknown/quarantined。未添加 route_versions、price_versions、预算/费用流水或正式正文表。

## 配置与能力语义

ProviderConfigurationService 复用管理员准入，全局配置命令有数据库锁、Idempotency-Key 和 expected_version。相同键同载荷重放原安全响应；不同内容409；版本过期409。包含密钥的命令只存HMAC，不存普通可枚举的载荷hash或明文。密钥轮换新增 provider_secrets，旧请求仍冻结旧配置版本。

能力声明与实际能力严格分开：支持声明字段 context_tokens/max_output_tokens、structured_output、streaming、reasoning_metering、source_note；不推断真实模型能力。可声明 supported，但 verification_status 仍为 unverified，effective_capabilities=null；不接受提交 available/verified 字段来伪造验证。输出容量不能大于已声明的上下文容量。

Fake 请求固定 evidence_kind=fake、capability_status=unverified，不会升级能力档案。Usage 缺失为null，不伪造0；cost_status=not_billable_fake仅表达本轮无费用测试，不提供价格或实际费用计算。价格/路由/使用阶段保持未知，属于后续任务。

## 网关、证据与恢复

ProviderGateway 内部接口接受管理员、novel/step/model_version、prompt/output_schema 和阶段内逻辑键。没有真实生成HTTP入口。

1. 验证小说归属、Schema与大小，再按小说→运行→供应商顺序加锁；先检查请求幂等，不因重放时阶段已推进而再次调用。
2. 冻结当前供应商版本、指定模型版本、输入hash和阶段fencing_token。当前版本停用则拒绝；非fake或非testing在解密密钥和执行适配器之前拒绝。
3. 要求有效C02租约、可执行运行与未改变的正式state_version；在供应商锁下按最近60秒请求数和pending/unknown数量准入。未知请求保留并发占用，不自动再次发送。
4. 在同一准入事务写 pending 请求及加密请求证据。事务外执行适配器一次，不持锁等待，不自动重试。
5. 响应保留原始脱敏字节及hash。密钥在正文、JSON字符串和Unicode转义回显中被替换，证据标记redacted；“原始”明确指只做必要脱敏后的证据。无脱敏时保持响应原文。认证头不进入证据。
6. JSON解析或受限Schema校验失败时保存 error_code、问题路径与对应证据ID/hash。失效令牌、过期租约、暂停/取消或正式状态变化导致迟到结果quarantined，不作为成功产物。
7. 传输异常不保存异常原文或堆栈，状态unknown、error=PROVIDER_OUTCOME_UNKNOWN；同逻辑键重放不再发请求。C06的人工处理与C05的风险预算尚未实现。

原始脱敏证据仅提供带书归属检查的内部 evidenceText 方法，不开放公共下载路由，不进入配置页。请求结果投影不返回密文、密钥引用、认证头或正文全文。应用密钥与备份应按A02保护；本轮未替换 APP_KEY，也没有进行应用密钥轮换演练。

## 端点与输出安全

- 仅HTTPS/443、精确主机白名单；拒绝用户信息、IP字面量、数字变体主机、localhost/local/internal/test/invalid后缀、空白/反斜杠/百分号、query和fragment。
- DNS结果为空拒绝；任一结果为内网/保留/映射或NAT64地址均拒绝，不能从混合结果中挑一个公网IP放行。
- curl绑定已验证公网IP，保留原主机TLS验证，关闭代理和自动重定向。任何3xx拒绝，不能跟随Location访问内网。
- 实测 PHP 的 GLOBAL_RANGE 会接受部分 NAT64 地址，本轮单独排除该前缀并加入反例。公网过滤还接受IPv4多播和旧式IPv6站点地址，因此另外限定公网单播候选（IPv4首字节小于224、IPv6限2000::/3并叠加保留地址过滤），覆盖fec0/多播反例。
- 所有 testing 启动时阻断未预期 Laravel HTTP。安全传输测试只使用模拟HTTP及模拟DNS结果，没有对公网/内网发探测请求。
- 输出Schema为明确有界子集：object/array/string/integer/boolean/null，properties/required/additionalProperties=false/items/maxLength/maxItems；拒绝未知关键词和不适用关键词，不宣称支持完整JSON Schema。
- Schema最大8层、每对象最多100字段；校验问题最多100项。请求规范化后不超过200KB，prompt最多50000字符，响应超过1MiB标为RESPONSE_TOO_LARGE。真实HTTP流式接收/大响应存储优化需实际供应商接入时单独验收。

本轮覆盖AC28的供应商地址分项，不代表上传、导出或全系统安全验收已完成。

## API 与 Filament UI26

| 入口 | 字段/动作/筛选/状态 |
| --- | --- |
| GET /api/v1/providers | 管理员安全配置投影；q、interface_type、enabled、declared_structured_output、page；供应商25条/页，当前筛选供应商模型最多100条 |
| POST /api/v1/providers/provider | 配置新增/新版本、启停、密钥轮换；Idempotency-Key与expected_version；has_key取代密钥及secret_ref |
| POST /api/v1/providers/model | 模型标识和声明能力新版本；版本冲突检查；验证状态只读unverified |
| /admin/providers | 供应商卡片含端点、配置版本、启停、是否有密钥、超时/速率/并发；名称/接口/启停筛选与分页；原生表单新增、修订、停用、轮换 |
| 模型表 | 供应商标识、模型名称、版本、未验证状态、声明和来源；声明结构化能力筛选；无数据空态；保存模型新版本 |
| 尚未开放动作 | 真实能力验证/连通探测禁用；路由与主备策略留C04、价格留C05；不把“保存成功”显示为available |

沿用同源session/CSRF，API需登录且is_admin。敏感字段采用原生POST password输入，不绑定Livewire公开属性；不回填旧密钥、不写withInput，api_key加入异常dontFlash。页面/错误/快照只显示安全投影；用户输入的配置文字采用Blade转义。历史/请求审计完整运营页面留C07，不宣称本页已经完成全部UI26路由管理。

## 自动化与边界

ProviderGatewayTest、ProviderConcurrencyTest覆盖配置幂等/不可变/版本冲突、加密及轮换、能力不伪造、无效JSON和结构问题定位、密钥Unicode回显脱敏、unknown不重试、HTTP错误分类、真实调用封锁、限流/并发、SSRF及重定向、Schema拒绝、迟到隔离、后台HTTP渲染/Livewire筛选/原生表单轮换，以及每个新表/字段中文备注。

独立PHP进程竞争同逻辑请求只执行一次；竞争最后一个在途额度只有一个准入。所有响应均Fake，未以该测试推断供应商账号限额或真实并发性能。后台验证是HTTP/Livewire测试，没有真实浏览器视觉、移动端或无障碍人工验收。

完整回归：**185项，184通过、1中文分词项跳过，1165断言**，用时194.293秒。随后仅收紧公网单播候选策略并补3个地址反例，重跑全部C03定向用例：**18项全部通过、213断言**，用时11.950秒；没有将两轮断言相加冒充一次全量执行。结果见 NF-C03_TEST_RESULTS.json 和 NF-C03_TARGET_TEST_RESULTS.json。

21个本轮新增/修改PHP文件Pint检查通过，git diff --check通过。原有C02未提交改动保留；没有新增其他任务业务。空库回滚/重建覆盖八个业务迁移。README中“无业务表/无队列任务”等旧描述已按当前实现修正；不是对未实现模块的授权。

新增8张表及67个字段中文备注在测试库逐项验收，并在开发库迁移后再次查询确认。后台只完成HTTP/Livewire自动化验证；本轮未重跑前端构建，也未执行真实浏览器人工验收。

## 待决事项

无新增阻塞本轮离线实现的用户决定。DEC-01供应商/模型、DEC-02预算与币种、DEC-05部署权限和备份仍需对应阶段确认。A02仍未完成，C05尚未提供预算准入，因此真实调用/能力探测继续关闭。前十章成品与100章伏笔回收仍未进入可执行验收阶段。没有将本轮结果扩大为生产可用或模型质量验证。


## 开发数据库迁移实测

先核对本机novel_forge，完成迁移前自定义格式备份并通过pg_restore --list检查，然后仅增量应用C03迁移，退出码0。未清空或回滚开发库；实际备份还原演练未执行。

- 用户1→1，小说0→0；providers、model_profiles、ai_requests迁移后均0条，未写开发供应商配置/密钥或测试请求。
- 新增表中文备注8/8；字段中文备注67/67。各表字段数：providers 3、provider_secrets 4、provider_versions 15、model_profiles 4、model_profile_versions 9、ai_requests 19、ai_request_evidence 7、provider_commands 6。
- 备份路径：`/Users/webb/Herd/NovelForge/.local/backups/nf-c03-before-20261023-165613.dump`。
- 备份SHA-256：`05896eb08b51c6b0eee5bc6e43d4c0c9122b74aeaa1b8bc7c52efe919bc1d07a`。
- 测试专用Redis在验证结束后关闭；没有修改开发Redis、服务器扩展、生产消费者或真实模型配置。
