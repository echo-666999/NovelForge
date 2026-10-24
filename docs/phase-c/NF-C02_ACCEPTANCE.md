# NF-C02 事务 outbox、租约与队列幂等：实施与验收

日期：2026-10-23（Asia/Shanghai）。仅实施 NF-C02，新增代码包含中文注释，不推进 C03/F01 等业务，不调用真实模型，不处理中文分词。

## 实施内容

- OutboxService::startQueued/enqueue：运行和任务意图同事务提交，复用 C01 创建幂等；通知发布在事务外。
- OutboxService::publish/recover/consume/acknowledge：分批 SKIP LOCKED 领取、60秒可重投、投递错误脱敏、数据库回执、租约扫描。
- ConsumeGenerationIntent：真实 Laravel Redis Job，仅携带意图 ID，timeout=60、tries=1。
- GenerationService::claim/complete/heartbeat：数据库租约、单调 fencing_token、独立 owner UUID、旧 Worker 拒绝、心跳与提交到期校验。
- generation_book_slots：队列管理运行的同书串行槽，跨步骤保持，不依赖 Redis 锁。
- novelforge:dispatch 命令与每分钟调度；操作说明见 NF-C02_OPERATIONS.md。

当前测试执行器为 test.echo-v1，生产执行仍被拒绝。未增加生产启动 HTTP 接口、运行中心或人工恢复页面，不注册真实供应商。C01 的 advance 保留便捷调用，但内部已通过 claim/complete 使用租约。

## 数据库与契约变化

迁移 2026_10_23_000007_create_queue_orchestration.php：

| 结构 | 字段 / 约束 |
| --- | --- |
| generation_steps 增列 | fencing_token bigint 默认0且单调不减；lease_owner uuid、lease_until、heartbeat_at；running 过期扫描部分索引 |
| outbox_events | novel_id/run_id 同书复合FK、scope_key、retry_of=0..2、受限 queue、创建/发布/下次投递时间、delivery_count、last_error；run+scope+retry 唯一；意图字段不可改删 |
| consumer_receipts | event_id 主键及FK、completed_at；回执不可更新/删除 |
| generation_book_slots | novel_id 主键、run_id 唯一、同书FK；一个书最多一个队列运行占槽 |

C01 不可变输入与终态保护保留，仅允许执行中租约字段更新；旧终态尝试不覆写。步骤认领时原子进入 running 并获得有效租约，不另持久化短暂 leased 状态。重新认领同一测试尝试增加 fencing_token，**不增加 attempt_no**；明确 retryOf 才分配新的业务尝试。三次重领与最多三次阶段尝试是不同计数，不能冒充真实模型网络重试策略。

重试通知带固定旧尝试号：首尝试尚不存在时不能越序创建；旧通知不能认领更新的重试尝试。图依赖仍检查不可变来源 hash。回执只在意图对应的确切尝试终态后生成，不以 Job 返回或 published_at 代替。

## 并发与恢复证据

新增 OutboxTest 和 OutboxRecoveryTest，覆盖：

| 验收点 | 验证方式 |
| --- | --- |
| 运行与 outbox 原子性 | 在调用外层事务故障回滚，运行、幂等记录与意图均不增加 |
| 发布前中断可补投 | 已提交意图 published_at 为空，真实 Redis 发布并消费 |
| 发布确认不确定时重复 | 重投同一意图、真实 queue:work 消费，产物和回执只各一份 |
| 结果与回执同事务 | 在完成事务后注入异常，产物与回执同时回滚；之后可正常完成 |
| Redis 真正重启 | shutdown 专用服务、启动新服务并探测 PONG；队列消失但 PostgreSQL 意图保留，调度器补投后独立 Worker 完成 |
| Worker 真正中断 | 独立 PHP 进程领取后发送终止信号；故障注入租约到期，两个新进程竞争，仅一个有效产物 |
| fencing 与心跳 | 有效令牌可续期；错误/到期/旧令牌不能续期，旧 Worker 无法写结果，新令牌可完成 |
| 限制重领 | 三次认领后再次到期变为 LEASE_EXHAUSTED，不生成伪造成功产物 |
| 书级串行 | 第二运行不能越过第一运行，第一阶段完成不释放整书槽；整图终态后释放 |
| 乱序与旧通知 | 提前重试不创建首尝试，旧通知不重领新尝试，失败下游不抢回槽 |
| 运行控制二次核验 | 领取后取消的结果保存为 quarantined，运行保持 canceled，不成功提交 |
| 消费者更新 | 隔离文件缓存发 queue:restart，旧常驻 Worker 退出，新独立 Worker 消费后续运行 |
| 配置及不可变约束 | retry_after≤90 拒绝发布，数据库拒绝改写 outbox 身份 |

扫描器每分钟配置，测试以数据库时间故障注入到期并立即扫描，验证识别耗时小于5分钟；没有等待真实5分钟或进行长时间生产监控。Redis 重启测试修复了 daemonize 返回早于 socket 就绪的测试支架竞态，使用有界 PONG 检查。

## 边界与待决事项

本轮无新增阻塞性用户决定。尚未部署守护进程，也未执行真实模型/预算、生产负载、跨主机缓存或文学验收。调度器持续运行是5分钟识别目标的必要前提。测试阶段可安全重领；真实供应商未知结果须留待 C03/C05/C06 联合实现，不得照搬自动重发。完整人工暂停/恢复/取消与运行页面仍待 C06/C07。

C01 当时“没有租约、不能恢复在途步骤”的历史验收结论保持原记录；此限制已在本轮测试阶段范围内补齐。前十章成品和100章伏笔回收仍未进入可验收阶段。

## 最终验证

全量回归 **167项：166通过、1中文分词项跳过、947断言**，用时182.341秒。新增两个测试类共14项。结果保存在同目录 NF-C02_TEST_RESULTS.json。

17个本轮新增/修改PHP文件已完成Pint格式检查；git diff --check通过。PostgreSQL空库回滚/重建覆盖B01–B05、C01、C02共七个业务迁移；原测试隔离与C01回归保持通过。C01取消后重试现在提前返回RUN_NOT_EXECUTABLE（原测试为RETRY_LIMIT），对应执行前控制状态核验，未放宽任何门禁。

本轮未修改前端，因此没有重新执行前端构建或浏览器视觉验收；未调用真实模型，未安装中文分词，未提交Git。


## 开发库迁移

核实本机 novel_forge 后，完成迁移前自定义格式备份并通过 pg_restore --list 检查，仅应用新增 C02 迁移，退出码0；没有开发库清空、回滚或测试数据写入。真实还原演练未执行。

- users：1→1；novels：0→0。
- generation_runs、outbox_events、consumer_receipts、generation_book_slots：迁移后均0条。
- 备份路径：`/Users/webb/Herd/NovelForge/.local/backups/nf-c02-before-20261023-155329.dump`。
- 备份SHA-256：`e5329a92f633ddda525afd12e60641fd0f2b16958d4938d26efb0c6be59717d0`。
- 没有修改开发Redis、部署守护进程或重启生产消费者。测试专用Redis在验证后关闭。
