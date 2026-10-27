# NF-UI16 验证结果与复现

| 轮次 | 结果 | 断言 | 用途 |
| --- | --- | --- | --- |
| 修复前真实缺Redis复现 | 原异常被覆盖；管理员残留1，外层收尾0 | 不适用 | 证明旧finally链路 |
| 新门禁实际缺Redis | 1个预期error、users0、独立标记保留 | 0 | 165ms轮次，先于迁移/夹具阻断 |
| 纯单元 | 6通过 | 18 | 顺序、原对象/堆栈、全部异常、诊断失败、逐进程停止 |
| 首轮新集成 | 4例3通过1失败 | 21 | 子PHPUnit未捕获error_log诊断；保留失败 |
| 修正STDERR后的新集成 | 4通过 | 24 | 实际PG、真实受控子进程、后续夹具及分类 |
| 最终一次有序套件 | **60通过，无skip/error/failure** | **333** | 174.945秒；不是全项目完整回归 |

原始验证命令（项目根目录，均只用于测试环境）：

```sh
# 私有Redis尚未启动时，分别执行修复前探针与门禁反例；探针会重建测试库。
APP_ENV=testing php docs/ui/NF-UI16/scripts/before-repro.php
# 启动本轮port0实例，另一个终端执行后续命令。
bash scripts/start-testing-redis.sh
bash docs/ui/NF-UI08/scripts/preflight.sh
php vendor/bin/phpunit tests/Unit/RecoveryCleanupTest.php --log-junit=/tmp/nf-ui16-unit.xml
php -d memory_limit=512M vendor/bin/phpunit tests/Feature/RecoveryCleanupTest.php --log-junit=/tmp/nf-ui16-feature-recheck.xml
php -d memory_limit=512M vendor/bin/phpunit -c docs/ui/NF-UI16/ORDERED_SUITE.xml --log-junit=/tmp/nf-ui16-ordered.xml
# 同一有序套件的便捷入口，自动先运行只读preflight：
bash docs/ui/NF-UI16/scripts/run.sh --log-junit=/tmp/nf-ui16-ordered.xml
```

本轮实际运行了上述直接PHPUnit命令；便捷入口仅做等价封装，另作shell语法检查。套件顺序：RecoveryCleanup单元→A01单元/集成→RefreshDatabase预热→新增故障集成→CanonicalMemoryRecovery→OutboxRecovery→Outbox→PlanningPipeline。预热与恢复测试都在迁移/夹具前检查私有Redis，默认PHPUnit的普通非Redis测试仍沿用原策略。

`tests/Support/Probes/RecoveryFailureProbeTest.php`专门由父测试以独立PHPUnit调用，预期一项failure、一项error，且无真实数据库/队列操作；它不在默认Unit/Feature套件，也不直接列入有序套件。父测试核对非零退出、JUnit分类、原始标记与STDERR，并删掉本轮唯一命名的临时JUnit文件，不能将这两个预期红结果当作正常测试失败。RecoveryWarmupTest只用于有序套件，预热后不留下管理员。

各轮JUnit已按用例保留，失败文本仅首行、无完整堆栈：[TEST_ROUNDS.json](TEST_ROUNDS.json)、[最终逐例结果](ORDERED_RESULTS.json)、[原始文件摘要](EVIDENCE_MANIFEST.json)。历史UI08红结果不改写。

格式验证输入见[PINT_INPUTS.json](PINT_INPUTS.json)，[修改文件Pint](PINT_CHANGED.json)通过；[全项目Pint](PINT_FULL.json)失败仍为RunCenterController/CreateAdmin/RecoveryConcurrency三个旧文件，未推进UI17。

所有应用测试继续由A01守卫验证实际PostgreSQL、固定私有Redis、测试存储/日志、sync/array默认资源，并禁止非伪造HTTP。真实Redis重启用例会重启本轮测试实例，因此结束时需停止当前私有socket的进程，不能只追踪最初启动PID。停止命令：`redis-cli -s "$PWD/.local/redis-testing.sock" shutdown nosave`。本轮未清空开发Redis或操作开发库。
