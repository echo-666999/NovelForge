# NF-UI15 自动化验证

| 轮次 | 用例 | 通过 | 断言 | 秒（逐例时间和） |
| --- | --- | --- | --- | --- |
| regression | 96 | 95 | 1057 | 298.944 |
| recheck | 18 | 18 | 195 | 55.45 |
| final | 21 | 21 | 270 | 194.201 |
| edge | 9 | 9 | 103 | 4.04 |
| container | 10 | 10 | 122 | 4.46 |

regression中1个错误为story-select独立渲染没有全局$errors；已增加isset保护，并由recheck复测。final复核通知清理及既有生产契约，edge补整体载荷未知字段不会落到同名筛选，container补缺失容器/类型/布尔/空值ARIA。最新98个唯一用例全部通过、1094断言，见[逐例结果](MERGED_RESULTS.json)。按classname/name取最新，不能称为一次全量通过。

各轮JUnit保留在`*_TESTS.xml`，失败只保留类型与首行，不复制完整夹具/堆栈；逐例轮次见[TEST_ROUNDS.json](TEST_ROUNDS.json)。更早开发调试的两轮7例均有4错误（Blade邻接指令及Livewire保留事件参数component），后改sourceId与独立指令；第三轮7例/85断言通过，不计入正式去重总数。

原始命令（在项目根目录、仅批准的测试库及独立Redis）：

```sh
bash scripts/start-testing-redis.sh
bash docs/ui/NF-UI08/scripts/preflight.sh
php -d memory_limit=512M vendor/bin/phpunit --filter='FormAccessibilityTest|ProductionUiTest|ConfigurationUiTest|StoryUiTest|ExtractionUiTest|UiFoundationTest|BillingSelectTest|BillingTest|AuthoringConfigurationTest' --log-junit=/tmp/nf-ui15-regression.xml
php -d memory_limit=512M vendor/bin/phpunit --filter='FormAccessibilityTest|StoryUiTest' --log-junit=/tmp/nf-ui15-recheck.xml
php -d memory_limit=512M vendor/bin/phpunit --filter='FormAccessibilityTest|ProductionUiTest' --log-junit=/tmp/nf-ui15-final.xml
php -d memory_limit=512M vendor/bin/phpunit tests/Feature/FormAccessibilityTest.php --log-junit=/tmp/nf-ui15-edge.xml
php -d memory_limit=512M vendor/bin/phpunit tests/Feature/FormAccessibilityTest.php --log-junit=/tmp/nf-ui15-container.xml
npm run build
python3 docs/ui/NF-UI15/scripts/contrast_check.py
```

preflight通过后执行；现有TestCase/RefreshDatabase负责迁移清理，DB_URL和实际库名、私有socket、队列及测试存储继续由NF-A01门禁保护。所有HTTP禁止，没有真实模型调用。

新增测试FormAccessibilityTest覆盖：预算正/反例、容器缺项、同名筛选、跨树前缀与歧义、未注册字段、原生控件SSRARIA、导入保值、故事单/复选、类型布尔空值、中文搜索及token对比度；既有8类覆盖幂等、不可变配置、正文/规划/提取来源及UI单层边框。没有扩大为全项目全量、UI16故障或真实模型测试。

[构建日志](BUILD.log)通过689ms；Vite提示可选fontaine未装，未新增依赖。最终修改PHP的Pint为passed，输入见[PINT_INPUTS.json](PINT_INPUTS.json)。[全项目Pint](PINT_FULL.json)仍为既有3文件失败：[文件范围](FILE_SCOPE.json)证明与本轮基线相同，未处理UI17。
