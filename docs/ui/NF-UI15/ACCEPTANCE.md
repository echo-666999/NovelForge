# NF-UI15 验收记录

日期：2026-10-27。状态：`implemented_core_verified`，仅覆盖本任务整改与受影响回归。
基线：`5d49029a7da9dafc3f2606e70e1c4bd08e398d90`，开工工作区干净。实装 Laravel 13.38.0 / Filament 5.10.3 / Livewire 4.4.8。沿用 Interface Design 与根目录 DESIGN.md，不更换组件框架。

## 已完成

| NF-UI08 遗留 | 本轮实现 | 验证 |
| --- | --- | --- |
| 预算currency错误污染筛选/价格 | 只登记当前动作实际读取的载荷；显式前缀优先；歧义归摘要；缺失字段归本树容器 | 预算负例仅policy树报错；筛选/price树无错；缺失字段、双树同名、未注册字段和下一动作均有反例 |
| 控件缺少ARIA关联 | nf.control继承Field的id/help/error，只输出原生控件；单选、复选组、类型树、容器和导入区补关联 | SSR验证input/textarea/select及布尔/空值/容器；描述ID存在、消失后移除，保留外部说明 |
| 首错不可定位 | 按事件sourceId限定当前Livewire根，在渲染帧后打开折叠祖先并定位；读筛选独立映射 | Chrome两主题×1440/390各重复3次，12次均聚焦唯一币种错误；Tab/ShiftTab可返回，错误值U保留 |
| 英文辅助文案 | 新增常用验证规则中文消息、字段名及Filament全局搜索中文资源 | 实际中文币种错误；全局搜索指引/结果区/27条结果状态为中文；未声称覆盖所有第三方语言资源 |
| 浅色分页“每页”4.382:1 | 分页辅助文字采用既有pencil token，不增加新色值 | 实际合成背景计算：浅色5.660:1、深色8.267:1，均≥4.5:1 |

高级导入重复键只标本次导入框，保留旧树并清除上次预算通知。修正为USD后字段错误及关联引用清除；相同输入重复保存仅一条预算/命令，十进制`123.4500000001`不丢精度。公开错误映射不改变服务载荷、数据库约束、版本及幂等键逻辑。

## 浏览器与可访问性证据

实际测试站点为`127.0.0.1:8772`，APP_ENV=testing且校验current_database为novel_forge_test；正常登录，无鉴权旁路，Http::preventStrayRequests，未启动队列。Chrome桌面1440×1000/窄屏390×844、明暗主题；截图与DOM采集见[BROWSER.json](BROWSER.json)、[截图索引](SCREENSHOTS.json)。最终截图使用`budget-final-*`；早期一次焦点未就位的采集保留为intermediate，不冒充最终通过。最终12次连续复测均通过，早期瞬时差异的单一原因未被独立证明。

- Chrome原生菜单实见200%页面缩放；CSS视口854×441、DPR4、root字号16px，根scrollWidth=854。还原后1709×883/DPR2；快捷键的两次无效还原尝试保留，最终通过原生菜单还原。该项是页面缩放，不是纯字体。
- Safari独立无痕窗口执行4次`MakeTextBigger`，与`ZoomIn`分开；大字下中文错误可见，首错焦点落在“稳定标识”，ShiftTab返回同字段。正向Tab没有采集到焦点行，不计为Safari键盘全通过。原生“实际大小”恢复后变为disabled，已关闭本轮窗口。见[SAFARI.json](SAFARI.json)、`safari-text-*.png`及两份AX证据。
- 本轮未开启Safari开发者功能，**没有重新读取Safari computed字号或确认精确百分比**。UI08曾测得207.36%是历史证据，不能替代本轮精确数值验收。本轮只认定完成了4步原生文字放大下的本页表单复核。
- ARIA属性/目标和键盘焦点已核对；没有运行VoiceOver朗读验收，不能声称全部WCAG或全站Safari验收通过。390下“每页”文字隐藏但选择器保留名称；4份原生select样本含背景图，保守标为未测量，不计入对比度通过率。

浏览器阶段分两个夹具周期：第一周期27本合成小说及一条预算，[计数快照](BROWSER_EPOCH1_COUNTS.json)中ai_requests/story_events/canonical_commits均0。后续PHPUnit按既有RefreshDatabase重置测试库，再建27本供原生缩放；Safari只提交无效空预算。两周期不能作为同一事务前后计数对比。

## 测试、范围与保留事项

[TESTS.md](TESTS.md)记录各轮原始失败及复测。9个受影响测试类按用例取最新结果，**98例通过，1094断言**；不是一次全项目测试通过。构建通过；新增/修改PHP的Pint通过。全项目Pint仍有原3个既有文件失败，SHA与开工基线一致，NF-UI17保持todo。

服务、模型、控制器、路由、配置、数据库及依赖文件未变，见[FILE_SCOPE.json](FILE_SCOPE.json)。本轮没有迁移，无新增表/字段；新逻辑与组件有中文注释。只更新NF-UI15状态；NF-UI08历史证据与audited_with_gaps状态、UI11–14、UI16/17及其他业务任务状态全部保留。未调用真实模型，未处理中文分词，未实施正式提交/文学验收或H03验收。

保留限制：Safari精确字体百分比与完整键盘/朗读范围仍未在本轮认证；全站及原生select对比度不因本次两项文字测量而整体判绿；全量故障测试隔离和既有Pint基线仍分别属于UI16/UI17。

本轮Chrome已恢复100%与系统主题、关闭自己的标签；Safari已还原字体并关闭自己的无痕窗口，没有修改开发者偏好。[浏览器清理](BROWSER_CLEANUP.json)；进程及临时凭据收尾另见[CLEANUP.json](CLEANUP.json)。
