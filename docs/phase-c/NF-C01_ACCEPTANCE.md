# NF-C01 持久任务与运行编排基础：实施与验收

日期：2026-10-23（Asia/Shanghai）。本轮仅实施 NF-C01；新增代码添加中文注释，保留既有 B06 改动，不调用真实模型，不安装或修改中文分词。

## 交付与范围

提供 GenerationService 的 start/read/advance 应用服务、StageExecutor 接口、StageResult 结果契约，以及 tests/Support/FakeStageExecutor 确定性测试支架。当前仅支持 test.echo / test.echo-v1，启动和执行强制要求 testing 环境；不注册生产容器绑定、HTTP 启动入口、队列任务或 Filament 运行页面。read 保持管理员及小说所有权检查。既有工作台的 generation_eligible=false、未知正式指标保持原义。

本轮验证的是无外部费用的阶段编排，不是小说生产管线。UI11/UI12 完整运行页面属于 C07；POST generation-runs 属于 F01；完整恢复操作属于 C06；outbox、租约、fencing、心跳和僵尸回收属于 C02。真实 Provider、Prompt、价格、预算和正文等尚未实施，不能将测试图误当成已冻结这些生产配置。

## 数据库、约束与存储

新增迁移：2026_10_23_000006_create_generation_foundation.php。

| 表 | 实际字段与用途 | 约束 / 索引 |
| --- | --- | --- |
| generation_runs | novel_id、actor_id、outline_version_id、expected_state_version、command_key/request_hash、frozen_config/input_hash、status/error_code、created_at/updated_at | 同书规划复合 FK；actor+novel+key 唯一；status+updated_at 索引；身份/输入禁止修改或删除 |
| generation_steps | novel_id/run_id、stage/scope_key、attempt_no、frozen_input/dependency_hash、status/error_code、checkpoint_artifact_id、resume_artifact_id、时间戳 | run+stage+scope+attempt 唯一；queued/running 同节点部分唯一索引；尝试1–3；终态行不可修改；冻结输入不可改 |
| generation_artifacts | novel_id/run_id/step_id、type、schema_version、content、content_hash/source_hash、storage_ref、created_at | 同运行同阶段复合 FK；每尝试一份结果/证据；UPDATE/DELETE 均由数据库拒绝 |
| generation_dependencies | novel_id/run_id/step_id、source_step_id/source_artifact_id | 物化所消费的成功产物边；同运行复合 FK；禁止自引用、重复边与更新删除 |

当前小型测试产物存私有 PostgreSQL JSONB，storage_ref 为唯一 db:UUID 标识，不是公共下载 URL，也不建立文件副本。内容 hash 使用已有 json-canonical-v1：对象键排序，列表保持顺序，不接受浮点。大文本对象存储及生产产物格式留给对应阶段。

整个 DAG 存在不可变 frozen_config 中；尚未领取的节点不预造 attempt。图要求按拓扑顺序提交，1–100个节点；拒绝未知、自引用、重复或前向边，因此不能形成循环。每节点固定 key、stage、depends_on、input.text。goal 固定 all_nodes_succeeded，不能由最后一次执行成功替代整图完成。

## 冻结身份与执行流程

1. start 验证管理员、书归属、未归档、expected_version 与同书 sealed 规划哈希；冻结规划 ID/hash、真实 state_version、完整测试图、schema/executor 版本。未知配置不接受。
2. 创建持有小说行锁，同一作者/书/创建键只生成一个运行。复用现有不可变 novel_commands，命令域 generation.test.start 保存首次响应；同键同内容重放原响应，同键不同内容报 IDEMPOTENCY_CONFLICT。运行推进不修改小说 row_version/state_version。
3. advance 以小说共享锁、运行行锁领取阶段，确认上游最新尝试成功，复核内容、来源与冻结输入哈希；将实际依赖的 step/artifact IDs 和 hash 冻结到尝试，并物化依赖边。
4. 释放事务后调用 StageExecutor；返回后重新锁定并校验正式状态。产物、检查点、步骤状态与运行状态在同一事务落盘。执行异常/非法输出保存脱敏失败证据；不保存异常中的敏感文本。
5. deferred 保存不可变检查点；明确指定 retryOf 旧尝试号才可创建新尝试。新尝试以 resume_artifact_id 及 frozen_input.resume_checkpoint 固化续跑来源；旧失败与旧产物不改写。重复相同重试请求不额外分配尝试，最多三次，不无限循环。
6. 成功产物只在输入及依赖身份一致、内容/来源 hash 校验通过时复用。身份变化报 DEPENDENCY_MISMATCH，不替换旧产物；本轮尚无生产迁移依赖的交互入口，调用者只能创建新的测试运行。执行中状态变化则保存 quarantined 证据，运行 needs_attention，不计成功。

冻结的规划版本身份不会因新版本存在而自动替换；正式 state_version 变化则使原运行输入不再满足。生产运行的 Prompt/路由/能力/价格/上下文等必须由后续实际模块扩展契约，不能填入虚构值。

## 状态语义与恢复边界

| 状态 | C01 的实际含义 |
| --- | --- |
| queued | 图已落盘，尚未领取 |
| running | 一个测试阶段已领取，尚未原子落盘 |
| waiting_dependency | 尚有未完成节点，不能越过上游依赖 |
| deferred | 已持久化续跑检查点，等待明确重试 |
| blocked | 测试阶段报告阻塞，保留原因；不是技术失败 |
| failed | 执行或输出校验失败，保留证据 |
| canceled | 测试阶段的终止结果，后续执行受阻；采用 A04 单 l 拼写，任务清单 cancelled 为同义文字 |
| succeeded | 所有声明节点的最新尝试均成功 |
| needs_attention / quarantined | 运行 / 步骤的依赖过期隔离结果 |
| paused | 数据库为后续契约保留，不提供本轮暂停命令 |

重建服务或更换进程可从数据库读取图、步骤、失败、产物和检查点，并继续未执行节点或显式续跑 deferred。若进程在领取后中断，running 行保留且重复请求不会抢占；本轮没有租约，不能声称自动恢复在途僵尸步骤。C02 必须补齐失效认领与 fencing 后才可开展 Worker 崩溃恢复验收。取消迟到请求、暂停/迁移/人工重试 UI 不是本轮交付。

## 自动化验收

GenerationTest 与 GenerationConcurrencyTest 覆盖：

- 图依赖等待、整图完成、服务重建恢复、复用成功产物且不重复执行。
- 重放首次创建响应、内容冲突、冻结输入保持不变。
- 循环/未知/重复依赖拒绝；跨书规划、归档书、越权和生产环境测试入口拒绝。
- deferred 检查点、明确尝试号续跑、重复重试幂等、三次上限。
- blocked/failed/canceled 区分，旧失败与旧产物保留。
- 执行中正式状态变化隔离、依赖身份和损坏内容 hash 反例、异常脱敏、非法结构化输出失败。
- 数据库禁止覆盖/删除产物、改写冻结输入/终态、重复尝试及跨运行依赖。
- 两个真实 PHP 子进程并发创建只保存一个运行；另两个进程竞争同一检查点续跑只执行一次；新的进程继续下游，最终达到整图成功。

既有 B01/B02 测试由“不存在生成表”更新为“规划动作不创建生成运行”；保留正式状态/正文等未授权表不存在的检查。空库回滚/重建覆盖 B01–B05+C01 六个业务迁移。发现 PostgreSQL migrate:fresh 保留函数对象，已改用 CREATE OR REPLACE FUNCTION，后续重复迁移测试验证该问题。

最终完整回归：**153项，152通过，1中文分词项跳过，842断言**，用时162.054秒。结果见同目录 NF-C01_TEST_RESULTS.json。运行环境为项目 PostgreSQL 18 测试库 novel_forge_test；测试 Redis 为专用 Unix socket。新增两个测试类共13项；早期12项定向测试通过后补充跨书/归档和唯一约束断言，最终以全量结果为准。

本轮12个新增/修改 PHP 文件经 Pint 格式化；git diff --check 通过。未修改前端资源，因此本轮未重新运行前端构建或声称浏览器视觉验证。既有未提交 B06 工作保留。

## 待决事项与未验收内容

没有新增阻塞本任务的用户决定。DEC-01/02 的模型及预算等仍在对应节点前确认。未做真实模型、真实生产队列、浏览器视觉、性能压测或文学成品验收。前十章成品和第100章伏笔回收尚未达到可执行阶段，不能据本轮编排测试宣称通过。中文分词继续处于最低优先级后期优化。


## 开发数据库迁移结果

只对核实过的本机 novel_forge 应用 C01 新增迁移，退出码0；未执行开发库清空或回滚。迁移前完成自定义格式备份并通过 pg_restore --list 检查，尚未做真实还原演练。

- 用户数：1 → 1；小说数：0 → 0。
- 迁移后 generation_runs / generation_steps / generation_artifacts 均0条，没有开发库测试运行。
- 备份：`/Users/webb/Herd/NovelForge/.local/backups/nf-c01-before-20261023-141032.dump`。
- SHA-256：`d162af15de6220ee632ddde425fbe8e8e926bc3af7f9520b1f04b990026b42b4`。
- 备份保存在 .local 私有目录，不提交凭据或数据库归档。
