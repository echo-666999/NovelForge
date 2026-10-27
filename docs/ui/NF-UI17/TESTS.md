# NF-UI17 对应回归与复现

| 测试类 | 用例 | 验证范围 |
| --- | --- | --- |
| CreateAdminCommandTest（新增） | 5 | 创建唯一管理员、保存哈希、已有管理员拒绝、三组无效参数不写入 |
| RunCenterFormatRegressionTest（新增） | 1 | 访客拒绝、四种JSON读取分支、审计导出、私有无缓存响应、不触发步骤/模型请求 |
| RunCenterTest（既有） | 8 | 来源追踪、权限、证据下载、跨运行隔离、未知费用、审计不可变与导出 |
| RecoveryTest（既有） | 11 | 暂停/取消/恢复、晚到结果、错误分类、重试、幂等与历史保留；只用离线夹具 |
| RecoveryConcurrencyTest（既有格式修复） | 1 | 独立PHP进程竞争恢复预览，单一互斥决定、陈旧冲突及重放 |

单次执行26例全部通过、267断言，原命令：

```sh
bash scripts/start-testing-redis.sh
bash docs/ui/NF-UI08/scripts/preflight.sh
php -d memory_limit=512M vendor/bin/phpunit --filter='^(Tests\\Feature\\(CreateAdminCommandTest|RunCenterFormatRegressionTest|RunCenterTest|RecoveryTest|RecoveryConcurrencyTest))::' --log-junit=/tmp/nf-ui17-regression.xml
vendor/bin/pint --test app/Http/Controllers/RunCenterController.php app/Console/Commands/CreateAdmin.php tests/Feature/RecoveryConcurrencyTest.php tests/Feature/CreateAdminCommandTest.php tests/Feature/RunCenterFormatRegressionTest.php --format=json
vendor/bin/pint --test --format=json
```

最后一条全仓Pint返回1，仅余NF-UI16文档脚本；前一条目标Pint返回0。没有以目标通过冒充全仓通过。新增管理员测试模拟问答，不代表本轮人工TTY隐藏输入或真实浏览器验收；所有账号和密码是测试专用合成值。

格式实际只调用三个指定路径的Pint；新增测试另行格式校验，不运行全仓自动修复。对三文件的token核查仅过滤T_WHITESPACE，保留所有字符串、运算符、注释和token顺序；前后文件SHA及统一token SHA见[TOKEN_EQUIVALENCE.json](TOKEN_EQUIVALENCE.json)。

既有NF-A01门禁验证actual current_database及资源隔离；所有应用测试禁止未伪造HTTP。测试会按既有机制重建/回滚测试库，不得对开发环境执行。结束后停止本轮私有Redis：`redis-cli -s "$PWD/.local/redis-testing.sock" shutdown nosave`。
