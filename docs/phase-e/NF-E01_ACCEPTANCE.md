# NF-E01 创意到蓝图与层级规划离线流水线验收

日期：2026-10-24。范围：PRD §2/5/6/13、A04 规划/冻结/恢复契约、DEVELOPMENT_TASKS NF-E01。当前只允许明确提供的离线响应回放，不代表已调用真实模型、完成真实创作质量验收或开放后续 E02–E08。

## 实现与阶段边界

- 注册 `planning.intake`、`planning.blueprint`、`planning.outline`、`planning.chapter_plan`、`planning.scene_plan` 五个固定阶段；每阶段独立 Schema、已发布 Prompt/路由/文风版本、能力、价格、planning 预算与来源快照。固定阶段定义见 `App\Planning\PlanningContract`；禁止额外字段或以无约束 JSON 代替阶段协议。
- `planning_stages` 的前驱阶段与 `planning_adoptions` 的作者采纳回执构成 DAG。阶段序号严格递增，允许保留多个候选分支，禁止环、跳阶段、跨书或没有作者采纳的前驱。每个图节点引用一个现有 generation_run，阶段内复用 C01/C02 的步骤、尝试、租约、fencing、outbox和消费回执；不另造一套恢复引擎。跨阶段边由E01表保存，不谎称已有 generation_dependencies 可跨运行引用。
- 输入提炼发生于大纲之前，generation_runs.outline_version_id 仅对 schema_version=planning-v1 可为空，其他运行仍有数据库约束。提炼可从全新创意开始；新实体批准仍由 B03 管理，不由模型自动登记为批准事实。
- 全部模型执行经过 ProviderGateway、C05预算预留/结算及加密脱敏请求/响应证据。仅 fake 供应商、offline_fake 路由和启动时冻结的 `PlanningReplayAdapter` 响应可在非 testing 环境执行；适配器没有网络实现，响应哈希必须匹配冻结资料。原 test.echo 仍只允许 testing，真实供应商门禁保持。
- 回放 Usage 和价格都是离线模拟，ai_requests.cost_status 保持 `not_billable_fake`；预算预留/核销作为真实数据库协议验证，不冒充真实供应商账单。
- 复用 D06 ContextComposer：原始输入全文、当前要求、批准设定、结构、实际批准蓝图和前驱采纳映射进入作者规划区。系统契约及来源均为强制项；最终实际请求文本/哈希、预算和来源身份冻结在运行配置中。前置规划没有场景，故不伪造带 scene FK 的 D06 场景清单。
- 容量使用UTF-8字节保守估算，实际模型Token及误差未知。输入、输出、单列适用推理、安全余量合计检查；推理included不重复加，separate须预留。备用模型显式切换时也重验其容量，不自动换路由或提高预算。规划请求另设有界大小，原测试网关限制不变。
- 章节与场景阶段由作者指定起止章，每次最多十章；候选必须覆盖该范围，不能由模型扩大或遗漏章位。卷/弧范围与父子级、顺序、同书关系等复用 StructureService 和 PostgreSQL 约束。

## 候选、证据与作者决定

- 输入提炼必须保留原始创意/背景/禁用内容/结局偏好中的实际具体文字，原句必须可定位，不能用泛化摘要替代特殊细节。采纳只追加或修订要求建议，decision_status=unresolved；作者在UI05确认强度、冲突和决定后才能继续。
- 蓝图候选只引用同书批准实体和线程，包含完整蓝图/终局字段及准确要求原句覆盖。首次无结构时可在 foundation_nodes 提供卷/弧建议，作者采纳时才保存结构快照。模型不能提供 author_checks；采纳始终将其设为false。
- 作者可在既有UI06修订、验证、批准候选。后续阶段追溯已批准版本的base_version_id至该DAG蓝图候选，允许作者修订后批准，禁止借另一规划分支的模型候选放行。批准不改变正式剧情。
- 卷弧、章、场景候选分别要求对应层级的实际正文覆盖；只接受当前同书要求版本及其准确原句。字段内必须存在引文，而非AI声称“已覆盖”。确定性预演只证明结构与字面证据，不能代替作者对语义和剧情承接的阅读。
- 每次成功候选先在嵌套事务中调用已有保存服务预演，所有草稿、覆盖及审计写入回滚；只有成功产物入库。失败响应、错误码与费用证据保留为planning_evidence；不会写出半份草稿。
- 采纳由作者显式触发，再次校验书级版本、原始来源/要求身份、当前成功产物和哈希；同事务写草稿、结构快照及不可变采纳回执。结构改动产生覆盖建议（实际节点ID、Unicode码点偏移），供B05现有保存/验证/冻结复核。未自动批准蓝图或冻结计划。
- 同一稳定节点不能在候选内重复覆盖；局部父键必须拓扑有序；非法层级、父级、范围和缺失覆盖均阻塞。原始输入换版、要求或书级输入变化后不能原样复用旧请求。
- 原始来源、各阶段候选、作者修订、采纳映射、批准和冻结均有独立来源记录。旧失败/旧候选不删除；所有步骤均不写正式事件或推进novels.state_version。

## HTTP 与 UI05–08

- 实际入口为 GET/POST `/api/v1/novels/{novel}/planning-runs`，GET `/{planning}`，POST `/{planning}/adopt`。这些统一阶段接口细化A04示意的blueprint-runs/outline-runs；没有宣称原示意路径已注册。
- 启动字段：phase、parent_id（intake不填）、route_version_id、billing、offline_output、expected_version、reason；章/场景另需start_chapter/end_chapter。billing.category=planning，模板变量仅context，输出Schema必须等于阶段契约。启动202附run_id/status_url；采纳提交artifact_id、expected_version、reason。
- 写入均要求非空Idempotency-Key，域为actor+novel+command，规范化hash相同重放首次响应，异载荷409；所有读取和写入均做管理员同书所有权检查，同源会话、CSRF、private/no-store沿用既有协议。
- UI05–08的书内导航均可进入“离线规划候选”；选择阶段、前驱、离线路由及预算，提供明确标注的离线验收资料，启动并回放。可并列查看当前/历史候选、哈希、尝试、阻塞码、采纳回执，显式采纳为草稿。Schema与JSON资料仅为当前离线开发验收模式，不伪装成已接入真实AI的一键创作。
- 要求决定、蓝图人工检查/批准以及近期计划覆盖/冻结继续在原页面完成；UI06显示“离线候选采纳”来源，不再把此类版本标作手工。结构变更导致旧批准依赖失效时，仍需作者修订/重验，不绕过B04/B05。
- C06恢复页面可暂停、取消、复用/重试已注册阶段，未知请求和预算/容量阻塞保持原门禁。阻塞分支占用书槽时须先作明确取消/恢复决定，不能为了新候选静默抹去旧失败。

## 验证结果

最终定向11项全部通过、85断言、40.390秒，见 `NF-E01_TARGET_TEST_RESULTS.json`。包含九项规划测试、一项独立Worker恢复测试及既有空库迁移回滚/重建测试。

已验证：五阶段真实网关记录与预算、原始特殊细节跨弧章场景保留、作者独立批准后前十章B05冻结、输入建议不自动接受、正式状态不变、无大纲首次提炼、模拟非testing标识仍仅执行离线适配器、非法响应/层级和缺失覆盖阻塞、零预算不创建请求、来源过期拒绝、数据库历史不可变及中文备注、HTTP鉴权/幂等/异载荷、Livewire比较与采纳。

中断测试先保存成功网关响应而不完成阶段，再令租约到期，使用独立PHP Worker重领：仅一条ai_request、一份产物、一条消费回执，恢复成功。没有把“跳过成功任务”当作唯一的恢复证据。

前期发现并修正：PL/pgSQL共用触发器不能在另一表分支外引用不存在的NEW字段；测试应断言fake费用类别而非真实计费；阻塞书槽必须经C06明确取消；全新创意夹具应满足B01必填背景。失败轮未记为通过。

完整回归307项：306通过、1跳过、0失败，3159断言、435.756秒，见 `NF-E01_TEST_RESULTS.json`。所有本轮PHP文件Pint检查及 `git diff --check` 通过；核对4条API与1条Filament路由。

开发库先备份后仅应用E01迁移，退出0。新增2表18字段中文备注完整；generation_runs.outline_version_id调整及中文备注已核验。users 1→1、novels 0→0，providers/model_profiles/ai_requests均0→0，两张规划表均0行，没有导入测试候选或配置。备份 `.local/backups/nf-e01-before-20261024-200324.dump`，SHA256 `0ada66b5e2e3fb90d3bc86252397d99f9d121f4708c70f9b1021909cc76cc41a`；归档目录检查通过，未执行恢复演练。详情见 `NF-E01_MIGRATION_RESULT.json`。测试限定novel_forge_test，专用测试Redis在收尾关闭。

UI证据为HTTP/Livewire动作，不代表真实浏览器视觉与键盘验收；未验证真实供应商、模型语义质量、实际Token、千章性能或备份恢复演练。中文分词未接入。
