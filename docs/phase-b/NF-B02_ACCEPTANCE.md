# NF-B02 五层结构与不可变规划版本：实施与验收

日期：2026-10-23（Asia/Shanghai）。本轮仅执行 NF-B02，并对 B01 增加必要的目标章数一致性保护。未调用真实模型，未安装或调整中文分词，未推进 B03 及其他业务任务。

## 结论与范围

**NF-B02 核心结构、不可变版本、接口及后台已实现，自动化验收通过。** Novel 沿用 B01；新增 Volume→Story Arc→Chapter→Scene、全书 Thread 与故事弧关联、稳定节点和不可变版本节点。既有原始要求/来源历史继续保留。

结构版本的 `sealed` 表示内容不可变，不表示业务批准、要求已覆盖或允许生成。工作节点仅有 skeleton/detailed 成熟度；小说进入 planning，不进入 ready。所有结构读取明确返回 `generation_eligible=false`。B04/B05 的蓝图批准、覆盖、字数预算、滚动细化和生成门禁仍待实施；本轮不冒充完整 UI07/UI08 或实际旧运行消费版本的联合验收。

## 数据库与契约映射

新增迁移 `database/migrations/2026_10_23_000002_create_structure_versions.php`，创建八张表：

| 表 | 范围、索引与约束 |
| --- | --- |
| volumes | 根父级为 novel_id；每书 sequence 唯一；起止章正整数且不得超过小说目标；同书卷范围不重叠 |
| story_arcs | 唯一主父级 parent_id→volumes；同卷 sequence 唯一；章范围在父卷内、同书不重叠；不能缩小到排除已有章 |
| chapters | parent_id→story_arcs；每书章 sequence 唯一且必须位于父弧章范围内 |
| scenes | parent_id→chapters；同章 sequence 唯一；保存具体标题和前后场衔接 |
| threads | 同书稳定线索标识、名称、目标、变更原因及 row_version；只实现 B02 关联所需字段 |
| arc_thread | arc_id/thread_id 联合主键；两个引用均带 novel_id 复合 FK；允许不同卷的弧承接同一条全书线索 |
| outline_versions | 每书 version 唯一；source_version_id、base_version_id 同书 FK；input_row_version、schema_version、内容/hash、原因、操作者与时间；building→sealed 只在构建事务内完成 |
| plan_nodes | 每版本每稳定节点唯一；volume_id/story_arc_id/chapter_id/scene_id 四个具体 FK 恰有一个，匹配 node_type；同书同版本 parent_plan_node_id 复合 FK；卷/章全版本序号唯一、弧/场景同父序号唯一 |

四类稳定节点共有：来源版本、序号、标题、目标、摘要、details、成熟度、row_version、修改原因及带时区时间。来源均连接 premise_versions，不是未来的 source_documents。根卷直接用 novel_id，不重复存储同值 parent_id。API ID 为字符串。

A04 中 stable_node_id 的逻辑角色由 plan_nodes 的四个具体外键承接，避免无校验多态引用。版本父级同时校验层级类型与快照中的稳定父级；历史读取从版本节点和快照获取，不追随当前节点内容。

数据库触发器禁止结构版本及版本节点 UPDATE/DELETE（仅允许构建中的版本一次性 sealed），禁止向 sealed 版本补节点；固化时检查节点数量和内容一致。范围检查锁定小说，应用事务统一先锁小说，避免并发重叠与版本重复。历史节点被外键 RESTRICT 保护，无删除接口。新版本不覆盖旧内容或旧哈希。

B04 blueprint_versions 尚不存在，当前结构快照没有假 blueprint_version_id。后续 B04/B05 应新增真实同书蓝图引用和审批记录；既有 sealed 历史不得原地补写为已批准。未来批准必须产生新的版本/独立审批记录并验证其引用。

## 应用服务与失败处理

`StructureService` 为 API 和 Filament 共用入口：

- 管理员及作者归属在服务内校验；其他书的来源、父级和线索均拒绝，数据库复合 FK 二次兜底。
- 修改需要小说 expected_version、非空 reason 和幂等键。小说行锁与版本号拒绝旧窗口覆盖。每次成功修改递增小说 row_version；不改正式 state_version。
- 同 actor/novel/structure.command/key 使用事务级 advisory lock；相同载荷重放原响应，异载荷409；使用 B01 novel_commands，不提前实现 C01 通用运行编排。
- 归档书只读。只允许 draft/planning 中修改。不能通过提交 frozen/approved 状态绕过审批。
- 节点保存为完整字段更新：details 未提供时为空；故事弧 thread_ids 未提供时清空关联。修改端应提交需保留的完整内容，后台编辑会预填现有内容。
- 保存结构版本读取完整当前树和线索快照，规范 JSON 哈希，按父先子后生成版本节点，全部成功后 sealed；中途失败，版本、节点、幂等记录和小说版本号全部回滚。没有异步 Job 或真实模型请求。
- B01 追加输入版本缩短 target_chapters 时，如果已有卷越界则409；数据库更新同样被阻止。先调整规划再修改目标，禁止悄悄截断子节点。

结构 details 是可编辑规划文字：转折、高潮、结束状态、POV、故事时间、地点、冲突、角色变化、伏笔任务、参与者、前后场衔接、禁止变更、必要事件、前置依赖、目标字数。此时不声称它们已验证为世界实体、故事事实、伏笔回收证据或批准后的预算。

## 已实现接口

同源会话、CSRF 与 B01 统一成功/错误包。GET 不需幂等键；所有 POST 必须有 `Idempotency-Key`、`expected_version`、`reason`。状态冲突409，输入错误422，越权对象404，未登录401、非管理员403；CSRF中间件可先返回419。

| 方法 / 路径 | 内容 |
| --- | --- |
| GET /api/v1/novels/{novel}/structure | 当前小说及四层稳定节点、全书线索；按类型/序号返回完整工作树，不是正文列表 |
| GET /api/v1/novels/{novel}/structure/versions | sealed 版本元数据 cursor 分页，默认20、最大100；不返回全部快照正文 |
| GET /api/v1/novels/{novel}/structure/versions/{outline} | 指定不可变快照和版本节点；核验内容哈希；跨书或不存在404 |
| POST /api/v1/novels/{novel}/structure/node | 创建/编辑节点，200；type/source_version_id/sequence/title/objective/status 必填；node_id 有值时编辑；非卷需 parent_id；卷弧需 start_chapter/end_chapter；可提交 summary/details，弧可提交 thread_ids |
| POST /api/v1/novels/{novel}/structure/thread | 创建/编辑同书线索标识，200；title/objective；thread_id 有值时编辑 |
| POST /api/v1/novels/{novel}/structure/snapshot | 固化结构快照及版本节点，201；返回 version 与最新小说 row_version |

不会接受生成、审批、正式提交动作。版本读取路径及游标拒绝无效/超出 PostgreSQL bigint 范围的值。

## Filament 页面范围

入口：小说列表的“五层结构”，页面 `/admin/novels/{id}/structure`。

| 部分 | 字段 / 筛选 / 动作 / 状态 |
| --- | --- |
| 页面头部 | 小说标题、五层关系、尚不可生成的说明；当前草稿/历史版本选择、历史比较；历史只读 |
| 节点表格 | 层级、序号、具体标题、主父级、章范围、目标、摘要/细节、关联线索、来源版本、修改次数、成熟度；覆盖尚待接入明确标示 |
| 筛选及分页 | 层级、对应主父级、弧承接线索、成熟度、序号范围、缺少POV/目标字数；10/25/50/100分页；父级使用层级+ID防止不同表同ID串选 |
| 新增/编辑节点 | 完整规划字段；层级、父级、来源可选择；编辑时保持节点身份；变更原因必填；可调整顺序，冲突时原子拒绝 |
| 全书线索 | 名称、目标；新增/修正说明；同书弧多选关联；不含B04主支线批准和终局控制 |
| 保存结构版本 | 原因必填；固定树、来源和线索内容；展示不可变与非审批提示 |
| 历史与比较 | 历史节点不显示编辑/保存按钮；按稳定节点比较前后内容，包含根输入及线索差异；修改工作草稿后仍能读取旧版 |

UI07/UI08 的批量生成、批准冻结、拆分合并、滚动细化及字数预算平衡依各自任务接入。本轮提供手工结构编辑与顺序调整，不提供未实现动作的假成功反馈。后台实施状态页同步更新。

## 实际验证记录

- 最终完整 `php artisan test`：**65项，64通过、1跳过，305断言**。唯一跳过项是既有中文分词测试；独立测试 Redis 读写/队列探针已运行。
- B02 首轮定向15项全部通过、80断言；后续增加后台筛选/比较和超范围游标测试，计入上述最终全量结果。
- 并发/迁移定向3项通过、25断言：两个真实 PHP 子进程同键创建只生成1节点；两个不同键竞争同一 expected_version，仅1个快照成功，另一返回 STALE_STATE；后续版本序号为2。B01并发建书仍通过。
- PostgreSQL真实约束：跨书父级/来源/线索、重复章/场景序、卷弧范围冲突、缩小范围、目标越界、旧版本/节点更新、sealed版本增补节点、重复版本号均被拒绝；同书多卷承接共享线索的正例通过。
- 历史验证：修改当前章名及线索后，旧具体章名、场景衔接、父版本节点、线索快照及哈希保持不变。未来实际Run消费旧版本的端到端验证待运行模块建立，本轮未伪造Run。
- 失败注入：章节版本节点创建异常，整次快照回滚；重试从版本1正常成功。
- API验证：鉴权、跨书访问、游标分页、无效游标/bigint、幂等重放/冲突、乐观锁、归档和伪造审批状态。
- Filament/Livewire：实际创建/修改卷落库、保存版本、历史动作隐藏、按父级/层级筛选、历史比较；后台HTTP渲染通过。没有进行人工浏览器视觉、移动端或真实点击验收。
- 本轮19个PHP变更文件的定向 `pint --test` 通过；`git diff --check`通过；A04设计包23份文件的SHA256与字节数校验通过；7条结构相关路由注册核查通过。
- `npm run build`通过。仍有既有可选 fontaine 提示，本轮未额外安装依赖。
- 没有执行前十章成品、百章伏笔回收、真实模型或正式规划审批验收；这些不属于B02。

测试只在 novel_forge_test 运行，使用现有DB_URL防绕过及文件/Redis/队列隔离；没有清空开发库。空测试库已验证 B02+B01 回退与重新迁移。开发中遇到的触发器语法和测试辅助方法问题已经修复，并以最终全量结果验证。

## 开发库迁移与回退边界

已先备份 novel_forge，再仅执行本轮新增迁移，退出码0。用户数1→1，小说数0→0；卷与结构版本均为0，未写入示例业务数据。备份归档目录已通过 pg_restore --list 检查，但没有进行恢复演练。

备份：`/Users/webb/Herd/NovelForge/.local/backups/nf-b02-before-20261023-073554.dump`。SHA256：`293a982967b083708af95b736d254a048e5ade0a56b5238dccdb100e39feaaa5`。文件名使用进程UTC时间，本文使用上海日期。

down会删除本轮八张表，仅空测试库回退经过验证。已有规划数据时不要直接rollback；应保留稳定节点、快照、命令历史，优先前向修复或单独制定备份恢复方案。

## 后续联合验收与待决事项

1. B04/B05：蓝图同书FK、审批记录、要求覆盖/真实节点跳转、字数预算及生成门禁。B01中未实现的覆盖联合验收仍未关闭；本轮没有将未覆盖要求改成covered。
2. C/F阶段：真实运行固定 plan_node 版本引用并验证旧运行历史可读。当前已验证基础历史读取与不可变数据库保护。
3. 人工后台视觉/移动端验收尚未执行。
4. 当前B02不需要新增用户决定。模型、费用、嵌入配置、验收小说与部署决策仍按DECISIONS既定节点处理；中文分词保持后期最低优先级。

本记录不授权自动推进后续任务。
