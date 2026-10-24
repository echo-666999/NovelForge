# NF-C06 分类恢复、暂停取消与人工决策验收

日期：2026-10-24。范围仅 NF-C06；保留此前 NF-C05 未提交改动。没有真实模型调用、生产消费者启动或中文分词操作。

## 实现及边界

- `RecoveryPolicy` 区分可重试技术故障、容量/预算 deferred、来源 stale、证据问题、文学失败及 unknown。可确认的技术错误最多三次尝试，指数退避加抖动，持久保存并尊重供应商 Retry-After；未知异常不会自动当成网络请求未发出。
- `GenerationService` 在无路由配置的旧支架上也检查请求和费用未决；pending/unknown 请求、reserved/unknown 费用均阻止重新认领。过期且有未决请求的步骤由看门狗转 blocked，运行转 needs_attention，不无限重发。
- 暂停、取消和转人工在小说/运行锁内结束旧活动步骤，保留不可变历史。即使暂停后马上恢复，旧 Worker 也不能提交。迟到输出进入独立不可变隔离证据，不覆盖有效产物或消费回执；网关迟到响应继续保存脱敏证据及核销已知 Usage。
- 取消不可恢复；发布、消费、认领及网关均保留控制门禁。已发出的外部请求可能继续计费，取消不会抹掉风险预留。
- 恢复预览列出各节点 reuse/checkpoint/redo/stale、技术尝试、原始产物及哈希、未知请求/费用、人工建议、迟到证据和决策历史。声明变更的节点和所有下游列为 stale；正式状态改变时全图保守失效。原历史不删除、不修改。
- 所有人工动作要求原因及 preview_hash；小说先锁、运行后锁，拒绝过期预览；幂等键重放首次响应，相同键不同内容拒绝。已存在下一尝试意图时不能通过另一个决定偷偷替换模型。模型切换仅允许 C04 冻结的批准主备清单，并由新尝试保存选择及原因。
- 成功产物不因后续失败丢失；恢复使用 C01 校验后的检查点，不把失败/隔离产物强制标记成功。技术 attempt_no 与文学自动修订分开，当前文学执行器未接入，明确展示 0/未实现，不用技术重试冒充文学修订。
- 重新提取先保存原 artifact_id/content_hash 和 rewrite_source=false。`replayEvidence` 提供 testing-only 离线适配点，独立保存 `test.extract-v1` 结果、幂等重放，不修改原文；测试验证来源及原产物不变。实际事件提取执行器属于后续业务任务，后台登记决定显示 awaiting_stage_implementation，不能声称已提取正式事件。
- 配置变更动作保存影响预览与人工决定；不覆盖旧运行冻结配置，不自动迁移到当前默认配置。真实的新配置执行链须在相应生产阶段接入后显式创建、重验。后台明确显示登记状态，不把登记当作新运行完成。

## 数据与接口

新增 `recovery_decisions`、`generation_late_evidence`、`recovery_outputs`，均有不可变触发器、运行范围索引和复合归属外键。`ai_requests.retry_after_seconds` 仅在 pending 请求完成时保存，此后不可改写。所有新增表与字段均带 PostgreSQL 中文 COMMENT。

API：`GET /api/v1/novels/{novel}/runs/{run}/recovery`；可选 changed_scopes 数组。POST 同一路径下 pause/resume/cancel/manual/retry/reextract/change_plan，沿用同源会话与 CSRF。

公共字段：Idempotency-Key 请求头、reason、preview_hash；retry/reextract 需要 scope，retry 可选 model_version_id，change_plan 要求 changed_scopes。响应包含 action/status/outcome/event_id/source/decision_version。主要错误：STALE_RECOVERY_PREVIEW、RECOVERY_BLOCKED、RETRY_ALREADY_SCHEDULED、UNAPPROVED_FALLBACK、INVALID_SOURCE、RUN_NOT_EXECUTABLE。

Filament `/admin/recovery-desk`：最近100条自己的运行、小说/运行定位、变更范围、恢复预览、阶段筛选、原产物、费用/迟到证据及决定记录、原因和批准备用模型字段；动作确认后调用同一服务。没有运行时显示真实空态；没有强制通过按钮。本页不替代 NF-C07 完整运行中心、审计链和账本联调。

## 验证结果

完整回归：229项，228通过、1项中文分词跳过，1678断言，232.375秒。该次回归之后补充了完成运行的回执补偿、取消后已有意图的幂等重放及恢复预览下一投递时间，最终定向结果另列，未声称收尾后重跑全量。

- `NF-C06_TEST_RESULTS.json`：完整回归。
- `NF-C06_TARGET_TEST_RESULTS.json`：收尾后定向71项全部通过，677断言，65.473秒。覆盖恢复/并发、队列/Redis、租约、配置与网关。
- `NF-C06_MIGRATION_RESULT.json`：开发库增量迁移、备份及中文备注核对。

开发库已在备份后执行单个 C06 增量迁移，用户1→1、小说0→0；没有供应商、模型或真实请求新增。3张新表、31个表内字段及1个既有表新增字段均有中文备注。备份归档列表验证成功，实际恢复未执行。

本轮测试覆盖：暂停→恢复→迟到结果隔离及去重、心跳不得复活；取消后的发布/消费/新意图拒绝；陈旧预览与命令冲突；独立进程互斥决定；成功产物与检查点保留；来源变更下游失效；旧证据离线重提取；三次上限和退避；未知请求阻止过期重领；迟到响应计费；API越权及 Filament Livewire 操作；新增数据库备注；既有队列/规划/配置/费用回归。

本轮变更通过 Pint；`git diff --check` 无错误，9条恢复台及API路由注册正确。HTTP/Livewire 验证不等同真实浏览器视觉验收。

## 操作与待接入事项

1. 打开恢复台，定位运行并刷新预览。先查看已有产物、请求和风险预留，再决定暂停/取消/转人工。
2. 对暂停或人工状态，只有来源未过期且没有未决请求时可恢复调度；中断的阶段再明确执行重试，不隐式接受迟到正文。
3. 预算 deferred 不自动扩额度；新政策或配置只能明确用于新运行。unknown 的账务对账不等同请求结果确定，不能据此默认重发。
4. 新部署应按 C02 操作说明重启长驻 Worker；本轮没有启动或重启生产 Worker。
5. 开发库迁移前备份；实际恢复演练、浏览器视觉/移动端、真实供应商与文学验收未执行。

没有新增需要用户批准才能完成离线底座的决定。DEC-01/02/05、A02 和真实业务执行器接入仍保持原边界。AC15/AC19 本轮证明编排与 Fake 请求恢复语义，不表示真实场景、前10章或百章伏笔验收通过。
