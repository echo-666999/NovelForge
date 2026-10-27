# NF-UI08 遗留清单

审计结论：`audited_with_gaps`。表示本轮审计和证据交付完成，尚不能宣布全部UI/可访问性通过。旧任务状态保持；以下新任务均为todo。

| 编号/优先级 | 已确认事实 | 原因/推断界限 | 后续验收 |
| --- | --- | --- | --- |
| NF-UI15 / P0 表单错误与可访问性收口 | 新增预算的currency错误同时映射到无关currency筛选及价格树；6个错误关联控件均无aria-invalid/aria-describedby，虽已有错误id；错误文案和搜索辅助指引仍英文；小说分页“每页”14px在浅色背景实测4.382:1 | `HasProductionChoices::productionErrors()`遍历所有树并保留裸字段错误，字段同名会跨根；`nf/field`生成错误段但具体控件未补关联。部分树所在details可能折叠，不能说6条错误均同时可见；对比度仅认证已测样式 | 只标当前表单；无关筛选/价格无错误；精确ARIA关联、错误焦点、保值/修正；中文辅助文案；普通文字≥4.5:1；浅/深、390、键盘及旧焦点边界回归 |
| NF-UI16 / P1 故障测试清理韧性 | 首轮忘启动专用Redis，3例socket异常；随后22例users_single_admin冲突。OutboxRecovery等finally中Redis清理位于migrate:fresh前，前者异常可跳过后者；启动隔离Redis后相关49例全通过 | 源码支持“Redis异常跳过DB清理导致后续污染”的解释，尚未额外注入Redis异常独立复现清理链路；不是已证明的业务幂等缺陷。此次仅新增只读preflight，不修测试类 | 缺Redis快速阻断；故障清理即使Redis失败仍保证测试库收尾；保留原异常；仅批准测试库与私有socket；故障测试后紧接Outbox/Planning无污染 |
| NF-UI17 / P2 既有格式基线 | 全量Pint失败：RunCenterController.php、CreateAdmin.php、RecoveryConcurrencyTest.php三文件；本轮前后SHA相同 | 全是本轮之前已有格式问题，未因审计顺手改动 | 独立格式提交，仅三文件必要空白格式；Pint通过；相应回归无语义变化 |

## 保留的既有待办

NF-UI11（JSON对象/数组空值语义）、NF-UI12（完整历史分页/检索）、NF-UI13（主支线正式投影）、NF-UI14（工作台读模型）仍按原状态。NF-E08、H01/H03及其他业务任务未推进；本轮没有扩展中文分词或真实模型。

## 范围与未认证项

- Safari完成32页浅色四尺寸、31登录页深色1440及5页深色390；未执行所有Safari深色尺寸组合。
- 已做真实Safari字体≥200%和Chrome页面200%，不把模拟无效的Chrome OS文字缩放计通过，也不声称两个浏览器各自完成所有缩放组合。
- 基线DOM命名/ARIA引用通过不覆盖提交后错误状态；预算负例明确失败。未使用屏幕阅读器做完整任务朗读，没有宣称WCAG AA认证。
- 截图为实际可见窗口，未制作长页滚动拼接；未穷举每个嵌套JSON类型/所有历史页/所有瞬时加载帧。既有字段合同和自动化用例是补充，不能替代未执行的浏览器动作。
- 合成正文、人工评审、offline_hash_v1、测试Canonical Commit不是前10章成品、前5章伏笔百章回收或真实语义模型验收。

复现截图：[预算错误](screenshots/budget-validation-390.jpg)、[读取失败](screenshots/billing-read-error-390.jpg)、[分页对比度](screenshots/novels-pagination-contrast-1440.jpg)。首次测试失败和定向复测分别保留，不能删去红结果只呈现合并绿结果。
