# NF-UI16 故障测试清理与后续用例隔离

日期：2026-10-27；状态：`implemented_core_verified`。基线提交`da517a2447a61e6b7b7571d01a08b9d2900b43b0`，开工工作区干净。
范围只涉及测试支持、OutboxRecoveryTest、CanonicalMemoryRecoveryTest及TestIsolationTest中的Redis收尾；沿用[NF-A01隔离约束](../../phase-a/A01_ISOLATION_FIX.md)和[A04契约](../../phase-a/A04_CONTRACTS.md)。没有修改业务服务、队列协议、租约、正式状态、数据库迁移或前端。

## 已确认问题与修复

修复前独立复现原finally顺序：测试本体抛出指定异常后，缺失私有Redis使Queue::clear再次抛错，覆盖原异常并跳过migrate:fresh；剩余管理员1，探针外层收尾后恢复0。[原始结果](BEFORE_REPRO.json)。该证据补足UI08当时仅有源码推断的链路；[UI08首轮/复测](../NF-UI08/TESTS.md)原件与状态全部保留。

- 新增测试专用RecoveryCleanup：各步骤分别尝试，进程/Redis清理失败不跳过缓存配置恢复和数据库重置。本体失败时重抛同一异常对象，保留类型、堆栈；清理异常保留在failures并向STDERR输出步骤名和异常类别，不输出连接参数/异常消息。本体成功但收尾失败时抛RecoveryCleanupFailure，保留所有原因和首个previous，不伪装成通过。
- 两类恢复测试都登记本轮子进程并按引用在收尾读取最新列表；逐个停止，失败不跳过其他进程。Redis重启、队列清理、重启信号、缓存配置和数据库重置分别列为独立步骤。TestIsolation的Redis键、缓存键、探针队列同样分别尝试，不改变其原有缺Redis时skip策略。
- 新增RecoveryEnvironment：在两个恢复测试setUp中，先只读核验testing、批准数据库配置与actual current_database，再验证default/cache的固定Unix socket、数据库0/1和Redis PING（3秒超时）。缺Redis不自动启动或回退。migrate:fresh显式使用pgsql并在每次重置前核验测试库；数据库收尾不依赖Redis健康检查，避免重现同一问题。
- 有序套件加入RefreshDatabase预热，在迁移前做相同只读门禁，复现“先前已有静态migrated状态”的全量运行条件；故障测试后紧接原OutboxTest和PlanningPipelineTest，没有关闭唯一管理员约束或为后续用例增加掩盖污染的额外清库。

## 验收

| 条件 | 证据与结果 |
| --- | --- |
| 私有Redis缺失，先于迁移和夹具阻断 | 实际执行Outbox恢复用例，预期1 error、0断言，PHPUnit轮次165ms；现有cache表独立标记保留，users=0。标记随后移除。见[MISSING_REDIS_GATE.json](MISSING_REDIS_GATE.json)与对应JUnit；这是预期反例，不是通过用例 |
| Redis收尾异常仍重置PostgreSQL | 注入generation/memory清理异常，实际管理员由1归0；下一Outbox/Planning夹具可创建且只有1管理员；缓存配置恢复 |
| 原异常不被覆盖 | 单元验证相同AssertionFailedError对象和原堆栈；子PHPUnit仍分类failure，诊断仅步骤/类别。纯收尾错误则为error，两个子运行都非零退出，由父测试验证 |
| 子进程完整收尾 | 真实受控PHP子进程在本体失败后停止；mock多进程验证一个stop抛错仍尝试其余进程 |
| 实际队列恢复与后续隔离 | 一次有序套件60例通过、333断言，174945ms；含原有6例真实Redis/Worker恢复，再接Outbox/Planning，无单管理员冲突 |
| 安全与范围 | 错误数据库/Redis配置被拒绝，原夹具保留；仅novel_forge_test与私有port0 Redis。业务源文件、历史UI08/UI15证据及UI17三个格式文件与开工SHA一致 |

详细轮次和失败保留见[TESTS.md](TESTS.md)、[TEST_ROUNDS.json](TEST_ROUNDS.json)。首轮新集成测试4例中3通过、1失败，原因是PHP error_log未进入预期子进程输出；改用固定STDERR后4例/24断言通过，随后有序60例通过。不能删去首轮红记录，也不能把预期失败的子探针当成默认套件失败。

## 边界与遗留

本轮只更新NF-UI16。NF-UI08的audited_with_gaps、NF-UI15和其他已完成任务、UI11–14、UI17及业务任务状态不变；未调用真实模型，不处理中文分词，不修改数据库表或字段。新增代码有中文注释。

本次是测试基础设施整改，没有UI、前端或依赖变更，未运行浏览器/Vite，也没有运行全项目完整PHPUnit。新增/修改PHP的Pint通过；全项目仍为UI17原有三个文件失败，SHA未变。验证仅限现有本机串行配置；不宣称支持多个测试运行共享测试库/Redis或parallel testing。

“保证收尾”指每个步骤都会尝试且异常不被吞掉。若PostgreSQL本身不可用、进程被SIGKILL/机器崩溃或子进程无法停止，不能保证资源已清理；测试会保留/报告清理失败，不能据此宣布环境干净。本轮实际执行的故障注入与恢复均通过上述验收。

可复现命令与预期失败探针说明见[TESTS.md](TESTS.md)。[范围核查](FILE_SCOPE.json)、[最终检查](FINAL_CHECKS.json)、[专用服务收尾](CLEANUP.json)。
