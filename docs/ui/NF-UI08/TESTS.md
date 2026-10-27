# NF-UI08 自动化验证结果

| 执行轮次 | 结果 | 说明 |
| --- | --- | --- |
| 首次全量PHPUnit | 457例：430通过、25错误、2跳过；4950断言；约43分32秒；退出2 | 未先启动专用Redis是执行失误。3个Redis socket错误；22个后续单管理员唯一约束错误。原结果保留，SQL/测试账号字段和完整堆栈已脱敏 |
| 隔离Redis就绪后的定向复测 | 49通过，290断言；约152.551秒；退出0 | CanonicalMemoryRecoveryTest、OutboxRecoveryTest、OutboxTest、PlanningPipelineTest、Unit/Feature TestIsolationTest；都属于原457例 |
| 按用例取最新结果汇总 | 457个唯一用例：456通过、1跳过，5137断言 | 是两轮汇总，**不是一次全量命令通过**。唯一跳过为后置中文分词，真实Redis隔离用例在复测已通过 |
| npm run build | 通过，873ms | 未改依赖，未修改业务代码 |
| vendor/bin/pint --test | 失败，3个既有文件 | SHA与本轮基线相同，保留NF-UI17待办 |
| 隔离preflight | 通过 | 检查私有socket PONG与现有testing启动门禁，不启动真实模型或队列 |

完整原命令：`php -d memory_limit=512M vendor/bin/phpunit --log-junit=/tmp/nf-ui08-suite.xml`（512M也供既有大检索语料测试使用）。定向原命令见 `RECHECK_TESTS.json`。执行前在项目目录运行 `bash scripts/start-testing-redis.sh`，再运行 `bash docs/ui/NF-UI08/scripts/preflight.sh`；测试会使用现有测试库清理/迁移逻辑，不能指向开发库。审计结束已停止本轮私有Redis；重跑需重新启动。

[首轮JSON](INITIAL_TESTS.json)、[首轮JUnit](INITIAL_TESTS.xml)、[复测JSON](RECHECK_TESTS.json)、[复测JUnit](RECHECK_TESTS.xml)、[逐例两轮汇总](MERGED_RESULTS.json)、[Pint](PINT.json)、[格式基线](FORMAT_BASELINE.json)。未对无关业务扩大测试或改写旧失败历史。

[契约维度与实际测试类映射](CONTRACT_REGRESSION.md)。
