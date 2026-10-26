# NF-UI05 总览与候选提取样板验收

实施：2026-10-26至2026-10-27。状态：implemented_core_verified。沿用 Interface Design、DESIGN.md 和 UI04 组件；本轮只推进 NF-UI05。

## 改造结果

总览以“找到小说，继续创作”为主区，统一创建/小说库/刷新动作、状态与日期筛选、分页和空态。生产指标放在辅助区，仍明确“总览未接入/未知”，不把空值当零或健康。长标题表格固定列宽，日期、状态和入口不再被挤成竖排，手机只在表格区域横向滚动。

提取页依次为“选择章节与证据来源 → 编辑候选事实与关联 → 验证结果与原文定位 → 不可变历史 → 折叠离线流水线”。使用实际正文、同正文旧提取、紧邻前章有效基线、作者上下文、批准设定、人物、事件引用、伏笔计划、路由、预算和报价选择器，不要求日常操作手输数据库ID。

候选事件、依赖、知识、关系和伏笔全部按契约逐字段编辑，事件值保留类型；可选字段显式添加/移除，列表增删排序。每个列表仅展开当前一行，复杂状态复用UI04锁定树与扁平字段映射，默认请求深度/大小限制保持不变。固定对象按契约顺序展示，不随JSONB对象键排列变化；数组顺序原样保留。

正文保持只读、独立阅读行宽及局部滚动。引文与说明采用文本域；问题显示事件键、原文指纹、范围和修正建议，可定位编辑行。读取/复制历史定位到结果或编辑区。修订只复制响应到新提取，历史正文和提取记录不原地覆盖。

普通验证继续调用ExtractionService；离线流水线与其分开，仍调用既有冻结、预算和Outbox链。同一输入采用稳定UI幂等键，重复提交不追加记录/运行。读取正文时保存乐观版本，冲突保留输入；提取回放拒绝非提取运行。服务、迁移、模型、API路由和正式提交协议未改。

Prompt作为第三页对照，复用UI04字段界面，本轮未重写其表单。字段详见 [字段与状态矩阵](FIELD_MATRIX.md)。

## 验证结果

| 范围 | 结果 | 记录 |
| --- | --- | --- |
| UI05专项 | 10项、96断言通过 | [TEST_RESULTS.json](TEST_RESULTS.json) |
| UI04共享字段回归 | 14项、147断言通过 | [UI04_REGRESSION.json](UI04_REGRESSION.json) |
| 提取/恢复/工作台/导航关联 | 33项、448断言通过 | [REGRESSION_RESULTS.json](REGRESSION_RESULTS.json) |
| 收紧运行范围后的流水线复验 | 2项通过，与关联组重复，不重复计数 | [PIPELINE_RECHECK.json](PIPELINE_RECHECK.json) |
| 去重覆盖 | 57项、691断言；不是全库全量测试 | 上述报告 |
| 资源与源码检查 | 构建、Pint、diff检查通过 | [构建](BUILD_RESULT.json)、[检查](CHECK_RESULTS.json) |

以上测试串行运行，均使用PostgreSQL novel_forge_test。命令：

```sh
php artisan test --compact --filter=ExtractionUiTest
php artisan test --compact --filter=ConfigurationUiTest
php artisan test --compact --filter='ExtractionTest|ExtractionPipelineTest|ExtractionRecoveryTest|WorkspaceTest|NovelNavigationTest'
php artisan test --compact --filter=ExtractionPipelineTest
npm run build
```

专项覆盖：不写JSON建立实际事件、Unicode引文码点、稳定幂等、错误引文/定位/同正文重提取、五类关联的类型与顺序往返、未知键/空对象导出及错误导入不覆盖、null字段选择新值及非法输入保护、必填删除拦截、乐观冲突、跨书/非管理员、非提取运行拒绝、长书名/分页/转义和读取失败不冒充空数据。

## 浏览器实际验收

开发站只读检查空态、筛选错误与Prompt未保存编辑；写入使用本机临时testing预览，启动严格核对数据库名，正常Filament登录，文件会话仅存testing目录。没有写开发演示数据或改变测试隔离配置。

- Chrome默认1709×939，总览实际显示13本夹具/隔离样本，包括120字中文书名；选择10本并切到第2页。先后日期颠倒显示错误，不显示成空列表。
- 从不可变提取#36复制实际正文#15，逐字段将引文改错，保存#37并返回INVALID_EVIDENCE；点击定位后修正为原文，保存#38成为consistent有效候选。重复相同输入仍为#38。三个记录均保留，正文未增版。
- 保存时实测加载提示display:block，验证按钮disabled:true。原文未读取/配置不足时相应动作禁用；错误保留输入。若浏览器短等待超时，继续读取最终DOM确认结果，未把超时直接记为应用失败。
- 纯验证结束实测提取记录3条、story_events=0、小说state_version=0；ai_requests=1为夹具预存的离线正文请求，没有由本轮纯验证增加模型请求。不是正式剧情或文学质量验收。
- 三页390×844根滚动宽度均390，侧栏收起后右边界0；总览长表格局部滚动。提取页深色局部检查后恢复系统主题和默认桌面尺寸。
- 临时标签、HTTP服务和私有凭据文件均清理；最后用隔离测试重建清理样本。清理与基线核对见检查报告。

[浏览器记录及截图哈希](BROWSER_EVIDENCE.json)。主要截图：

- 总览：[空态](screenshots/overview-empty.jpg)、[筛选错误](screenshots/overview-error.jpg)、[长数据](screenshots/overview-populated.jpg)、[分页](screenshots/overview-page2.jpg)、[手机](screenshots/overview-mobile.jpg)。
- 提取：[无书空态](screenshots/extraction-empty.jpg)、[事件字段](screenshots/extraction-editor.jpg)、[冲突与来源](screenshots/extraction-conflict.jpg)、[有效候选](screenshots/extraction-success.jpg)、[加载](screenshots/extraction-loading.jpg)、[深色](screenshots/extraction-dark.jpg)、[手机](screenshots/extraction-mobile.jpg)。
- Prompt：[桌面对照](screenshots/prompt-reference.jpg)、[手机对照](screenshots/prompt-mobile.jpg)。

视觉复核确认固定字段和文本域没有原始JSON主编辑入口；长标题挤列问题在截图核对中发现并修复，最终长数据截图重新采集。部分过程截图记录修复前的读取位置，最终桌面/手机表单截图用于布局结论。

## 限制与后续

既有提取服务listing最多50条，本轮明确标为“最近50条”，在此范围搜索并每页10条；相关基线/重提取选项也受该历史范围限制。新增 **NF-UI12（todo）** 处理完整历史服务分页与引用检索，不改E03状态，本轮不实现后端历史扩展。

UI11对象语义限制继续适用：类型树可保留/导出空对象、小数和未知扩展，进入现有不支持的保存表示时明确阻止，不能静默改成列表/整数/文本；不支持的扩展可显式移除。没有扩张既有事件或Schema协议。

本轮仅Chrome、三页桌面/390px及提取局部深色；没有完成Safari/WebKit、200%纯字体、减少动效、全页面全状态或性能SLA验收，不替代UI08/H01/H03。未推进UI06/E08、真实模型或中文分词。

开发中修正了公共状态白名单不含warning、final服务不能直接Mock的测试写法、原因字段需实时同步才能解除禁用、JSONB字段顺序和长标题挤列。失败报告未作为通过证据收录，最终仅保存实际通过的报告。


## 三页对照来源

[旧总览](screenshots/before-overview.jpg) → [本轮总览](screenshots/overview-populated.jpg)；[UI02时期提取页（UI05前仍是原始响应入口）](screenshots/before-extraction.jpg) → [本轮字段编辑](screenshots/extraction-editor.jpg)；[UI04 Prompt样板](screenshots/ui04-prompt-baseline.jpg) → [本轮Prompt对照](screenshots/prompt-reference.jpg)。样本及滚动位置不同，不是同一数据的像素回归；用于核对结构、字段、密度和公共组件一致性。
