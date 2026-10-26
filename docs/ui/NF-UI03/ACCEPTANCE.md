# NF-UI03 导航与小说上下文验收

日期：2026-10-26。状态：implemented_core_verified。依赖：NF-UI02。采用项目已选 Interface Design 与 DESIGN.md；仅推进 NF-UI03。

## 实施结果

- 侧栏固定为“创作工作台 → 故事管理 → 生产与质量 → 检索与记忆 → 配置与运维”，逐页显式排序。原置顶的正文提取、评审、修订按该顺序归入生产与质量；没有无组顶层菜单。小说列表标签统一为“小说库”，原 Resource URL 不变。
- 新增 `/admin/story-hub`，作为已有故事页面的汇总入口，不复制业务实体页面。原始要求、蓝图、五层结构、章节场景计划与世界人物仍从既有小说 Resource 页面进入。
- 8 个 Resource 附属页、12 个现有书级 Page 及 StoryHub 共 21 个入口共享小说上下文。展示经授权读取的书名、ID、归档状态、活动正式基线 ID/版本、连续提交章位及状态版本；未初始化与不可读取分开显示。空根基线明确写“尚无正式章节”，不当成已提交章节。
- 本书入口按四个书级分组折叠，当前分组内部默认展开；任务页外层入口默认收起，StoryHub 默认展开。配置与运维仍为全局配置，没有虚构书级基线。同一区域不再重复追加相同链接。
- 小说选择复用 `x-nf.select` 的 Filament 菜单；新增 GET 表单模式（name/value/options），与已有 Livewire 模式兼容。先选目标再“切换小说”，选择期间不改变实际书身份，也不保存表单。切换采用整页白名单跳转，丢弃上一书的章节、正文版本、角色、时间线、阶段、运行、分支、分页及结果状态；不接收任意返回 URL。
- 侧栏的书级入口保持显式小说参数。没有使用跨标签页共享的“当前小说”会话，也不自动选第一本书。无小说给建书空态，有小说但未选给选择提示。
- 兼容仅携带 run/step/version/review 的旧深链接，从授权来源推导同书身份；有显式小说时必须一致。畸形、缺失、越权或跨书来源返回 404，数组参数在类型化属性挂载之前拒绝。上下文检查器限定当前书的阶段列表，读取/比较/重组重新核验阶段归属。
- `/admin/project-status` 继续可访问，从侧栏移至用户菜单“关于与实施状态”。移除已过期的“后续模块尚未实施”断言，改为指向 PRD、任务与验收记录；本页不替代实际运行健康。

## 工程与接口

共用目录与身份读取在 `NovelNavigationService`；独立 Page 使用 `HasNovelNavigation`；Resource 原共用视图转接同一上下文组件。`WorkspaceService::navigation()` 保留原 keys 与六条原入口，baseline 由保留空值接入真实活动基线。

新增只读 `GET /admin/novel-context?page={白名单页面标识}&novel={本人的小说ID}`：访客跳现有 Filament 登录页、非管理员 403、不可访问小说 404、非法页面标识按表单验证处理（JSON 请求 422）；成功只重定向，不创建任务或模型请求。清空小说时，Resource 页返回小说库，独立 Page 返回自身的无书 URL。保留全部原页面路由、权限及业务动作；没有新增数据库表、字段或迁移。

[格式与清理检查](CHECK_RESULTS.json)：变更PHP的Pint检查、git diff --check通过；最终测试库小说/用户/正式基线/模型请求均为0。新增 PHP、Blade 与 CSS 说明使用中文注释。没有修改 A–J 任务状态、业务写入契约、模型开关、队列运行策略或中文分词配置。

## 自动验证

| 检查 | 结果 | 证据 |
| --- | --- | --- |
| 最终核心与既有下拉回归 | 29 项、385 断言通过 | [TEST_RESULTS.json](TEST_RESULTS.json) |
| 受影响页面关联回归 | 13 项、148 断言通过；与上项重叠 BillingSelectTest 4 项/19 断言 | [RELATED_REGRESSION.json](RELATED_REGRESSION.json) |
| 去重覆盖 | 38 项、514 断言；不是全库全量测试 | 上述两份报告与命令 |
| 最终资源构建 | 通过，主题 `assets/theme-CRKbS89l.css` | [BUILD_RESULT.json](BUILD_RESULT.json) |

核心命令：`php artisan test --compact --filter='NovelNavigationTest|UiFoundationTest|WorkspaceTest|BillingSelectTest'`。关联命令包含 BillingSelectTest 及以下 9 个既有方法：

1. ContextAssemblyTest::test_authorized_api_ui_compare_and_reassembly
2. LayeredMemoryTest::test_api_and_filament_use_same_versioned_service_and_escape_text
3. HybridSearchTest::test_memory_projection_and_api_filament_authorization
4. CanonicalCommitTest::test_filament_actions_load_preflight_submit_and_filter_real_commit_history
5. CanonicalCommitTest::test_api_and_filament_preflight_commit_and_formal_state_are_same_service
6. ChapterReviewTest::test_http_ui_authorization_decisions_and_chinese_schema_comments
7. RecoveryTest::test_api_ownership_ui_empty_state_and_livewire_control
8. ForeshadowLedgerTest::test_http_and_filament_share_services_with_auth_empty_state_and_decisions
9. StoryConsistencyTest::test_filament_empty_state_editing_filters_and_historical_versions

新增 NovelNavigationTest 8 项覆盖：五组顺序与无游离菜单、空态/不隐式选书、21 入口身份与无写入、白名单切书清空旧参数、无效/越权/数组输入拒绝、访客/非管理员、真实正式提交后的游标及旧深链接同书约束、书名转义与侧栏上下文。正式游标用既有 CanonicalFixture 的实际离线提交验证到第 1 章，未将模拟评审宣称为文学质量认可。

## 浏览器证据

实际 Chrome，默认桌面 1709×939 与临时 390×844。开发站没有小说：只读检查真实空态、五组及折叠、关于菜单旧 URL，未插入开发演示数据。

两书操作在仅监听 `127.0.0.1:8768` 的临时 testing 预览进行：实际数据库严格校验为 `novel_forge_test`，正常 Filament 登录，Redis/队列/文件仍为测试隔离配置；临时仅将会话改为 testing 存储内的文件以支持跨请求登录，未改项目测试保护或开发配置。样本明确标为“UI03隔离样本甲书/乙书”：甲书仅初始化合法空根，乙书未初始化，没有伪造正式章节。

- 鼠标从甲书切到乙书：Resource 路径、书名、基线说明、侧栏链接均跟随目标书。
- 检索页填入未提交查询、角色与时间线上下文后切书：新页面清空这些值；没有执行检索、注册索引或模型动作。
- 键盘 Enter 打开/选择/确认，选择后焦点返回选择按钮；等待导航稳定后确认目标书。返回与刷新恢复相应 URL 的书身份。清空选择后显示“请选择当前小说”，不沿用此前书或其他标签页。
- StoryHub 默认展开故事入口；任务页辅助入口收起。窄屏有书/无书均实测 `scrollWidth=390`、`innerWidth=390`，选择器宽 310px。未把其他业务页的宽表、正文区全部计入本批响应式验收。
- 新空态在浅/深色检查；深色背景实测 rgb(23,26,33)。恢复原“跟随系统”主题、清除视口覆盖、关闭本任务临时标签页。带书菜单的深色、Safari、200%与纯字体放大等仍属 UI08 全面验收，未宣称通过。
- 临时预览已停止，一次性凭据文件已删除；标准隔离测试重建测试库后复查。现有 Herd/Vite 服务与用户原页面保留。

[浏览器记录](BROWSER_EVIDENCE.json) · [最终书内入口](story-book-final.jpg) · [手机](story-book-mobile.jpg) · [开发站空态](story-empty.jpg) · [深色空态与关于入口](story-empty-dark.jpg) · [切书菜单](book-switch-menu.jpg)。样本截图不是开发库小说或成品验收。

## 调试与既有断言调整

实施中曾出现未完成安装时缺失切书路由、泛用 auth 指向不存在的 login、Blade 动态属性写法不被组件编译器识别，以及测试误把 Filament Collection 当数组等失败；均修正后重跑，上述报告是最终通过结果，没有复制含会话信息的失败堆栈。

关联回归首次 12/13 通过：时间线旧测试假定切换至不存在小说后仍可显示上书编辑表单并等待动作提示。公共上下文现在更早返回 404，故同步该 UI 断言；保留原先有效编辑、历史读取、转义与数据库数量断言，复跑 13/13 通过。UiFoundationTest 只同步原“系统配置”分组到五组标准，原鉴权和无副作用断言仍在。

浏览器曾遇到调试连接超时和导航完成前读到旧页面，未作为通过证据；恢复临时页并读取稳定画面后完成上述核对。构建提示可选 fontaine 未安装；没有为此增加依赖，现有构建成功。

## 仍待后续任务

NF-UI04：Prompt/路由/文风/供应商 JSON 字段化；NF-UI05–07：业务页内部重排、过期说明、ID 选择器与结果可读视图；NF-UI08：全页/全状态、跨浏览器及完整可访问性。总览和工作台旧生产指标、检索等页面正文的旧阶段说明尚未统一，本批仅书级身份条接入真实基线。没有推进 NF-E08、真实模型或中文分词，也没有将本批导航通过等同于上述任务完成。
