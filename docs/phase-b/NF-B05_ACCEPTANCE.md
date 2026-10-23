# NF-B05 滚动细化与章节场景计划门禁：实施与验收

日期：2026-10-23（Asia/Shanghai）。仅实施 NF-B05 及必要的 B01/B02 接口、页面衔接。新增代码含中文规则注释。没有推进其他业务任务，没有真实模型调用，没有安装或开发中文分词。

## 状态与边界

**NF-B05核心范围已实现并通过自动化验证。** 交付范围包括全书骨架、手工滚动细化、不可变计划版本、要求覆盖、预算校验、检查报告、冻结、场景拆分/合并/排序、API 和 Filament 页面。

规划通过不等于正文成品：所有计划响应 `generation_eligible=false`，小说保持 planning，不写正式 state_version。AC01/AC02 的规划前置条件已提供实现；十章成品与百章伏笔回收文学验收未执行。冻结计划不可直接改写；正式历史变更流程仍属后续模块，当前以明确错误阻止绕过，不显示虚假的流程完成。

## 契约补充与数据库

新增 `2026_10_23_000005_create_rolling_plans.php`：

| 对象 | 约束与用途 |
| --- | --- |
| rolling_plans | novel/version 唯一；同书 outline_version_id、blueprint_version_id 复合外键；每计划新建 B02 outline 快照，outline 唯一关联；窗口、content/hash、作者、原因与时间；building→sealed，禁止覆盖历史 |
| requirement_coverages | 同书要求版本与 plan_nodes 外键；每计划/要求/节点/字段唯一；field、quote、码点 start/end、字段 SHA-256；仅 building 可插入，封存核对明细和快照一致 |
| plan_validations | 只追加的完整报告、依赖指纹、规则版本、report_hash、原因、作者与时间；按计划/id 索引 |
| plan_freezes | 每计划唯一冻结；同书同计划匹配 passed 报告；report_hash、原因、作者与时间；只追加 |
| plan_nodes 索引 | 新增 UNIQUE(novel_id,id)，供覆盖同书复合 FK 使用，不改变旧节点内容 |
| scenes.retired | 合并/拆分退休旧稳定节点；当前工作树排除退休节点，旧快照继续读原节点及原场景序 |

数据库拒绝修改/删除计划和证据、向封存版本补写覆盖、错误来源/偏移/引文/字段哈希、跨版本 plan_node、伪造冻结报告引用。冻结窗口内章/场景及其卷弧祖先禁止直接修改，新增或移入冻结章的场景也被数据库拒绝。应用服务同时保护 B02 老编辑入口。`state_version>0` 时保守返回 HISTORY_CHANGE_REQUIRED；尚未接入真实 canonical_chapters，不能据此宣称已做正式历史修订联调。

蓝图反向关联由新 rolling_plans 聚合承载真实 FK；不更新 B02 已封存 outline_versions。B04 的完整工作树依赖适用于蓝图批准当时；B05 允许章/场景细化，但重验蓝图当前批准身份、输入与要求、卷弧和全书线索、终局目标及实体批准资格。卷弧或主支线改变需新蓝图批准。

## 门禁规则

- 全书 1..target_chapters 需有连续章骨架，未来章可保持 skeleton。首窗覆盖 1..min(10,target)，后续窗口 5–10 章，尾窗不足五章允许。窗口必须从首个未冻结章开始，不跳章或重复冻结交叠窗口。
- 详细窗口每章至少一个 detailed 场景；章/场景目标必填，不能只用“第N章”作为具体章名。
- 场景必填：POV、已批准人物设定版本、叙事人称/模式、故事时间起止、地点、参与者、冲突、情绪起止、必要事件、禁止变化事实、前后衔接。POV 名称须对应已批准且未停用的人物版本。
- 章和场景预算均为正整数 min_words / target_words / max_words，满足 min≤target≤max。每章三项预算分别等于其有效场景三项之和；章节范围须落在小说字数政策内。预算是预期正文码点数，不是 Token 预算或已生成正文字数。
- 同章完全相同的场景目标、必要事件与冲突判为重复填充，阻止冻结。此为明确重复检测，不宣称语义去重或文学质量判断。
- 每项未豁免要求必须被接受，并有蓝图已批准覆盖及同一父子路径上的故事弧→章→场景证据。偏好豁免沿用 B01 作者决定，不允许静默豁免硬约束。覆盖可指向未来骨架，但未来骨架不会因此获得详细窗口资格。
- 覆盖 field 限 objective、summary、details.required_events、details.forbidden_changes、details.foreshadow_tasks；quote 必须等于指定 Unicode 码点区间，字段内容另存 SHA-256。引文真实存在与层级连续并不能自动证明语义充分，作者需审阅。
- validate 保存失败或成功报告；freeze 携带检查 ID 与完整 hash，同事务重新计算，不复用过期成功报告。
- 未冻结候选跟踪完整工作树；已冻结窗口跟踪自身节点、覆盖节点与卷弧，下一窗口正常细化不使前窗失效。输入、要求、批准蓝图等共享依赖变化仍显示 stale；原冻结事实保留。
- 时间区间、参与者、前置依赖与场景衔接当前为明确文本；时间线语义、正式动态状态与上下文检查在 D/E 阶段处理。本轮不伪造伏笔到期账本或上下文预览。

## 拆分、合并与顺序

只操作同书同章未冻结场景。拆分选择一个场景并提供至少两个替代场景；合并选择至少两个相邻场景并提供一个替代场景。三项总预算必须守恒，否则全事务回滚。替代场景使用新稳定 ID；原节点退休，原覆盖不会自动重绑。需新建计划、重新提供覆盖和检查。

重排必须列出本章所有有效场景 ID，先移入空闲序号区再写 1..N，避免顺序交换触发唯一键。编辑章或移动未冻结章/场景继续使用 B02 node 接口及同一后台表单；原父级、范围和唯一序号约束保留。不会删除历史节点。

## API

同源会话 + CSRF；共同前缀 `/api/v1/novels/{novel}/plans`，POST 必须有 Idempotency-Key、expected_version、reason。ID 为十进制字符串；统一 data/request_id/version 包装。无登录401、非管理员403、无权或跨书404、参数/门禁错误422、幂等/旧状态/冻结/旧报告冲突409；CSRF 可先返回419。

| 方法 | 参数与结果 |
| --- | --- |
| GET / | limit 默认20最大100；before 为降序 ID 游标；status=ready/blocked/frozen/stale；实时全局筛选后分页，next_before 翻页 |
| GET /{plan} | 不可变版本、节点、证据、当前派生门禁、原检查及冻结记录 |
| POST /save | blueprint_version_id、start_chapter、end_chapter、coverages；每项 requirement_version_id、type、node_id、field、quote、start_offset、end_offset；始终捕获当前工作树，追加版本，201 |
| POST /validate | rolling_plan_id；保存 plan-check-v1 报告及 report_hash；失败同样留存 |
| POST /freeze | rolling_plan_id、validation_id、report_hash；重验并冻结详细窗口 |
| POST /split、/merge | chapter_id、scene_ids、replacements；替代项含 title、objective、summary、details；预算守恒 |
| POST /reorder | chapter_id、完整有序 scene_ids |

B01 GET intent 与 requirements 返回真实 coverage_status、plan_url、coverage_evidence；freeze_gate 只表示规划门禁。requirements() 的底层原始数据保持不附加派生内容，避免 B04 要求快照哈希发生循环依赖。

## Filament 逐项交付

入口：小说列表“章节场景计划”，`/admin/novels/{id}/plans`。

| 区域 | 字段、筛选与动作 |
| --- | --- |
| 章场景表 | 类型/稳定ID/序号/标题/父级/目标、所有细节、三项预算、骨架或细节成熟度；按层级/父级/成熟度/序号范围/缺POV或预算筛选，25条分页 |
| 编辑节点 | 复用五层结构表单；补充字数上下限、POV版本、叙事模式、时间结束、情绪起止；同一服务校验冻结 |
| 场景变换 | 操作类型、章节、可拖动顺序的场景ID列表、替代场景详细表单、原因；实际调用 split/merge/reorder |
| 保存计划 | 批准蓝图、细化窗口、覆盖证据 Repeater、原因；选择历史计划后可复制证据引用，新保存仍按当前工作树重新校验 |
| 检查/冻结 | 选择计划保存检查，展示阻塞码/具体路径/说明；冻结引用检查ID/hash；不开放生成 |
| 版本历史 | 版本/窗口/原因/时间/ready、blocked、frozen、stale；全局状态筛选和 before 分页；历史详情只读 |
| 原始要求页 | 已覆盖/未覆盖/冲突/豁免/过期筛选与实际计划跳转，不再显示“覆盖未接入” |
| 五层结构页 | 保留卷弧树、全书线索和结构版本比较；更新门禁说明，不把 sealed 显示为已批准 |

ready 是当前实时规则通过；正式检查记录和冻结记录独立显示。历史失败记录不重写为成功。已冻结计划后续依赖变化可显示 stale，原 freeze 仍保留。

## 实测结果

最终完整回归：**130 项，129 通过，1 跳过，653 断言**。跳过项为服务器中文分词，按 CR001/本轮边界未处理。测试在 novel_forge_test 上执行，专用 Unix socket Redis 的隔离探针参与完整回归；未调用真实模型。

| 证据 | 实际验证 |
| --- | --- |
| RollingPlanTest（19项） | 前十章完整场景门禁；预算不平衡、范围/政策错误、缺必填/无效POV、断裂层级覆盖、伪造引文回滚、不可变数据库保护、旧编辑接口/SQL/新增场景冻结保护、过期报告、空泛章名/骨架、拆合排序守恒/旧快照、重复填充、API权限/幂等/旧状态、正式状态fail-closed、Filament实际保存/检查/冻结与HTTP页面 |
| 长篇与来源边界 | 100章骨架+前10章详细计划；第11–15章从骨架变为详细窗口，前窗仍frozen；Unicode emoji码点证据、B01覆盖API与页面链接、跨书引用/伪造冻结、要求变化阻止旧报告 |
| NovelConcurrencyTest | 真实独立进程同键保存只生成一份计划，两个冻结请求仅一个有效；B01–B05空业务schema回滚5步后重建 |
| FoundationTest | B05四表实际存在，generation_runs、chapter_versions、story_events仍不存在；PG18及现有pgvector/pg_trgm验证 |
| Pint / diff | 仅本轮变更的21个PHP文件格式检查通过；git diff --check通过 |
| 前端与路由 | npm run build通过；保留原有fontaine可选字体优化提示；9条计划API/后台路由注册有效 |

最后页面衔接回归曾发现旧B01测试仍断言“计划模块尚未接入”。已改为验证新书的真实空状态“无覆盖证据”，并验证没有伪造覆盖跳转；以上为修复后的完整回归结果。先前的测试失败没有被跳过或改成无条件通过。

开发库 novel_forge 已先备份再仅增量应用 B05 迁移，退出码0。用户数量 1→1，小说数量 0→0；迁移后计划 0、冻结 0，没有向开发库灌入测试小说。

备份：`/Users/webb/Herd/NovelForge/.local/backups/nf-b05-before-20261023-120007.dump`，SHA-256 `ae34b35a433bc33661119f240ad35e7cb520ae7336a9ead12c1cef7ae0fb9b13`。归档目录可读取验证通过，权限0600；**未做真实恢复演练**，不把目录检查描述为恢复验收。未执行开发库 migrate:fresh。

## 待决事项与后续边界

本轮没有需要用户先决定才能使用手工规划的阻塞项。正式历史变更、AI细化、上下文预览、伏笔到期/回收、模型生成与 Token 成本继续由对应后续任务负责。冻结后的内容修订当前明确阻止，不提供未经授权的正式历史改写功能。

覆盖的语义充分性、时间/人物关系的文学一致性须作者审阅及后续评审模块验证。没有浏览器可视化或移动端人工验收证据；自动化后台动作与 HTTP 渲染不替代视觉验收。动态状态筛选逐版本重验依赖，未做大量历史版本的性能基准；大规模优化不能宣称已验收。
