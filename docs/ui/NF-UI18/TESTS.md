# NF-UI18 验证方法

实际格式命令只指定一个脚本：

```sh
vendor/bin/pint docs/ui/NF-UI16/scripts/before-repro.php --format=json
php -l docs/ui/NF-UI16/scripts/before-repro.php
```

Pint自动产生的短名必须先有导入，首轮运行失败后仅整理同一脚本的use位置，再检查通过。AST使用当前已安装的nikic/php-parser，不安装/更换依赖；NameResolver按原始节点顺序解析名称，之后才过滤use节点与源码位置属性，不提前登记导入、不排序数组/语句。首版反例未识别的检查已作废，最终检查可识别“短名调用先于use”的反例。

实际复现：私有Redis缺失时，分别运行修改前、修改后的同一路径脚本，`APP_ENV=testing php docs/ui/NF-UI16/scripts/before-repro.php`；比较完整JSON相等。这会按脚本原逻辑重建批准的测试库，不能指向开发环境。

拒绝路径验证：在脚本同目录生成O_EXCL专用临时原版副本以保持dirname(__DIR__,4)不变；对原版和新版分别执行“私有Redis存在”和“虚构错误DB_URL”场景。每场景在测试库既有cache表放独立标记，运行后标记仍1、users0，再移除标记；临时原版副本在finally删除。只记录出口状态、固定拒绝文案及虚构标记是否回显，不复制原始栈。

| 场景 | 原版退出码 | 新版退出码 | 标记保留 | 虚构探针凭据回显 |
| --- | --- | --- | --- | --- |
| 私有Redis存在 | 0 | 0 | 是 | 否 |
| 错误数据库URL | 255 | 255 | 是 | 是（前后相同，NF-UI19待办） |

原版回归/拒绝结果见[BEFORE_REPRO.json](BEFORE_REPRO.json)、[GUARDS.json](GUARDS.json)。没有把拒绝文案当成非零退出，也没有将虚构凭据回显误报为已修复。

既有隔离回归：

```sh
bash scripts/start-testing-redis.sh
bash docs/ui/NF-UI08/scripts/preflight.sh
php -d memory_limit=512M vendor/bin/phpunit --filter='TestIsolationTest' --log-junit=/tmp/nf-ui18-isolation.xml
```

21例/78断言通过，无跳过；正反例覆盖批准/非法URL解析、实际测试库、缓存配置、环境冲突、存储符号链接、外部资源覆盖以及真实专用Redis读写/缓存/队列。结果见[TEST_RESULTS.json](TEST_RESULTS.json)。未扩大到完整业务回归、浏览器、Vite或真实模型。

本轮PHP脚本内容定稿后运行`vendor/bin/pint --test --format=json`，不使用排除路径来掩盖剩余项。测试服务结束仅停止本轮port0私有Redis，不清空开发Redis。当前其他未提交文件与完成任务卡均按本轮开工快照保护。
