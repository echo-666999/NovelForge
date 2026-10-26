# NF-UI06 故事规划与设定页面统一验收

日期：2026-10-27。状态：implemented_core_verified。沿用已选 Interface Design 与 DESIGN.md；只推进 NF-UI06，既有 A–J/UI 其他任务状态保留。

## 交付结果

覆盖小说库/创建、创作工作台、原始要求、五层结构、滚动计划、蓝图与终局、世界实体人物、时间线、伏笔账本。统一 Section、Field、选择器、表格局部滚动、加载及错误反馈；固定对象与列表继续使用类型明确的原生字段/Repeater，历史 JSON 改为逐字段只读与比较。字段/动作/边界见 [FIELD_MATRIX.md](FIELD_MATRIX.md)。

五层结构增加按真实主归属展开和定位的目录；冻结节点隐藏原地编辑，提交前检查冻结窗口，正式状态阻止结构原地编辑；并发冻结触发的已知数据库约束转为可读通知，其他数据库异常保留。滚动计划覆盖节点、同章场景及批准 POV 均使用实际记录选择，不要求手填对应 ID。蓝图五页签、人物设定四页签保留原字段路径、确认与版本命令。POV 选择保留本书所有有效已批准历史版本，不因新版批准挤掉旧引用，并排除未批准候选；已用原生场景编辑验证只改标题时旧引用保持。

时间线的事件、依赖、知情途径和关系使用当前版本实际选项。真实浏览器发现选择后折叠区自动收起，已用 `wire:ignore.self` 保留原生 details 自身开合，同时继续更新子字段；修复后桌面和手机选取真实事件均保持展开并正确带入时间等原值。

伏笔计划的正式进度现在展示现有服务返回的活动正式基线证据：隔离样本首次实际埋设为第1章、正式证据1条、尚无强化证据，不再写死为“无/未接入”。主支线正式进度投影仍未接入，保持明确未知，另加 **NF-UI13 / todo**；不改 D03/E06 等已执行任务状态。

## 自动验证

PostgreSQL `novel_forge_test`，串行运行；不使用开发库。去重 **60项测试、765断言通过**；最后视图修复后另有2项106断言追加复验（已计入去重，不重复相加）。

| 组 | 项目 / 断言 | 证据 |
| --- | --- | --- |
| StoryUiTest + NovelNavigationTest | 17 / 301 | [FINAL_TESTS.json](FINAL_TESTS.json)、[FINAL_TESTS.xml](FINAL_TESTS.xml) |
| ConfigurationUiTest + ExtractionUiTest | 24 / 243 | [SHARED_TESTS.json](SHARED_TESTS.json)，从同次 JUnit 明确选取两组全部通过的 testcase |
| WorkspaceTest | 10 / 102 | [WORKSPACE_TESTS.json](WORKSPACE_TESTS.json) |
| 既有故事页原生动作回归 | 9 / 119 | [NATIVE_TESTS.json](NATIVE_TESTS.json)，含建书要求决定、结构编辑快照、蓝图保存批准复制、人物批准策略、时间线修正与并发状态 |
| 最终视图/导航追加复验 | 2 / 106 | [VIEW_FINAL_TESTS.json](VIEW_FINAL_TESTS.json)，覆盖最后折叠区与人物标签变更 |

专项包括选项中文/0/转义/多选保留、八页结构化只读无渲染写入、目录展开定位和历史只读、蓝图复制后旧体/哈希不变、真实离线正式提交后读取证据、建书字段保留、原生保存/校验/冻结及阻止覆盖、场景选择确实执行并增加一条命令。Fixture 的正式提交只用于测试服务投影，不代表真实模型或文学质量验收。

主要复现命令：

```sh
php -d memory_limit=512M vendor/bin/phpunit --filter='StoryUiTest|NovelNavigationTest' --log-junit=/tmp/ui06-final.xml
php -d memory_limit=512M vendor/bin/phpunit --filter='ConfigurationUiTest|ExtractionUiTest' --log-junit=/tmp/ui06-shared.xml
php artisan test --compact --filter=WorkspaceTest
npm run build
```

源码格式、构建、受保护文件与任务状态核查见 [CHECKS.json](CHECKS.json)。新增代码含中文说明，没有数据库新增表/字段，故本轮无数据库备注迁移。

开发中记录并修正：测试提前读取尚未加载的 Action schema、空夹具误当有伏笔、条件 ARIA 属性造成 Blade 语法错误；最终专项/导航已全部复验通过。共享组合运行在默认128MB测试进程上限退出，改用单次进程512MB完成，未改应用配置；24项共享通过及导航失败修复后的17项通过分开记录，不把失败的32项整组算通过。未收录含页面快照的失败原始输出。

## 浏览器证据

Chrome、本机隔离预览、正常邮箱密码登录；独立测试 Cookie 与文件会话，不绕过鉴权。十个页面（小说库、创建、六个规划页、时间线、伏笔）逐页采集桌面/390×844尺寸和截图；蓝图、人物表单页签及时间线编辑另做实际交互核对。桌面默认宽1709，手机根滚动宽390、字段无越界，宽表格使用具名局部滚动区。手机账本实际按方向键横滚，scrollLeft观察到33.5，页面根宽仍390。

浏览器操作前后 novels/commands/requests/events/commits/context/outline/blueprint/plan/freeze 数量完全一致；其中 ai_requests=1、canonical_commits=1 是夹具预置，浏览器没有新增。原始计数、尺寸、截图清单与SHA256见 [BROWSER_EVIDENCE.json](BROWSER_EVIDENCE.json)。

重点截图：[建书焦点边界](screenshots/create-focus-desktop.jpg)、[结构目录](screenshots/structure-tree-desktop.jpg)、[蓝图字段](screenshots/blueprint-fields-desktop.jpg)、[人物字段](screenshots/world-character-desktop.jpg)、[手机人物](screenshots/world-character-mobile.jpg)、[手机时间线选择后](screenshots/timeline-editor-mobile.jpg)、[正式伏笔证据](screenshots/foreshadow-evidence-desktop.jpg)、[手机局部横滚](screenshots/foreshadow-evidence-mobile.jpg)。其他页面尺寸和截图在证据清单中；重点页面已目视复核，不声称每个历史版本和全部交互状态穷举。

## 边界与后续

- PlanningCandidates、正文生产等 NF-UI07 范围未推进；StoryHub 沿用 UI03，没有新增业务服务、API、模型、任务或数据库迁移。NF-E08 未执行。
- 主支线正式投影后续由 NF-UI13 核实来源与接入；工作台生产指标/入口的读模型仍有旧服务缺口，另加 **NF-UI14 / todo**；只修正文案范围，不在UI06偷偷补业务。UI11 JSON对象语义、UI12完整历史检索仍保留原状态和边界。
- 这次是 Chrome 桌面/手机与关键交互验收；Safari/WebKit、200%纯字体、减少动效、全页面深色/长数据/性能SLA仍由 UI08/H01/H03 独立验收，没有因本轮自动关闭。
- 未调用真实模型、未处理中文分词。临时预览、标签与凭据已移除。原样本在追加测试结束后只读核对为0；最终两页补拍重新建立的1套规划样本保留在novel_forge_test，未触及开发库。全库重建清理曾被自动审批拒绝，未执行该动作，也未改用间接清库；具体计数见检查报告。
