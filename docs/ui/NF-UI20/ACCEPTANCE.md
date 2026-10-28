# NF-UI20 公共上下文与工具栏对齐验收

日期：2026-10-27；基线：`4cce206`（开始时工作区干净）。仅此次新增整改，不修改NF-UI01～19既有状态。

## 已确认原因与修复

截图对应公共小说切换行。修复前Chrome 1440宽实测：选择器44.398px、按钮40px、链接26.398px；原布局只保证底边一致，中心线不同。选择器继承正文1.65行高，链接没有控件行高度。故事工具栏还可能叠加字段外边距。

仅修改`resources/css/filament/admin/theme.css`：同排单行控件行高1.25、动作链接inline-flex且最小高度40px、限定同排字段边距为0；长选择值CSS省略，完整数据不截断。运行中心复核时发现原生输入受旧规则优先级覆盖，已以限定panel的选择器修正。没有负边距、绝对定位、PHP/Blade/JS/API或业务逻辑变更。

## 实测结果

| 验证 | 结果 |
| --- | --- |
| 1440×1000，空态/短书名/待切长书名 | 三个元素均40px；中心均294.796875px，同一行 |
| 390×844，空态与长书名 | 选择器第一行，按钮/链接第二行中心一致；无根页面横向溢出 |
| 长书名，明/暗主题，1440及390 | 单行省略；含罕见字和emoji的完整可访问名称保留，无数据裁剪 |
| 键盘与切书 | Tab至切换按钮，Shift+Tab返回选择器；通过真实GET从夹具书12切至13 |
| 运行中心工具栏，1440及390 | 选择器、文本/日期输入均40px；桌面首排五控件中心一致，剩余控件自然换行 |
| 章节时间线空态 | 仅验证story-screen内公共上下文；无工具栏夹具，不计入工具栏覆盖 |
| 最终导航/UI功能回归 | 15例、264断言通过（34441ms）；不是全业务测试套件 |
| Vite构建 / 全仓Pint | 通过；构建840ms |

记录：[浏览器测量](BROWSER.json)、[几何断言及清理](CHECKS.json)、[PHPUnit](PHPUNIT.xml)、[测试日志](PHPUNIT.log)、[构建](BUILD.log)、[Pint](PINT.log)。BROWSER.json保留修复前及原生输入优先级修正前的中间测量，最终依据`after_*`、`long_*`、`short_final_*`及`run_toolbar_final_*`记录；中间测量不是当前失败。

## 截图

![桌面最终对齐](screenshots/alignment-detail.jpg)

- [修复前空态](screenshots/before-empty-1440.jpg)、[修复后空态](screenshots/after-empty-1440.jpg)、[窄屏空态](screenshots/after-empty-390.jpg)
- [明色长书名桌面](screenshots/long-light-1440.jpg)、[明色长书名窄屏](screenshots/long-light-390.jpg)
- [暗色桌面](screenshots/long-dark-1440.jpg)、[暗色窄屏](screenshots/long-dark-390.jpg)
- [最终运行中心桌面](screenshots/run-toolbar-1440.jpg)、[最终运行中心窄屏](screenshots/run-toolbar-390.jpg)

## 隔离、边界与清理

浏览器仅用`APP_ENV=testing`、受守卫保护的novel_forge_test及正常登录；两本合成小说只验证展示与切换，不生成正文。临时预览仅绑定127.0.0.1:8774，凭据文件权限0600且未纳入证据。已停止经路径/端口核实的本轮进程、删除临时凭据、关闭本轮标签、恢复viewport和系统主题；最终测试已完成夹具清理。不调用真实模型，不安装中文分词。

本轮只完成Chrome上述布局与交互抽检，不宣称全页面、Safari、原生200%缩放、纯字体放大、读屏或全部WCAG验收。未发现需阻断此对齐修复的新事项；历史遗留与任务状态保持。
