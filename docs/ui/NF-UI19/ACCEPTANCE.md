# NF-UI19 文档复现入口状态码与诊断脱敏验收

日期：2026-10-27。状态：`implemented_core_verified`。开工HEAD：`268f6878f566868c51b51b37682ac686afac443f`，工作区干净。只修复文档复现入口及增加对应测试；不修改应用业务逻辑。

## 修复与保持边界

唯一修改的既有代码文件为`docs/ui/NF-UI16/scripts/before-repro.php`：

- 在同一个脚本中为自动加载、应用启动及复现执行增加try/catch(Throwable)边界，捕获后仅向STDERR写固定`NF_REPRO_FAILED`诊断，明确exit(1)。不输出异常对象、getMessage或栈参数，避免错误DB_URL/认证信息经启动异常回显。
- 原有APP_ENV/实际数据库名/私有Redis socket条件原样保留；已知拒绝直接向STDERR写固定`NF_REPRO_REFUSED`文案并exit(1)，不再交由全局异常处理器决定退出状态。
- 合法复现主体、模拟原异常、旧finally中的队列清理，以及外层finally的测试库重置和JSON输出不改。新增注释为中文。没有调整APP_ENV、DB_URL、凭据或配置来“绕过”守卫，也没有改变全局Console Kernel或A01隔离实现。

[CORE_AST.json](CORE_AST.json)逐段验证bootstrap语句顺序、原守卫条件、守卫之后全部复现/清理/输出语句保持原AST；按源码顺序解析名称，忽略节点位置与注释属性，不排序语句/数组。[检查源码](AST_METHOD.md)。原版存档[BEFORE_REPRO.php.txt](BEFORE_REPRO.php.txt)只供审计，不在该存档路径直接运行（根目录依赖原目录层级）。

## 实际验收

| 场景 | 修改前 | 修改后与证据 |
| --- | --- | --- |
| 私有Redis存在 | 拒绝但退出0 | 退出1、STDOUT空、STDERR固定REFUSED；数据库独立cache标记及原用户数保留 |
| 非法DB_URL（路径、查询覆盖、端口） | 早期拒绝退出255，曾回显虚构URL凭据 | 退出1、STDOUT空、固定FAILED；无URL、凭据、项目路径或完整栈；标记保留，未执行迁移 |
| 批准数据库认证失败 | 不以旧结果替代本轮测试 | 只尝试批准的测试连接，退出1，无认证探针凭据回显，标记保留 |
| 非testing | 原守卫条件保留 | 显式固定测试库与array资源的子场景退出1，固定REFUSED，无测试数据变更；不将子进程指向开发库 |
| 配置缓存 | 保持A01早期门禁 | 退出1、固定FAILED，伪配置未执行、未输出其中标记，数据库标记保留 |
| 合法复现且私有Redis缺失 | NF-UI18冻结JSON | 退出0、STDERR空、JSON逐项assertSame冻结结果，外层finally后users=0 |

两个原始缺陷的直接子进程输出保存于[ENTRY_RESULTS.json](ENTRY_RESULTS.json)；[前后对照](BEFORE_AFTER.json)使用NF-UI18历史记录作为前值，并验证其脚本SHA正是本轮开工版本。没有改写旧红记录或重跑旧版本并把结果冒充本轮修复后结果。

合法复现仍刻意展示“原异常被旧finally覆盖、临时管理员1”，随后外层finally清理到0；这些字段是旧缺陷复现的预期JSON，不是新增错误。本轮没有更改NF-UI16实际RecoveryCleanup框架，亦未将此文档探针当成生产业务执行。

## 测试与范围

分两个不重叠阶段共**29例通过、158断言**：私有Redis缺失的专项正例1例/6断言（4.265秒）；私有Redis就绪后的7项入口拒绝/脱敏与21项既有隔离回归，共28例/152断言（3.407秒）。无失败、错误或跳过。不是一次完整项目PHPUnit。见[TEST_RESULTS.json](TEST_RESULTS.json)、[复现命令](TESTS.md)。

PHP语法通过；全仓Pint最终结果见[PINT_FULL.json](PINT_FULL.json)、[FINAL_CHECKS.json](FINAL_CHECKS.json)。没有前端/依赖变化，未运行浏览器或Vite。没有新增数据库表/字段。应用app、bootstrap、config、routes、database及其他业务测试保持开工SHA；只新增两个入口测试文件和本轮docs。

仅更新NF-UI19任务状态，NF-UI16/17/18及所有其他既有任务卡与历史验收保持；不新增其他业务任务、不调用真实模型、不处理中文分词。[范围核查](FILE_SCOPE.json)、[测试服务收尾](CLEANUP.json)、[证据摘要](EVIDENCE_MANIFEST.json)。NF-UI16历史manifest中的脚本哈希代表当时版本，因本轮授权修改而不匹配现行入口；旧manifest不改，本轮存档与新SHA记录演进。

本轮确认的是已测试的CLI启动/拒绝路径；不宣称覆盖内存耗尽、SIGKILL、任意PHP启动扩展输出或全部全局异常通道。复现中的数据库清理仍按原逻辑尝试，没有借本任务改变数据库不可用时的业务恢复规则。
