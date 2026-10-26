# NF-UI02 后台主题与公共组件基础验收

日期：2026-10-26。状态：implemented_core_verified。采用用户选择的 Interface Design 与根目录 DESIGN.md。

## 交付与边界

1. 新增 `resources/css/filament/admin/theme.css`，导入实际安装的 Filament 5.10.3 主题，并扫描 app/Filament、业务 Blade 和公共组件。Vite 增加主题入口，AdminPanelProvider 注册 viteTheme。
2. 纸面/正文/书签/校对语义 tokens、明暗配色、中文本机字体栈、40px 控件、轻边界、标题层级、网格和窄屏样式生效。面板使用 LocalFontProvider，不新增远程字体依赖；原 Vite 欢迎页资源与已有字体配置保留。
3. 对现有手写 Page 的原生输入框、选择框、文本域、代码预览和表格增加兼容样式；不将隐藏字段/复选/单选/上传误套成普通文本框，也不改 Filament 浮层或键盘逻辑。
4. 新增六个匿名公共组件：`x-nf.section`（原生Section外壳）、`x-nf.form-grid`（普通2→1列，筛选4→2→1列）、`x-nf.field`（原生字段容器）、`x-nf.input`（原生输入与自动label/help/error关联）、`x-nf.table`（命名可聚焦局部滚动区）、`x-nf.state`（状态白名单、错误/加载/空/成功等语义）。
5. 总览仅接入 Section、Table 和错误状态外壳。查询、字段、筛选、权限和业务动作保持原实现。公共输入和状态由隔离样本与测试验证，不把示例当业务数据。

**没有**修改导航分组、JSON 编辑模型、应用服务、API、业务表或数据库字段；无迁移、无真实模型请求、无中文分词处理。未推进 NF-E08/NF-UI03–08。新增代码有中文注释。既有业务任务与验收状态未改，仅更新 NF-UI02。

## 根因与实际样式证据

修复前，后台仅引用 Filament 默认编译 CSS；业务模板的任意 Tailwind 工具类没有独立主题扫描/加载链。已在真实 Chrome 后台读取修复前输入框样式：`border:0px`、`padding:0px`、透明背景。

接入主题后，在**实际已登录后台**观察：

- `/admin`：输入/选择框 `border:1px`、`padding:8px 12px`、`min-height:40px`；背景为浅色 `rgb(242,244,247)`；页背景 `rgb(248,249,251)`，本机中文字体栈生效。表格区域有独立可访问名称与焦点。
- `/admin/prompt-templates`：原 `grid gap-3 md:grid-cols-4` 实际计算为 grid，1709px视口下四列；390×844下为一列，未发生页面横向溢出。文本域具有完整边界和宽度。**整段JSON输入仍存在，结构化表单归NF-UI04。**
- 深色 Prompt 页：页背景 `rgb(23,26,33)`，控件背景 `rgb(25,29,38)`、文本 `rgb(238,240,245)`，边界仍为1px；390px下无页面横向溢出。
- `/admin/extraction-ledger`：控件1px边界和8/12px内边距；文本域桌面实际1275px宽；两个业务区域白色表面、24px内边距；无页面横向溢出。未点击提取、冻结或模型动作。
- 实际后台先观察到 `/build/assets/theme-*.css`；随后本机已有 Vite 热更新产生 public/hot，页面观察到 `http://127.0.0.1:5173/resources/css/filament/admin/theme.css`。两种模式均见主题效果。未删除 public/hot、未停开发者服务。

样式加载结论来自真实页面计算样式与截图；**没有单独采集后台主题 HTTP 状态码**。浏览器 pageAssets 导出热更新 CSS 的辅助检查失败且耗时异常，不能作为资源下载通过证据，也没有据此更换浏览器传输方式或修改开发服务。此失败保留，和实际样式命中分开记录。

## 公共组件样本

`tests/Fixtures/ui-foundation.blade.php` 为无业务写接口的样本，采用与实际后台相同的已构建主题和公共组件。

`tests/Support/render-ui-foundation.php` 强制要求 `APP_ENV=testing`，通过既有隔离配置核对实际数据库为 `novel_forge_test` 后，只渲染 HTML 到隔离存储。没有生产路由、没有新导航项、没有写入业务数据。复现：

```sh
npm run build
APP_ENV=testing DB_DATABASE=novel_forge_test php tests/Support/render-ui-foundation.php
```

输出位于 `storage/testing/ui02-review/index.html`，可在本机回环地址以仅该目录为根临时查看；本轮临时服务和临时浏览器页已关闭。

浏览器核对了样本默认/错误/只读/禁用、加载/空/失败/成功、枚举/长文本与表格：输入控件高40px，readonly/disabled为真实原生状态，错误有文字和边界而非仅颜色；桌面两列、390px单列，无页面横向溢出。明暗均查看截图。样本的“加载/成功”是界面样本，不是业务任务执行证明。

## 测试与构建

| 项目 | 实际结果 | 范围 |
| --- | --- | --- |
| 首轮新增测试 | 7项：4通过、2失败、1错误；44断言 | 两项构建URL断言受到现有public/hot影响；输入组件中在自定义标签属性写条件指令，导致输入标签未正确编译 |
| 修复 | 测试单独指定不存在的隔离hot文件，明确验证构建产物；输入属性统一通过AttributeBag合并 | 没有删除开发者hot文件，也没有通过放松HTML语义断言掩盖问题 |
| UiFoundationTest 最终 | 7项通过、60断言 | 主题入口/构建引用、鉴权/无业务副作用、label/help/error、只读/禁用、原生容器、转义、未知状态拒绝 |
| 相关回归 | 8项通过、89断言 | Foundation登录/非管理员/访客3项；Workspace空态/书内页面/失败状态3项；AuthoringConfiguration后台API与页面1项；Extraction HTTP/UI/授权/历史1项 |
| 最终前端构建 | npm run build 通过 | 默认Filament基础CSS和业务工具类共同编译；精确文件与大小见BUILD_RESULT.json |
| 代码检查 | Pint、PHP语法、node --check vite.config.js、git diff --check通过 | 仅本轮改动和相关入口 |

两个专项共15项、149断言；没有运行全量测试，不宣称既有全量测试内存问题已解决。没有将组件测试当作真实业务浏览器交互验收。

构建保留已有提示：字体优化缺可选 fontaine 包；构建本身成功。本轮未安装该可选依赖，后台使用本机字体栈。该提示不代表构建失败。

原始通过结果：[TEST_RESULTS.json](TEST_RESULTS.json)、[RELATED_RESULTS.json](RELATED_RESULTS.json)；首轮失败摘要：[INITIAL_RESULTS.json](INITIAL_RESULTS.json)；计算样式与范围：[BROWSER_EVIDENCE.json](BROWSER_EVIDENCE.json)。

## 截图

- [总览修复前](before-overview.jpg) / [总览修复后](after-overview-light.jpg)
- [Prompt展开编辑区](after-prompt-light.jpg) / [深色](after-prompt-dark.jpg) / [390px深色](after-prompt-mobile-dark.jpg)
- [提取页](after-extraction-light.jpg)
- [公共组件浅色](components-light.jpg) / [深色](components-dark.jpg) / [390px深色](components-mobile-dark.jpg)

截图只覆盖说明中的页面与状态；未包含业务内容的假数据注入。独立样本有明确样本标记。浏览器已恢复原有视口和“按照系统主题切换”。

## 剩余边界

- NF-UI03：导航五组、三个游离入口归组及书内上下文；本轮保持现有导航。
- NF-UI04：JSON逐字段、嵌套Schema无损编辑；本轮只补输入外观与宽度。
- NF-UI05–07：逐页表格/字段/操作区重构与所有状态接入；兼容CSS不等于这些任务已完成。
- NF-UI08：全页面长数据、200%缩放/纯字体、减少动效、完整键盘路径、对比度与Safari/WebKit/Chromium独立验收。本轮仅Chrome、实际桌面1709px与390px检查，不声称这些全量指标通过。
- 主题色Token的数学对比度检查仅覆盖指定色对，不等于所有浏览器最终控件状态满足WCAG；结果见BUILD_RESULT.json。

后续按既定顺序进入 NF-UI03；NF-E08仍为原状态，不因本轮改动而被执行或关闭。


后续纠正记录（2026-10-26）：用户发现原生兼容CSS误作用于Filament文本域内层，已通过新增 [NF-UI09](../NF-UI09/ACCEPTANCE.md) 修复。本文构建哈希、测试与截图为原验收时点快照；最新主题证据见该纠正任务。原NF-UI02任务状态不变。
