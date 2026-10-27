# NF-UI08 全页面验收记录

执行日期：2026-10-27；基线HEAD `67d9a3957fa24b302eb2c473f4de9efce024bc6b`。结论 **audited_with_gaps：审计与证据交付完成，存在验收缺口，不是全量通过**。

实际32页已逐页覆盖。Chrome 155.0.8059.41：两主题×1440/1920/1024/390，共256例；Safari 27.0.1：浅色四尺寸128例、登录后全31页深色1440及5页手机深色，共164例；另保留原生窗口基线截图。全部32页分别完成Chrome页面缩放200%和Safari纯字体约207.36%的独立测量。共531张主矩阵/交互原始JPEG，另留6帧画廊布局复核图，尺寸/字节/SHA见清单。

已确认缺口：预算错误跨表单映射及缺少ARIA关联、英文错误/辅助提示；小说分页文字对比度4.382:1；故障测试清理韧性和3个既有Pint格式问题。新增NF-UI15/16/17待办；不回退UI01–07或任何旧业务任务状态。

- [逐页与字段覆盖](PAGE_COVERAGE.md)
- [状态/交互/冻结契约](STATE_COVERAGE.md)
- [测试轮次与原始结果](TESTS.md)：首次全量457例有25错误；环境修正后相关49例全通过；按用例最新结果456通过、1个中文分词跳过。**未声称一次全量通过**。
- [业务契约与测试类映射](CONTRACT_REGRESSION.md)
- [遗留清单与待办验收](RESIDUALS.md)
- [截图总清单](SCREENSHOTS.json)、[截图画廊](GALLERY.html)、[范围与收尾检查](CHECKS.json)

## 执行边界

仅使用 `novel_forge_test`、私有Redis套接字及 `storage/testing`；正常会话登录，无鉴权旁路。HTTP预览门禁阻止未注册请求，运行只用既有fake/offline服务，浏览器未创建新真实请求；不处理中文分词。测试夹具通过既有服务建立，包含一次测试正式提交，不能当文学成品验收。

浏览器新增预算和Prompt仅落测试库；旧运行57的Prompt/风格/路由冻结记录保持一致，限额123.4500000001仍为精确字符串。所跟踪11张表中仅预算政策、计费命令、Prompt版本计数改变，事件、正式提交、正文、评审、上下文和请求计数保持；未将该计数表冒充全库所有审计行统计。

Safari开发者功能经用户授权临时从0改1，结束已核实恢复0、开发菜单消失；主题恢复系统，字体恢复16px，关闭本轮无痕窗口和测试标签，原有Safari标签保留。Chrome恢复系统主题、原生视口、减少动效No emulation，关闭测试标签。隔离账号临时凭据文件和预览进程按收尾记录处理。

## 证据的限度

基线DOM未发现根溢出或基本命名/引用错误，不代表提交后错误关联通过。Safari没有穷举所有深色尺寸/所有缩放组合；没有屏幕阅读器全任务认证。原生字体4次放大实测207.36%，不是声称精确200%；无效Chrome OS文字缩放模拟和采集等待失败均保留且不计通过。具体未认证项见遗留清单。

依据：[UI规划](../PLAN.md)、[PRD](../../PRD-V1.1.md)、[A04](../../phase-a/A04_CONTRACTS.md)、[WCAG2.2](https://www.w3.org/TR/WCAG22/)、[文本放大](https://www.w3.org/WAI/WCAG21/Understanding/resize-text)、[CSS Color4换算](https://www.w3.org/TR/2026/CRD-css-color-4-20261007/#color-conversion-code)、[Safari响应式设计模式](https://developer.apple.com/documentation/safari-developer-tools/responsive-design-mode)、[Safari文本放大](https://support.apple.com/en-ie/guide/safari/ibrw1068/mac)。
