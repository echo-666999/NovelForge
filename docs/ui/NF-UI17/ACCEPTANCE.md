# NF-UI17 指定三文件格式整改验收

日期：2026-10-27。状态：`implemented_core_verified`，仅表示指定三文件及对应回归通过；**全仓Pint尚未通过**，唯一剩余项另列NF-UI18。
开工提交：`018feedacd0a15a741344ae15777d3a0536539e5`；工作区干净。三个文件的开工SHA与NF-UI16保留的格式基线一致，未使用旧结论代替本轮检查。

## 已完成

| 指定文件 | 必要调整 | 验证 |
| --- | --- | --- |
| RunCenterController.php | header参数逗号后的空格 | 既有运行中心回归；补核list/detail/audit/health JSON外壳及audit-export分支 |
| CreateAdmin.php | 校验失败return语句缩进 | 新增有效创建、密码哈希、已有管理员拒绝、无效邮箱/空名称/短密码拒绝，失败不写用户 |
| RecoveryConcurrencyTest.php | migrate:fresh参数逗号后的空格 | 原独立进程暂停/取消竞争、单一决定、陈旧预览及幂等重放用例通过 |

仅Pint对三个文件作必要空白修复。没有改match分支、校验规则、业务服务、异常处理、进程生命周期或迁移调用语义；没有顺手将NF-UI16清理框架接入该并发测试。补充的两个测试文件有中文注释，不修改生产逻辑。

使用PHP自身`token_get_all(..., TOKEN_PARSE)`比较开工备份与当前文件，只剔除T_WHITESPACE；其余token的类型、文本、顺序完全相等，连注释和字符串都保留。三文件分别332/203/427个非空白token，[哈希与结果](TOKEN_EQUIVALENCE.json)。这是格式不改逻辑的词法证据，不冒充全量行为证明。

## 验证结果

- 指定三文件及两个新增回归文件的Pint：通过。[PINT_TARGETED.json](PINT_TARGETED.json)
- 一次对应回归：**26例通过、267断言、21.537秒**，无skip/failure/error。[TEST_RESULTS.json](TEST_RESULTS.json)、[JUnit](REGRESSION_TESTS.xml)。这是5类定向测试，不是全项目完整PHPUnit。
- 测试仅用批准的PostgreSQL novel_forge_test、项目私有Unix socket/port0 Redis；先运行只读preflight。实际模型HTTP保持禁止，阶段/供应商采用既有离线夹具。
- `git diff --check`通过；其余已跟踪文件的范围核查见[FILE_SCOPE.json](FILE_SCOPE.json)，其他已完成任务状态及旧验收产物保持。

没有前端、数据库结构或依赖变更，未运行浏览器或Vite；没有新增表/字段。未调用真实模型，未处理中文分词。

## 全仓Pint与新增待办

开工全仓检查实际为**4处**，除指定三处外，还包含`docs/ui/NF-UI16/scripts/before-repro.php`。[原始检查](PINT_BEFORE.json)。该PHP文档脚本是在上一轮Pint检查之后生成，因此上一轮交付时未捕获其新增格式问题；这是上轮最终检查的遗漏。本轮保留原历史证据，补充当前事实，不将旧记录直接改写成通过或重新打开NF-UI16。

修复三文件后，全仓只剩该脚本：[PINT_FULL.json](PINT_FULL.json)。按用户限定的三文件范围及“影响已完成任务时新增后续任务”的既定要求，新增**NF-UI18 文档复现脚本格式与全仓Pint收口**，状态todo。本轮不修改该脚本、不排除它来掩盖失败，也不更改Pint配置。不能宣称全项目格式已全部通过。

[复现命令与覆盖](TESTS.md)、[测试服务收尾](CLEANUP.json)、[产物摘要](EVIDENCE_MANIFEST.json)。
