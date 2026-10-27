# NF-UI19 验证命令与测试策略

先在私有Redis不存在时执行专项合法正例；该测试在Support/Probes，不属于默认Unit/Feature套件。它会按原脚本重建批准测试库，只能在独占、串行测试环境运行；若Redis存在则前置失败，**不会停止既有服务或挪动socket来伪造缺失**。

```sh
php -d memory_limit=512M vendor/bin/phpunit tests/Support/Probes/DocumentReproductionSuccessTest.php --log-junit=/tmp/nf-ui19-success.xml
bash scripts/start-testing-redis.sh
bash docs/ui/NF-UI08/scripts/preflight.sh
php -d memory_limit=512M vendor/bin/phpunit --filter='DocumentReproductionEntryTest|TestIsolationTest' --log-junit=/tmp/nf-ui19-regression.xml
php -l docs/ui/NF-UI16/scripts/before-repro.php
vendor/bin/pint --test --format=json
```

默认Feature新增DocumentReproductionEntryTest：Redis存在、三组非法URL（路径/查询覆盖/端口）、批准库认证失败、非testing和配置缓存，共7例。每个拒绝场景用测试库既有cache表的独立UUID标记验证migrate:fresh未执行，用户数不变，最后只移除本用例标记。断言exit=1、stdout空、stderr代码正确且无Stack trace/SQLSTATE/数据库URL/项目路径及虚构凭据。

Redis存在用例如已有批准私有实例则只借用，不停止；如独立运行且缺失，才启动本测试拥有的port0实例，并在finally只停止自己创建的进程。本轮28例阶段使用外部本轮专用实例，未额外声称覆盖该自启动分支。

配置缓存用例只创建本轮唯一命名的测试文件与执行标记，验证A01在执行缓存PHP前拒绝，并在finally删除。非testing用例的子进程显式固定DB_DATABASE=novel_forge_test、DB_URL为空及array/sync资源，只检查拒绝；入口本身不会改写任何环境变量。认证失败用例仅对批准的测试库使用虚构账号，不创建账号，不打印实际凭据。

Support专项正例比较NF-UI18/AFTER_REPRO.json冻结结果，断言退出0、stderr空和最终users0。两阶段JUnit分别保留，[SUCCESS_TESTS.xml](SUCCESS_TESTS.xml)、[REGRESSION_TESTS.xml](REGRESSION_TESTS.xml)，不能将29例说成一次全量业务测试。

核心保持性检查另用已安装的nikic/php-parser按原序解析名称，对原脚本和新try边界内的bootstrap前缀、守卫条件、守卫后全部合法复现语句分别作AST全等比较；仅允许新增入口错误处理。未安装/升级依赖，没有修改全局测试基础类或隔离配置。

本轮所有PHP与docs生成后执行全仓Pint；不排除文档脚本，也不自动格式化无关文件。终止专用实例使用`redis-cli -s "$PWD/.local/redis-testing.sock" shutdown nosave`，不操作开发Redis或开发库。
