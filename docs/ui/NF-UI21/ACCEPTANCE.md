# NF-UI21 跨页面同排控件整改验收

日期：2026-10-27。保留本轮开始时尚未提交的NF-UI20改动，仅新增NF-UI21，不更改任何旧任务卡或历史证据。

## 发现与实现

31页桌面基线中15页存在控件高度或同行中心差异，详见[原始测量](before.json)和[问题页](affected.json)。包括总览、配置筛选与模型声明的44.398px选择器对40px输入、故事筛选复选项4px边距、行内说明26.398px而按钮40px、工作台禁用原生按钮26.398px。供应商主机输入原100%宽，标签/输入/移除分成三行；上下文检查器的独立阶段选择也独占一行。

- `theme.css`：公共单行行高1.25；选择值CSS省略但数据不裁剪；同行文字/链接最小40px且居中；统一混合动作组12px间距，字段网格保留16px；仅移除行内字段/复选项的额外边距；独立原生筛选可伸缩。
- `provider-form.blade.php`：主机三列行，移除按钮复用Filament，行间及添加动作12px；name、x-model、增删表达式、最少一行和最多20行条件保持。
- `workspace.blade.php`：六个原禁用动作复用Filament；disabled和原说明关联保留，没有启用生产功能。
- 未修改PHP应用代码、数据库、接口、权限、队列、业务规则或模型配置。

## 验证结果

[页面矩阵](PAGES.md)列出31页在1440×1000和390×844下的当前状态；每轮239个公共行元素。修复后单行控件40px，同一视觉行中心误差小于0.5px，无根页面横向溢出。表格继续在其授权容器内滚动。最终结果以final-1440/final-390和host_final记录为准；after文件保留了微调前的中间测量。

[展开/交互记录](details.json)：Schema属性输入与动作桌面同排、窄屏自然换行；主机新增后可移除、剩一行再次禁用；工作台复选筛选仍可切换；无效属性名被原规则拒绝，配置键和输入保留，页面仍显示0个配置版本；明暗主题抽检。Escape关闭和焦点返回以settled记录为准，紧邻事件的即时状态不是最终焦点。

### 最终收口

- [最终桌面](final-1440.json)/[最终窄屏](final-390.json)：31页、每轮239个公共行元素；主机12px行间距的最后微调已纳入最终复测。原生小说列表搜索/分页可见外壳40px；原生创建页单行输入40px，多行文本保持原高，隐藏外壳0px不计入通过控件。
- 首轮指定7个测试类：70例中69通过，877断言、311808ms。[失败摘要](FIRST_REGRESSION.json)保留唯一失败，不把首轮写成全绿。原因是在同一测试进程期间再次构建CSS，Vite静态清单缓存仍引用旧文件，而测试直接读取新清单；`UiFoundationTest.php:34`校验失败。只归档诊断元数据及原始文件SHA，不将含临时CSRF等完整HTML的失败日志入库。
- 固定构建后[UI基础复测](RECHECK.log)：7/7、60断言、2835ms；最终浏览器取证及夹具清理后[再测](FINAL.log)：7/7、60断言、2764ms。它们覆盖首轮唯一失败项，不将不同轮次相加伪装成单轮70/70。
- [构建](BUILD.log)通过（788ms）；[全仓Pint](PINT.log)通过；`git diff --check`通过。浏览器[控制台记录](console.json)未捕获error；不代表所有网络、交互分支均无错误。
- [几何核对脚本](scripts/check-layout.py)验证保存的最终DOM测量及对应间距、键盘、禁用与错误保值记录；不替代浏览器访问。执行`python3 docs/ui/NF-UI21/scripts/check-layout.py`。
- [文件边界](SCOPE.json)记录原有文件哈希和旧任务卡前缀检查；[浏览器清理](browser-cleanup.json)记录。最终预览进程/本轮凭据已删除，恢复尺寸和系统主题并关闭本轮标签；测试库users/novels/ai_requests/prompt_versions均为0。

无新增必须阻断此布局修复的事项。

## 截图

![总览混合选择与日期输入](screenshots/overview-alignment-detail.jpg)

![主机输入与移除同排](screenshots/host-row-final-detail.jpg)

- [供应商主机桌面](screenshots/providers-hosts-1440.jpg)、[窄屏](screenshots/providers-host-390.jpg)
- [属性操作桌面](screenshots/prompt-properties-1440.jpg)、[窄屏](screenshots/prompt-properties-390.jpg)
- [暗色桌面](screenshots/providers-dark-1440.jpg)、[暗色窄屏](screenshots/providers-dark-390.jpg)
- [错误状态保值](screenshots/prompt-error-390.jpg)、[工作台筛选](screenshots/workspace-filter-1440.jpg)、[上下文工具栏](screenshots/context-toolbar-1440.jpg)

截图中间遇到抽屉动画未完成和裁剪框坐标不当；不采用不正确的裁剪图，相关窄屏截图已在确认抽屉无覆盖后重拍。

## 覆盖边界

本轮是Chrome当前合成小说/空态及若干展开分支的跨页布局检查，不是每个有数据分支、历史详情、弹窗或原生Filament内部控件的逐项全量验收。没有新增Safari、原生页面200%缩放、纯文字放大或读屏验证，不宣称全部WCAG通过。既有未完成业务及历史UI遗留保持原任务状态。

只使用受既有守卫保护的novel_forge_test和正常登录的临时测试管理员；Http阻止游离请求，无真实模型请求，未安装中文分词。预览仅绑定127.0.0.1:8775，清理及最终测试库状态已核实。
