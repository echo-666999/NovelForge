# NF-E07 提交后记忆索引与连续章衔接验收

日期：2026-10-26。依据PRD §9/10/21、A04来源/基线/POV/上下文/异步协议、DEVELOPMENT_TASKS NF-E07及UI22/23/24。本轮保留已有E01/E02复核和E06收尾改动，仅接入正式提交后的派生与已有冻结窗口内下一章衔接；不实施F01整书编排、G01重基线、G02大规模重建或E08导出，不调用真实模型、不处理中文分词。

## 实现与来源边界

新增CanonicalMemoryService及ConsumeCanonicalMemory。E06不可变canonical_outbox保持原样，扫描真实committed回执并核对payload_hash/response_hash后，以commit/outbox唯一键登记持久任务。不伪造generation_run、候选分支、供应商请求或费用。候选D04/D05表继续保留，正式派生采用独立强FK表，不能用候选有效标记冒充正式来源。

每份任务冻结canonical-memory-v1档案：offline_hash_v1、64维、cosine、semantic_model=false。四层摘要是确定性的结构化保守保留：working保存完整状态/知识/关系，episodic按来源章保存事件，semantic保存分类型状态事实，event保存事件清单。完整payload保留正式事件/原提案/证据ID、主体/持有者、事实或信念类型、属性/值、时间、叙述顺序及替代关系；不能把推断、传闻等升格事实。它不是自然语言语义压缩，更不代表真实摘要模型质量。

同一封口事务写四层摘要、精确事实值文档、可用离线向量、完成日志和任务状态。数据库校验来源committed、正式章序、完整事件集合/值/主体及正式快照，摘要JSON与该层保留内容一致；摘要/文档/嵌入不可改删。封口校验四层齐全、文档覆盖全部本章事件、indexed时嵌入数量完整；不完整或任意写入故障全部回滚。重试只补派生缺项，不重复正式事实、摘要或文档。

E06原提交回执中的memory_status=pending是提交当时的不可变记录，不回写历史回执。当前状态通过派生中心/状态接口和E06实时pending计数读取；未登记任务也按真实outbox显示memory_pending。

## 投递、租约与失败恢复

- `novelforge:canonical-memory --limit=100`每分钟扫描/补投到Redis memory队列。命令使用显式redis连接，与C02模式一致；通知只带任务ID，不含正文或密钥。任务身份与原outbox永久关联。
- 通知delivery_count/next_delivery_at与执行attempts/available_at分开。正常补投节流60秒，发布故障留QUEUE_UNAVAILABLE并15秒后可补投；未执行的通知不消耗技术尝试。发布后未回写或重复通知均可安全重投。
- 固定小说→任务锁序；60秒租约、单调token和随机owner。编码在封口事务之外，封口检查当前token/owner/未过期租约及活动正式基线。重复完成、旧租约/迟到输出不能改写派生或事实。
- 技术尝试上限3次；异常保留不可变failed日志，15秒后重试，达到上限failed。第三次硬崩溃后过期也能转failed，避免永久占用running。明确人工重试要求原因与Idempotency-Key，可重新开启一轮，但不清除原失败/尝试，也不重置单调token。
- 向量暂不可用时仍完成保守摘要和文本索引，vector_status=degraded。人工重试可补向量，已有摘要/文档不复制。memory_pending=false只表示派生封口已完成；不表示向量完整或语义模型已就绪。

本轮没有发送模型/嵌入HTTP请求，没有伪造Token或价格流水。offline向量只是D05字符编码链路，真实嵌入仍受DEC-03及原C03–C05门禁。

## 正式检索与下一章硬补读

正式scope必须显式指定活动revision_id；跨书/不存在/非活动基线拒绝，不回退候选。正式记忆支持章截止/层级/分页/指定ID，读取复核内容及正式正文来源哈希。candidate分支原接口和来源有效性规则保留。

正式检索先筛小说、活动基线、截止正式章和当前有效事件，再做精确/trgm/离线向量排名。被撤销/替代或未来事件不进入当前召回；POV事实检索还须D02证明的fact/hidden_truth获知链；推断、信念等保留在带类型的角色知识快照，不经无类型检索文本重新升格。不能靠角色白名单、正文中的名字或标题证明知情。文档只存精确事实值，原文大段quote不进入POV结果。向量按文档输入hash、冻结profile_hash及64维档案读取；任一不可用时全查询统一退回文本排名，返回degraded和原因，不以部分向量偷偷混排。固定有界查询不超过10000文档，不以放宽过滤补足Top K。

下一章的安全桥直接回放E06正式事件/快照及该次提交绑定的D02知识上下文，检查连续正式前缀、全局/局部状态版本、原正文哈希。完整分类型状态与最近两章正式正文作为强制上下文，不依赖摘要、Top K或向量；合法POV知识单独注入，仅保留证据定位/hash，不扩大原文引文。死亡等不可逆状态在索引尚无任何记录时也不会遗漏。

DraftingService在已有正式状态后自动从最新正式前缀构造下一章formal上下文，章内仍保留精确前场景全文和版本。历史章重新生成继续拒绝并指向后续重基线流程，不假装G01已实现。来源不完整、状态不一致、POV无法证明或容量放不下强制资料时等待/拒绝，不启动新的正文请求。formal_usable还要求实际组装成功；真实模型始终关闭。

落后的working/semantic摘要不能代替当前状态，缺最新章时不注入；历史episodic/event摘要带来源章/commit，仅作可裁剪作者资料。候选正文、候选记忆和候选索引不会混入formal上下文。已冻结运行/清单不因后来索引完成被回写；新组装才使用新派生。

## 数据库与接口

迁移2026_10_26_000025_create_canonical_memory新增5表57字段，全部中文COMMENT：

| 表 | 字段与约束/索引 |
| --- | --- |
| canonical_memory_tasks | 20字段；novel/revision/commit/outbox、状态/技术次数/token/owner/lease、可执行时间、投递次数/补投时间/错误、向量状态、档案/hash、时间；commit/outbox唯一，同书正式提交FK，待处理索引，身份不可变 |
| canonical_memory_attempts | 7字段；task/token/kind/details/时间及所属书；尝试类型唯一，追加不可变 |
| canonical_memories | 11字段；四层summary、完整payload/hash、正式来源hash、章/提交/基线；commit/kind唯一，基线/章/层级索引，不可变 |
| canonical_memory_documents | 11字段；精确正式event/属性/值/类型/hash及章位；revision/event唯一，同书正式事件FK，基线/章/event索引、pg_trgm GIN，不可变 |
| canonical_memory_embeddings | 8字段；document、profile/input hash、vector(64)、offline来源/时间；document/profile唯一，同书文档FK，不可变 |

没有新增既有表列或修改正式事实。C07审计接入任务状态，正式来源/内存快照及原提交审计保持。

实际新增GET `/api/v1/novels/{novel}/canonical-memory`和POST同路径`/tasks/{task}/retry`。重试仅reason，拒绝force/未知字段，要求Idempotency-Key；同规范请求重放，异原因同键冲突。沿用同源会话/CSRF、管理员同书授权、private/no-store和ID字符串。原memories与search接口在scope=formal且显式revision_id时分派正式读取；无基线请求继续拒绝，旧保护错误码保留但提示明确缺少参数。

## 后台UI22/23/24

新增`/admin/canonical-memory-center?novel=…`，从记忆库/检索实验室/上下文检查器链接进入，保留原候选工作区：

| 区域 | 字段、动作与状态 |
| --- | --- |
| 正式总览 | 同书选择、当前基线/正式版本、memory_pending、待处理数量；无小说/无基线/尚未登记任务有明确空态 |
| 任务与恢复 | 最近100条任务，提交/基线ID、queued/running/complete/failed/stale、向量/错误、lease、attempts、token及不可变尝试日志；填写原因后明确重试，无修改正式事实动作 |
| 正式记忆 | 最近分页结果的层级、来源章/commit、内容hash及完整摘要只读；没有摘要时明确提示下一章仍直读硬状态 |
| 检索诊断 | 显式截止章、query、author/pov、POV ID、离线向量开关；显示合法命中、降级原因和pending，不以未知人物或错基线补召回 |
| 上下文/E06状态 | UI23显示scope与memory_pending、强制状态/最近正文；E06计数取真实未完成意图，不再固定显示E07未接入 |

HTTP/Livewire验证了查看、空态、重试与检索动作，不声称真实浏览器视觉/键盘/小屏交互已经验收。完整来源与请求字段见A04补充。

## 实际测试及失败证据

全部数据库测试使用novel_forge_test，专用Redis Unix socket（端口0），保留原DB_URL/缓存/队列/文件隔离，不用SQLite替代约束/事务。本轮14项新增E07测试：11项服务/安全/故障/上下文/接口/Livewire，3项独立进程/真实队列；无真实模型调用。

| 验证 | 结果与证据 |
| --- | --- |
| 首轮5项 | 4通过、1失败、95断言；幂等重试首响应与JSONB回读键序不同，已按规范对象键规范化修正，未重排列表。[原记录](NF-E07_INITIAL_RESULTS.json) |
| 约束补强迁移失败 | 5项均在初始化时因CASE表达式缺少括号报错；事务未落半份结构，已修复。压缩错误明细保留于[记录](NF-E07_SCHEMA_FAILURE.json) |
| 相关回归43项 | 41通过、2旧阶段断言失败，623断言、669.645秒；覆盖上下文、候选记忆/检索、正文分区、连续章、空库回滚等。旧测试断言所有formal请求均不可用，已改为拒绝不存在/非活动基线，跨书/候选隔离未放宽。[记录](NF-E07_RELATED_RESULTS.json) |
| 最终专项16项 | 16/16通过、188断言、393.586秒；13项E07、2项旧阶段兼容复验及空库回滚重建。[记录](NF-E07_TEST_RESULTS.json) |
| 最后向量补验2项 | 2/2通过、17断言、53.073秒；专项后仅收紧向量输入/档案过滤和部分向量失败时统一文本降级，连同死亡状态直读/向量恢复复验。[记录](NF-E07_VECTOR_FINAL_RESULTS.json) |

独立进程：认领并准备后模拟租约过期，新PHP进程重领成功，重投不重复产物；两个真实消费者竞争同任务仅一个落盘；真实Redis memory通知由新的artisan queue:work进程消费，投递节流不消耗执行次数。故障注入在文档写入点触发异常，四层摘要/文档均回滚；旧token不可封口，新token可恢复。缺文档不允许complete，三次技术失败后需明确重试，旧失败日志保留。向量失败不会改写正式事件或丢掉死亡状态；无获知链POV没有命中，错基线/跨书/未来章均拒绝。

最后还收紧了POV推断回灌边界：3/3补验通过、16断言、83.994秒，覆盖推断保留类型/不作为事实检索、未获知隔离和下一章补读；[记录](NF-E07_POV_FINAL_RESULTS.json)。

本轮PHP Pint、git diff --check、3条新增路由与A04清单哈希核对通过。未重跑全仓单进程测试；此前128MB内存耗尽问题不由本轮相关/专项通过宣称解决。也未执行真实模型/语义相关性评测、前十章成品、前五章伏笔至百章回收、千章性能、真实浏览器或备份恢复演练。

## 开发库迁移与运行

开发库novel_forge先创建0600 custom备份并经pg_restore --list核对，再仅迁移000025，退出0。5表57字段中文备注完整，全部新表0行；迁移前后users=1，novels/providers/model_profiles/ai_requests=0，未导入测试小说/供应商/派生记录。[备份路径、SHA256及计数](NF-E07_MIGRATION_RESULT.json)。归档目录核对不等于恢复演练，备份本体不入库。

运行沿现有scheduler和包含memory的Redis worker；`php artisan novelforge:canonical-memory --limit=100`可补投，`php artisan queue:work redis --queue=memory --timeout=60 --tries=1`消费。任务超时/租约60秒，现有retry_after=120秒；更新已运行的消费者时按C02操作文档使用queue:restart共享缓存并核对新进程。本轮独立新Worker已验证，不表示已部署生产守护进程。测试专用Redis收尾关闭，不修改其他项目服务。

没有新增阻塞离线实现的用户选型。真实供应商、价格/预算、语义嵌入、创作验收与部署仍按DEC-01/02/03/04/05决定；中文分词最低优先级。下一章衔接限现有冻结窗口，跨窗滚动计划、整书自动编排、历史重基线及大规模索引重建仍归对应后续任务。
