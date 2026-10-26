# NF-UI09 文本域重复边框修复

日期：2026-10-26。状态：implemented_core_verified。新增纠正任务，既有任务状态不变。

## 原因与修复

创建小说页的 Filament Textarea 内层没有 class，`.fi-fo-textarea` 和 `.fi-input-wrp` 位于外壳。NF-UI02 的原生控件兼容规则仅按控件 class 排除，误给内层 textarea 增加1px边框、8px上边距和2px焦点outline；外壳自身还有焦点ring，因此出现多条横线。这是UI02选择器范围缺口。

现在原生兼容规则、textarea调整与焦点outline规则均排除 `.fi-input-wrp` 内部控件。Filament自行管理内层无边框与外层焦点ring；没有外壳的原生文本域仍保留兼容样式。没有用负边距遮住横线，也没有修改业务字段、数据库或表单数据。

## 验证

- 实际 Chrome 创建小说页，原始背景持续聚焦：内层border由1px变0px，margin-top由8px变0px，outline-style为none；外壳2px焦点ring保留。
- 同页所有Filament文本域的内层border/margin均为0，未聚焦区域也不再有重复顶线。
- 实际提取页的原生文本域：不在Filament外壳内，1px边框和8px间距保留。只读取页面，没有提交或创建小说。
- 浏览器执行上述样式断言通过，见 [BROWSER_EVIDENCE.json](BROWSER_EVIDENCE.json)。
- npm run build、node --check vite.config.js、git diff --check通过；[UI基础回归7项、60断言通过](TEST_RESULTS.json)。原有测试本身不覆盖CSS绘制，本次问题通过真实浏览器的聚焦断言和截图验证。
- 本轮仅浅色桌面实测；未宣称全浏览器、明暗、窄屏或全部页面通过。

[修复截图](textarea-fixed.jpg)。新增CSS注释为中文。NF-UI02原验收记录保持为历史快照，最新主题产物与源哈希见 [BUILD_RESULT.json](BUILD_RESULT.json)。未修改任何既有任务状态，未推进NF-UI03/04或NF-E08。
