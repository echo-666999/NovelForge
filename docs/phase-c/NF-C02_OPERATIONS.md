# NF-C02 队列运行与消费者更新说明

适用范围：C02 基础设施。当前唯一注册执行器为 testing 环境的 test.echo，生产环境尚无可执行生成阶段。以下生产命令说明未来同一基础设施的部署方式，不代表已开启生成或部署守护进程。

## 启动与时间关系

在项目根目录操作：

```sh
php artisan schedule:work
php artisan queue:work redis --queue=planning,generation,review --sleep=1 --tries=1 --timeout=60
php artisan queue:work redis --queue=memory,export,maintenance --sleep=1 --tries=1 --timeout=60
```

长驻进程须由部署环境的进程管理器维护；本轮没有安装系统服务。多服务器可重复启动扫描器，数据库 SKIP LOCKED 与回执保证安全；无需依赖 Redis 中的扫描锁保证正确性。

| 项 | 本轮固定值 / 规则 |
| --- | --- |
| Job / Worker timeout | 60秒；Job 自身固定60，不能仅修改 CLI 就声称已调整 |
| PostgreSQL 租约 | 90秒，时间来自 clock_timestamp() |
| 心跳 | 长阶段通过执行输入 _lease 的 id/token/owner 调用 GenerationService::heartbeat，建议每30秒；同步阻塞不能假装自动续期 |
| Redis retry_after | 默认120秒，测试隔离也120；投递器拒绝≤90秒的实际配置 |
| 数据库通知重投间隔 | 60秒；发布者取批后中断也由此到期补投 |
| 扫描频率 | Laravel 每分钟执行 novelforge:dispatch；调度器正常运行时，过期租约下一轮被识别，目标小于5分钟 |
| 重领上限 | 单个测试步骤最多3个 fencing 令牌；到上限变为 failed / LEASE_EXHAUSTED，不无限重发 |
| 队列自身尝试 | tries=1；失败记录不替代 outbox，恢复由数据库意图控制 |

生产测试之外的真实模型阶段尚未注册。将来真实请求的未知结果必须由 C03/C05/C06 联合处理 uncertain/预算与请求身份，不能复用“测试阶段可安全重领”的假设。不要对未来有外部费用的任务直接 queue:retry all。

## 投递与队列职责

startQueued 在同一 PostgreSQL 事务创建运行、幂等响应与各节点 outbox 意图；enqueue 可把显式重试意图加入调用者事务。事务内不发送 Redis。消息只携带 eventId，不带可执行类名、模型密钥或任意配置。

planning 规划、generation 生成、review 评审、memory 记忆、export 导出、maintenance 维护为队列分工。当前 stage_queues 只有 test.echo→generation；其余名称是基础设施分工，不表示业务阶段已经实现。新增阶段必须由其授权任务加入受控映射，不允许从消息任意选择队列或类。

published_at 表示曾确认发送，**不表示消费完成**。只有不可变 consumer_receipts 才表示对应尝试终态已落盘。没有回执的通知一分钟后可再次发送；Redis 重启丢消息也不需要重建运行。消费者在事务内保存结果、步骤/运行状态与回执。重复通知只读取已有结果。

书级 generation_book_slots 在真正领取队列管理步骤时占用，绑定整次运行；只完成一个步骤不释放。运行成功、失败或取消且没有仍在执行的步骤才释放；deferred/blocked/paused/needs_attention 保留占用，需要后续明确恢复策略。等待依赖的通知、旧通知和已完成步骤不重新占槽。未加入队列的 C01 测试支架不属于正式通道；未来 F01 必须使用受串行约束的持久队列入口。

## 正常更新消费者

1. 保存部署前版本和数据库备份，按迁移顺序部署兼容 schema 与代码；不要直接删除旧运行、outbox、回执或失败记录。
2. 校验实际配置：Job=60秒、租约=90秒、Redis retry_after>90秒。若存在配置缓存，应按部署约定重建；不要在测试环境使用缓存配置。
3. 在与旧 Worker 相同项目及缓存命名空间执行：

   ```sh
   php artisan queue:restart
   ```

4. 等待旧 Worker 完成当前任务后退出，由进程管理器拉起新 Worker；核对旧 PID 已消失、新 PID 已启动且读取新代码，再观察待处理意图和失败记录。当前本机 CACHE_STORE=file，同一主机/同一 storage 路径可共享重启信号；多主机部署须使用共享缓存或分别重启，不能假定文件缓存跨主机传播。
5. 确认调度器运行，执行一次 `php artisan novelforge:dispatch --limit=100` 补投；命令输出 expired_steps、unacknowledged_events、published、failed。失败数字非零需排查，不以命令启动成功代替任务完成。

本轮自动化在独立 Worker 与隔离文件缓存上验证 queue:restart 后旧进程退出，新进程继续消费；没有重启开发/生产消费者。

## 故障处理

- PostgreSQL 提交前中断：运行与意图一起回滚，不应存在可消费通知。
- 提交后/发送前中断：意图尚无回执，下一轮投递器会发送。
- 发送后/确认前中断：允许重投，由租约与回执去重，不能宣称消息本身恰好一次。
- Redis 不可用：outbox 保留，last_error=DELIVERY_FAILED；恢复后等待60秒或重新运行调度器，确认回执增长。
- Worker 被终止：租约到期后扫描识别；新 Worker 认领同一测试尝试并增加 fencing_token，旧令牌不能心跳或写结果。
- Worker 未停但租约失效：旧结果被拒绝；不是“先到的结果自动胜出”。期限在领取、心跳、提交时使用数据库时间校验。
- LEASE_EXHAUSTED：已用完3次认领，保留失败；本轮不提供人工“强制通过”或通用恢复 UI。
- 运行状态/依赖改变：执行前后均重新校验，过期依赖隔离，暂停/取消不自动提交成功结果。完整暂停取消命令仍属 C06。
- 超过5分钟未识别过期步骤：检查 schedule:work/外部 cron、PostgreSQL 可用性和投递命令；该目标依赖运行中的调度器，不是数据库自行执行定时任务。

不使用 flushall、清空业务库、删除历史产物或手工减少 fencing_token 来恢复队列。

## 隔离验证环境

测试实际连接仅允许 novel_forge_test，Redis 仅允许项目 `.local/redis-testing.sock`，禁用 TCP 与持久化。测试中会真实停启此专用 Redis、真实终止测试 Worker，并故障注入 lease_until/next_delivery_at 来缩短等待。没有改动开发 Redis 或调用模型。专用 Redis 的停止、重新启动和就绪探测均属于测试支架，不在业务代码中执行系统服务命令。
