# NF-UI07 生产页面统一整改验收

日期：2026-10-27。状态：implemented_core_verified。沿用已选Interface Design及项目DESIGN；只推进NF-UI07，既有A–J与UI其他任务状态保留。

## 交付

完成13个既有页面：规划候选、候选正文、评审、受限修订、正式提交、分层记忆、正式记忆、检索、上下文、运行、恢复、费用和审计。复用NF-UI06组件、小说身份与局部表格滚动；正常操作使用逐字段表单及实际记录选择，证据、冻结配置、审计差异逐字段只读，高级JSON导入折叠。具体字段、动作与来源见[FIELD_MATRIX.md](FIELD_MATRIX.md)。

七维评分可读取真实正文，唯一引文定位使用Unicode码点；重复或不存在引文明确拒绝。修订保持有序补丁与逐项作者选择，正文保存/比较保留旧版本，正式稿单独只读。价格/预算/对账金额保持十进制文本，未知用量明确null；读取失败与空数据分开。相同输入的当前页面重试复用原命令和载荷，原服务的预算、来源、鉴权、幂等和并发校验继续生效。

没有修改业务服务、API、模型、队列、数据库迁移或依赖。新增代码含中文说明；无新增数据库表/字段，因此无本轮数据库备注迁移。修复过程中核对了实际PostgreSQL列，未以页面推测创建字段或写入假数据。

## 自动验证

全部在PostgreSQL `novel_forge_test`串行执行，Http::preventStrayRequests阻止外部请求。离线fake记录不能当作真实模型调用。

| 组 | 测试 / 断言 | 证据 |
| --- | --- | --- |
| 最终ProductionUiTest | 12 / 171 | [FINAL_TESTS.json](FINAL_TESTS.json)、[FINAL_TESTS.xml](FINAL_TESTS.xml) |
| NovelNavigationTest + 原有test_filament回归 | 16 / 319 | [NAVIGATION_NATIVE_TESTS.json](NAVIGATION_NATIVE_TESTS.json) |
| UI04/UI06/账本及原有领域UI回归 | 34 / 435 | [REGRESSION_TESTS.xml](REGRESSION_TESTS.xml) |
| 最终上下文授权/比较/重组复验 | 1 / 11 | [CONTEXT_FINAL_TESTS.json](CONTEXT_FINAL_TESTS.json)、[CONTEXT_FINAL_TESTS.xml](CONTEXT_FINAL_TESTS.xml) |
| 补充正式正文、恢复与投影复验 | 4 / 127（2项与前组重叠） | [EXTRA_TESTS.json](EXTRA_TESTS.json) |

专项覆盖13页只读渲染无写入、字段错误不得提交旧响应、重复键导入不覆盖、折叠保值、评分及重复保存、策略保存/发布/退役与旧哈希不变、修订范围和首补丁采纳/旧稿保留、规划启动去重、金额精度、跨书/任意路径拒绝、读取失败与重置、emoji唯一引文码点、正式正文和正式摘要分离。最终报告替代早期11项专项报告，不将重跑和重叠断言重复相加。

主要复现命令：

```sh
php -d memory_limit=512M vendor/bin/phpunit tests/Feature/ProductionUiTest.php --log-junit=/tmp/ui07-final.xml
php -d memory_limit=512M vendor/bin/phpunit --filter='NovelNavigationTest|test_filament'
npm run build
```

格式、构建、受保护文件及原任务状态核查见[CHECKS.json](CHECKS.json)。构建成功仍有既有可选fontaine提示；本轮未添加包或改字体配置。

## 浏览器核对

Chrome正常登录本机隔离测试预览，独立Cookie与文件会话，不绕过鉴权。十三页分别采集桌面宽1709与390×844；页面根宽等于视口，所查可见输入无越界。重点页面目视及实际交互复核，不声称每个历史版本或全部状态穷举。完整尺寸、交互记录、截图及SHA256见[BROWSER_EVIDENCE.json](BROWSER_EVIDENCE.json)。

实际逐字段填写七维分数、理由与原句，唯一引文定位为0–12；保存得到评审#5，再次保存仍为#5。该评分只是UI流程夹具，不代表文学成品或人工质量验收。修订任务#2展示first/second两个未选补丁；正式正文#5独立只读；正式派生记忆4层逐字段显示；通过现有服务从真实冻结运行建立上下文清单#1，共13来源条目，并在浏览器核对结构化来源。

浏览器评分与快照操作前后：ai_requests保持3、正式事件保持1、正式提交保持1，均为离线夹具预置；novel_commands由162至163（评分），其余计划/正文基线数量未改变。没有真实供应商请求。此前后模板对照截图仅回退旧Blade视图，仍使用当前类与主题，不能算旧完整堆栈的像素比较。

重点截图：[手机评分字段](screenshots/review-fields-mobile.jpg)、[评分结果与重试](screenshots/review-saved-desktop.jpg)、[正式摘要字段](screenshots/canonical-memory-detail-desktop.jpg)、[上下文来源](screenshots/context-detail-desktop.jpg)、[手机上下文](screenshots/context-detail-mobile.jpg)、[修订差异](screenshots/revision-detail-desktop.jpg)。

## 边界

NF-UI08的跨浏览器、200%纯字体、深色/减少动效及全状态验收仍为todo；未提前关闭NF-H01/H03。UI11的完整JSON对象语义、UI12完整历史检索、UI13主支线正式投影、UI14工作台生产读模型均保持原状态。未执行NF-E08或其他业务任务，不调用真实模型，不处理中文分词。

临时预览服务和专用浏览器标签已关闭，视口恢复，临时序列化夹具及登录凭据文件已删除。浏览器样本之后运行最终专项测试；未另外执行全库清理命令，未触及开发库。
