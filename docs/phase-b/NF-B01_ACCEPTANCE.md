# NF-B01 实施与验收记录

日期：2026-10-23（Asia/Shanghai）。范围：小说创建与原始要求追踪。仅执行 NF-B01；没有实施 B02 及后续业务，没有调用真实模型，没有安装或调整中文分词。

## 交付状态

**NF-B01 可独立运行的核心范围已实现并通过自动化验证；涉及后续计划模块的联合验收仍待接入。** 不将此结果记成整个 UI03/UI05、计划覆盖或冻结闭环全部验收通过。

已实现：小说创建、元数据乐观锁、原始输入不可变版本、要求提炼候选与修正版本、冲突及作者决定、归档/恢复、复制小说输入设定、新书要求重新待确认、同源会话接口、Filament 页面、数据库约束与回归测试。

依赖边界：A04 字典将 requirement_coverages 分配给 B01/B05，目标 plan_nodes 在 B02/B05 才建立。为避免推进其他业务或引入无真实外键的假引用，本轮不建空壳计划表、不接受手填 plan_node_version_id、不开放 covered 写入，也不宣称已实现真实计划跳转。覆盖矩阵当前显示未覆盖/冲突/不适用；snapshot 返回明确的冻结阻塞原因。后续 B02/B05 必须增加覆盖表、同书复合 FK、证据校验、跳转及冻结联调。工作台 UI04 属 B06，本轮入口打开原始要求页，不冒充完整工作台。

## 数据库与服务

新增一条迁移，创建以下五张表：

| 表 | 用途与约束 |
| --- | --- |
| novels | 作者归属、标题题材、原始创意当前镜像、目标章节、字数范围、输入设置、状态、row_version、state_version；章数及字数约束；按作者/状态/id、题材/更新时间索引 |
| premise_versions | 每书递增原始输入版本、原文字节、完整字段快照、规范哈希、变更原因；UNIQUE(novel_id,version) 和 UNIQUE(novel_id,id) |
| requirement_items | 稳定要求 ID 与当前版本号；UNIQUE(novel_id,id) |
| requirement_versions | 来源版本、准确原句、提炼内容、分类/强度、关键性、冲突、作者决定、原因及操作者；要求和来源均用 novel_id 复合外键；要求版本唯一 |
| novel_commands | 仅 NF-B01 的同步幂等命令结果；actor+novel_scope+command+key 唯一，规范请求哈希与响应快照同事务提交 |

premise_versions、requirement_versions、novel_commands 有数据库触发器禁止 UPDATE/DELETE；外键 RESTRICT。新表均使用 PostgreSQL，未用 SQLite 替代。正式历史、运行 DAG、outbox、预算策略与 Canonical Commit 表尚未建立。本轮不提前实现 C01 的通用幂等模块；将来统一命令存储时需保留现有成功键的兼容重放。

应用服务 `NovelIntentService` 是后台与接口共同入口：

- 服务内验证管理员与小说归属，越权对象按404处理；不只依赖按钮隐藏。
- 建书保留输入内容，不将 CRLF、空白或 emoji 改写为提炼结果；规范 JSON 哈希保持数组顺序、排序对象键并拒绝浮点。
- 按背景/剧情/禁用内容/结局原句建立确定性候选，不调用模型，也不把候选当作作者批准。
- 修改原始创意追加来源版本，旧版本不可覆盖；修改要求追加版本，保存准确来源、作者决定及修改原因。
- 硬约束不能标记不适用；只有偏好可在作者说明理由后标记不适用。接受要求不等于有计划覆盖。来源变更、未覆盖硬约束和关键待决定项均列入冻结阻塞原因。
- 行锁与 expected_version 防止旧窗口覆盖；同一幂等键的事务级 advisory lock 防止并发重复创建。相同载荷返回原响应，异载荷409。
- 归档书只读，可恢复或复制设置；生成中状态不能归档。复制当前小说输入设置为独立草稿，重新生成待确认要求；不复制旧书要求决定记录、规划、正文、正式状态或费用历史。

## 接口

同源会话鉴权及 CSRF。JSON ID 为字符串，时间包含时区。写操作须 Idempotency-Key，已存在小说的写操作须 expected_version。CSRF 在 Laravel Web 中间件执行；无有效 CSRF 的修改请求可能先返回419。使用有效会话/CSRF后，未登录401、非管理员403、越权对象404。

| 方法 / 路径 | 行为 |
| --- | --- |
| POST /api/v1/novels | 建书，201，返回小说、来源、要求候选与冻结阻塞信息 |
| PATCH /api/v1/novels/{id} | 修改 title/genre；禁止写正式状态、state_version或来源正文 |
| GET /api/v1/novels/{id}/intent | B01 意图详情，包含原始版本、当前要求及阻塞项；不是 B06 工作台摘要 |
| GET /api/v1/novels/{id}/requirements | 当前要求游标分页，默认20/最大100；strength、decision_status、source_version_id筛选 |
| POST /api/v1/novels/{id}/source | 追加完整原始输入快照；需完整输入字段、reason、expected_version |
| POST /api/v1/novels/{id}/requirement | 新增或修正要求；requirement_id缺省为新增，存在则追加版本；需来源准确引用、内容、分类、强度、关键性、决定状态及reason |
| POST /api/v1/novels/{id}/archive | 归档 |
| POST /api/v1/novels/{id}/restore | 恢复归档前非活动状态 |
| POST /api/v1/novels/{id}/copy | title+expected_version，201，新书输入设置副本 |

创建/source字段：title、genre、background、premise_raw、target_chapters、length_policy.min/max 必填；target_reader、selling_point、forbidden_content、style、ending_preference、budget_note可选。budget_note只是预算意向文字，不是已生效的预算、额度或币种。

成功包 `{data,request_id,version}`；错误包 `{error:{code,message,fields,retryable,suggested_action},request_id,version}`。API额外端点是A04允许由所属模块细化的页面CRUD；没有实现蓝图、章节、生成等接口。

## Filament 页面

| 页面 | 已实现 |
| --- | --- |
| /admin/novels | 书名/题材/状态/正式章数/字数/费用/最近活动；题材、状态、归档、日期范围筛选；默认25条、10/25/50/100分页；创建、修改元数据、复制设定、原始要求、归档、恢复 |
| /admin/novels/create | 输入→提炼约束→确认歧义→保存四步；展示原句候选，确认待决定项保留；全部PRD输入字段可录入 |
| /admin/novels/{id}/intent | 原始输入版本只读展开；要求原句、提炼、分类强度、冲突、作者决定、覆盖状态；强度、决定、覆盖、来源版本筛选；分页；新增/修正要求和来源版本；历史版本展示 |

正式章数/字数标明尚未接入，费用显示未知，不填虚构0；归档不提供修改入口；无直接删除及冻结按钮。页面状态使用 A04 的小说状态集合，本轮只产生 draft/archived；accepted 是作者接受要求的决定状态，覆盖状态仍独立为 unresolved/conflict/waived。

## 实际验证

- 最终 `php artisan test`：**48项，47通过、1跳过，204断言**。跳过项仅为既有中文分词配置测试；本轮真实独立Redis读写与队列探针已运行。
- NF-B01定向回归曾单独运行：18项通过、117断言；之后补入真实双进程竞争及空库回退，最终以完整48项结果为准。
- 两个真实 PHP 子进程同时使用同一幂等键建书，返回一致结果，仅产生1本小说和1条命令记录。
- PostgreSQL真实验证：跨书来源外键拒绝、不可变原始版本拒绝修改、要求版本拒绝删除；字数输入校验通过；空测试schema可回退最后一条迁移并重新应用。
- HTTP验证：无权限/越权、缺幂等键、异载荷重试、乐观锁、非法状态写入、无效原句、硬约束放弃、冲突决定、归档只读、恢复、复制、分页/过滤及CSRF拒绝。
- Filament/Livewire验证：创建向导提交、列表筛选、要求动作实际落库、追加来源版本、覆盖筛选、归档实际落库、旧版本操作通知且不覆盖新值；页面HTTP渲染通过。
- 最终检查统一游标与bigint ID范围后，额外定向分页回归：1项通过、10断言；相关文件格式检查通过。
- 本轮变更PHP文件定向 `vendor/bin/pint --test`：通过。`npm run build`：通过；工具提示可选 fontaine 缺失，不影响此次构建完成，本轮未安装额外依赖。
- 未进行人工浏览器视觉/移动端验收；Livewire测试不等于人工浏览器交互。未运行真实模型、十章/百章文学验收或完整计划覆盖验收。

开发过程中发现并修正：测试重建保留PG函数导致重复创建；后台越权错误状态码；Filament测试辅助方法未实际提交动作；分页游标校验误读框架返回格式；PDO将JSON问号运算符识别为参数。最终全量重跑通过，未掩盖或以早期结果替代最终验证。

## 开发库增量迁移

已先创建开发库 custom-format备份，并以 pg_restore --list 检查归档可读取，再只执行 NF-B01 新增迁移（退出码0）。用户表记录数前后均为1，小说表为0，未写入示例小说。未执行开发库 migrate:fresh，未更换账号或修改真实凭据。

备份文件：`/Users/webb/Herd/NovelForge/.local/backups/nf-b01-before-20261022-161957.dump`。SHA256：`4d4c51a89e87bed2d843f1d08b1fd338f116f30f7bbc46d669a9725ac15c854f`。文件名按进程UTC时间生成，报告日期为上海时间。此备份已检查归档目录，**未进行实际恢复演练**。

回退边界：down会删除本任务五张表，只有空测试库验证过回退。开发库产生小说后不可盲目 rollback；应先保护输入/要求/幂等记录并采用前向修复，确需恢复时按备份范围另行操作。

## 后续接入清单（本轮不执行）

1. B02/B05：真实 requirement_coverages 复合外键、计划版本证据、承接节点跳转、来源变更后的覆盖失效与冻结联合验收。
2. B06：完整工作台及真实进度指标。
3. A02：最小权限账户与运行托管；当前仍用既有数据库账号。
4. 模型、预算、验收小说的待决事项仍按原阶段节点解决；中文分词仍最低优先级。

本报告不授权继续实施其他任务。
