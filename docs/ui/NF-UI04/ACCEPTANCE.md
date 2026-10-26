# NF-UI04 配置界面验收

日期：2026-10-26。状态：implemented_core_verified（现有 C03/C04 支持的契约范围）。本轮只实施 NF-UI04，沿用已选择的 Interface Design、Filament 和 DESIGN.md。

## 实施结果

- Prompt、路由、文风分别使用原生 Filament Schema 表单。保存、离线回归、发布、退役分别执行；生命周期操作使用 Filament 确认弹窗，可取消。发布改变新运行默认引用，不改写历史正文或旧运行快照。
- 变量/输出 Schema 使用属性名、类型、必填、数组项与长度限制编辑器；样例、评分维度和分数使用递归类型字段。列表可增删排序，空文本、false、0、null、列表顺序分别处理。不以完整 JSON 作为普通编辑入口。
- 复制从真实来源读取，身份只读，乐观版本在打开时冻结。服务端仍采用锁定身份，篡改客户端身份不能另建配置。过期保存保留编辑内容；重复保存不会追加第二次版本。
- Prompt 变量只插入既有 `{{json:name}}` 字面占位符。路由使用实际 Prompt/文风/模型能力版本选择器；备用模型有序、逐项批准并填写理由。能力声明始终标为未验证，不推断为模型实测。
- 文风授权样例为逐条文本，评分阈值及回归样例逐字段编辑；原生 Repeater 的 UUID 行键在保存/导出时转换为有序数据，服务错误映射回真实行控件。
- 供应商使用普通 POST，主机白名单逐行编辑；模型能力采用独立容量、枚举和说明字段，未知容量留空。失败仅回填白名单内非敏感字段并回到对应表单；同源合法筛选保留，外部 Referer 不影响跳转。密钥不进入 Livewire、旧输入和历史比较。
- Prompt/路由/文风详情、比较逐字段显示类型、友好名称与辅助 JSON Pointer，区分缺失/空值/空文本和列表顺序。供应商/模型历史仅投影安全字段、按需读取最近100版；密钥只显示是否配置，不读取密钥内容或内部凭据标识。
- 递归节点按需展开；供应商、模型每类只渲染当前展开的一份编辑表单，避免隐藏控件堆积。复杂状态保留服务端锁定树，客户端使用两层扁平字段映射，不提高 Livewire 默认嵌套深度10或1MB请求上限。

字段范围及转换规则见 [字段契约](FIELD_CONTRACTS.md)。没有新增数据库表/字段，没有更改迁移、C03/C04应用服务、任务队列、冻结协议、预算或真实调用门禁。

## 验证结果

| 验证 | 结果 | 证据 |
| --- | --- | --- |
| UI04专项 | 14项、147断言通过 | [报告](TEST_RESULTS.json) |
| C03/C04、公共菜单及UI03导航关联 | 30项、430断言通过 | [报告](REGRESSION_RESULTS.json) |
| 去重合计 | 44项、577断言；不是全库全量测试 | 两组无重叠方法 |
| Pint、构建、差异检查 | 通过 | [检查](CHECK_RESULTS.json)、[构建](BUILD_RESULT.json) |
| Chrome实际交互 | 普通字段保存、离线回归、确认发布、只读比较、原生POST错误、窄屏和深色局部通过 | [浏览器记录](BROWSER_EVIDENCE.json) |

命令：`php artisan test --compact --filter=ConfigurationUiTest`；关联命令为 `php artisan test --compact --filter='AuthoringConfigurationTest|test_filters_and_native_form_rotate_without_secret_in_livewire_state|test_api_page_and_form_never_echo_or_flash_secret|BillingSelectTest|NovelNavigationTest'`。最终两组串行运行，均使用 PostgreSQL 测试库；测试禁止未预期 HTTP 请求，新增测试包含零模型请求断言。

## 实际浏览器证据

开发站只读检查；写入交互在本机 `127.0.0.1:8769` 的 testing 临时服务进行，路由启动严格核对 `current_database() = novel_forge_test`。会话临时改为 testing 目录内文件以支持普通登录；没有改项目测试隔离规则或开发环境配置。数据明确标为 UI04 隔离样本，不是正式小说或模型能力验证。

1. 复制已发布 Prompt v1，普通字段保存 v3，再经标准确认弹窗执行确定性离线回归与发布；原 v1正文保留。比较 v1/v3 显示正文变化。取消确认与过期保存另外有自动测试。
2. 文风通过原生授权样例行保存新版本，旧版本仍可读；供应商留空密钥，仅修改超时和理由追加版本，再比较20秒与21秒差异。
3. 模型通过实际供应商选择器创建未验证声明，空容量保存为null。容量100/输出101的失败返回到 `#model-new`，保留非敏感输入，密码框均为空；未插入无效模型。
4. 390×844下，Prompt Schema、长正文、路由与文风表单、供应商历史页面均核对根滚动宽度390；表格仅在本地容器滚动。首次截图捕获到侧栏收起过渡，已丢弃并重新获取侧栏右边界为0的稳定截图。深色背景实测 `rgb(23,26,33)`，随后恢复系统主题和桌面尺寸。
5. 临时标签页及HTTP服务已关闭，临时私有凭据文件已删除。最终测试重建清除隔离样本；清理检查见检查报告。

截图均为真实离线配置界面：

- [Prompt编辑](screenshots/prompt-editor.jpg)、[语义Schema](screenshots/prompt-schema.jpg)、[手机Schema](screenshots/prompt-mobile.jpg)、[长正文手机](screenshots/prompt-long-mobile.jpg)、[深色Schema](screenshots/prompt-dark.jpg)。
- [路由编辑](screenshots/route-editor.jpg)、[手机路由](screenshots/route-mobile.jpg)、[文风编辑](screenshots/style-editor.jpg)、[手机文风](screenshots/style-mobile.jpg)。
- [模型字段](screenshots/model-fields.jpg)、[原生POST失败回填](screenshots/model-validation.jpg)、[发布确认](screenshots/publish-confirm.jpg)、[版本差异](screenshots/version-compare.jpg)、[供应商历史](screenshots/provider-history.jpg)、[手机历史](screenshots/provider-mobile.jpg)。版本差异截图采集在友好名称补充前，其后最终供应商历史截图已显示友好名称；类型/差异语义没有变化。

## 真实限制与后续任务

现有服务只支持严格 Schema 子集，不支持 enum、oneOf、$ref、类型联合等。导入保留这些字段并明确阻止保存，不删字段伪装成功。未知扩展显示为逐字段只读，可导出；现有服务拒绝的扩展不能保存为新版本。

另确认现有服务以关联数组解码和重新计算内容哈希：空对象及连续数字键对象不能可靠往返。UI保留对象/列表区分并允许导出，在进入服务前明确阻止危险转换。小数按既有规范不可保存，不擅自转换为整数或文本。这些不等于完整 JSON Schema 或所有 JSON 数值字面量支持；新增独立 **NF-UI11** 处理既有对象语义缺口，未改 C04 完成状态，本轮不实施。

浏览器仅 Chrome，局部明暗及390px检查；未完成 Safari/WebKit、200%纯字体、减少动效、全部页面/全部状态和文学质量验收，不替代 NF-UI08/H01/H03。没有真实模型、中文分词或业务下一阶段。

## 失败与修正记录

开发过程中修复过 Blade 块相邻、字段错误路径、隐藏递归DOM内存及 Livewire 深层绑定问题。后续补测的断言又修正了原生弹窗分片的断言方式、供应商更新ID和实际同源地址。Mac锁屏曾阻挡原生确认框，用户解锁取消后完成实际交互，并改用标准Filament弹窗。

一次关联测试与新增专项重叠，测试库重建使3项报缺表/列；这是本轮测试调度错误，已串行重跑两组，仅最终串行通过报告作为验收。失败原始HTML可能包含请求细节，不收录到项目文档；不把这些失败隐藏成从未发生，也不将测试故障推断为生产数据库缺表。
