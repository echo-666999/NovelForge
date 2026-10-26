# NF-UI10 下拉菜单与连续焦点描边

日期：2026-10-26。状态：implemented_core_verified。基于用户截图新增纠正任务；既有任务状态不改。

## 原因与范围

费用页原生select的弹出列表由浏览器/操作系统绘制，不能单靠页面CSS保证统一。原生箭头也没有业务侧的右侧留白。input出现两圈，是原边框与offset=2px的outline之间留下空隙；与NF-UI09的Filament文本域内外叠线是两个不同场景。

新增 `x-nf.select`，基于现有 Filament Dropdown/list/item 组合单选菜单，复用浮层定位、键盘导航、Escape和焦点返回。费用页的费用状态、用途、请求状态三个筛选接入，显示中文标签与选中勾选，原始status/category/requestStatus的值、Livewire更新钩子、页码复位、URL与导出参数不变。选择后显式关闭并返回按钮焦点。没有新增JS依赖、状态副本或筛选API。

主题中原生select及Filament选择器的箭头位置统一向内14px、文字预留44px；新菜单按钮右内边距14px，实测图标距外边缘15px（含1px边框）。输入与菜单触发器共用焦点规范：边框变强调色，紧贴1px同色box-shadow，outline为none，不再有两圈之间的间隙。保留Filament外壳对内部控件的管理，并排除隐藏、复选、单选、文件控件的普通焦点覆盖。

`x-nf.select`适用于当前少量固定选项的单选筛选，不冒充支持动态搜索或多选。此次自定义弹层仅替换截图费用页的三个菜单；其他原生select获得箭头间距与焦点修正，其操作系统弹出列表没有宣称全站替换。后续页面可按原UI任务采用公共组件，不顺带推进业务或JSON字段重构。

## 验证

- 实际Chrome费用页：菜单圆角、选中勾选/高亮、中文标签正常；选择已预留后URL为status=reserved，导出链接含相同值；选择全部可清空筛选。
- 方向键展开并定位选项，Down+Enter选择后菜单关闭，焦点回billing-status；Escape关闭且焦点保留。未提交费用表单或调用模型。
- input聚焦时outline=none、offset=0，border与1px阴影均为rgb(67,56,202)，连续描边不留双圈空隙。
- 390×844菜单展开无页面横向溢出；箭头内距实测如上。临时视口和临时检查页已恢复/关闭，用户原页面未跳转。
- 新增BillingSelectTest四项：筛选/分页/导出范围、选中语义与更新命令、转义/禁用、非法属性路径拒绝。联合原UiFoundationTest共11项79断言通过。
- 原BillingTest费用页面/API/权限/筛选与数据库备注回归1项94断言通过。合计12项173断言；仅隔离测试库及既有Fake供应商，没有真实模型请求。
- npm run build、Pint、node --check vite.config.js、git diff --check通过。无数据库迁移，新代码注释为中文。

本轮浏览器验证为Chrome浅色桌面与390px；不宣称Safari、全站全部菜单、暗色弹层或全部可访问性验收完成。测试通过与真实浏览器证明分别保留：[专项](TEST_RESULTS.json)、[既有费用回归](BILLING_REGRESSION.json)、[浏览器计算值](BROWSER_EVIDENCE.json)、[构建](BUILD_RESULT.json)。

[下拉效果](select-menu.jpg) · [输入框聚焦](input-focus.jpg) · [手机菜单](mobile-menu.jpg)。

未改既有业务服务、数据库、真实模型开关或中文分词，未推进NF-E08、NF-UI03/04。历史NF-UI02/09验收保持原时点记录，最新样式以本次构建哈希为准。
