# NF-B03 世界实体、人物设定与批准策略：实施与验收

日期：2026-10-23（Asia/Shanghai）。本轮仅执行 NF-B03；保留 B01/B02 既有修改。未开发其他业务模块、调用真实模型或处理中文分词。

## 交付结论

**NF-B03 核心范围已实现并通过自动化验证。** 包括六类世界实体、人物稳定属性、版本化初始关系、别名合并候选、不可变设定版本、人工批准、冻结的受限路人引入策略、引用资格校验、同源API和Filament页面。

候选设定、已批准设定、正式动态状态严格区分。保存候选不覆盖已批准指针；批准授予具体设定版本的引用资格，不生成故事事件、不修改人物存亡/位置/知识、不推进 novel.state_version。`canonical_state=null` 表示尚未建立，不能解释为人物生存、无知识或无关系。PRD AC12/AC13 的完整正文事件门禁仍待 D01/D02/E03/E06 联调，本轮没有将其标成已通过。

## 数据库与 A04 映射

新增单条迁移：`database/migrations/2026_10_23_000003_create_world_definitions.php`，创建八张表。

| 表 | 内容与约束 |
| --- | --- |
| entities | 稳定实体ID、小说、类型、当前名称、current_version_id、approved_version_id、retired、row_version；按小说/类型/ID索引；禁止跨书/变更类型与硬删除 |
| entity_definition_versions | 每实体递增version、原始来源版本及准确引文、完整definition/hash、别名合并来源alias_origin_version_id、原因、操作者、时间；同书实体/来源/合并来源FK；版本号唯一 |
| entity_aliases | 别名原文及trim+Unicode小写的normalized_alias；每设定版本归一化别名唯一；小说/归一化别名B-tree；允许不同实体同名，不自动认定同一人 |
| character_profiles | 人物具体设定版本的profile JSONB；每设定版本一条；角色、身份、外貌、价值观、目标、底线、口吻、能力、限制、阵营 |
| world_rules | 具体设定版本的规则原文和适用范围；每版本至多一条；是待后续验证器解释的规则设定，不是已执行的状态检查结果 |
| entity_initial_relations | 源设定版本、目标具体设定版本、关系类型、描述；同书双向复合FK；版本/目标/类型唯一，目标版本反查索引 |
| entity_introduction_policies | 每书递增策略版本、enabled、章范围、累计接纳上限、minor-v1规则版本、完整constraints快照/hash、原因及作者审计；默认无策略即关闭 |
| entity_approvals | 一次批准对应一个具体设定版本；manual或policy、冻结策略版本引用、原因/操作者/时间；每设定版本至多一条批准记录 |

设定版本在事务内building→sealed；从首次保存起不可变，不只在批准后才保护。别名、人物卡、规则和初始关系只允许在构建该版本时插入，sealed后禁止增补、修改或删除。策略和批准记录禁止UPDATE/DELETE。

当前/批准指针以(novel_id,entity_id,version_id)复合FK限制为本实体版本；当前指针须指向sealed，批准指针须已有批准记录。批准INSERT触发器再次检查同书、当前版本、冲突、关系目标资格和路人策略边界，防止绕过服务直接批准核心实体。应用按小说加锁，数据库唯一约束负责最后防线。

A04具体映射：字典中的approval_status/approved_at通过独立不可变entity_approvals及实体指针表达；别名唯一范围从稳定实体细化为“具体设定版本”，以保留历史；world_rules的约束内容本轮存可查询规则原文/范围，不提前实现D01/E03可执行规则；初始关系使用专表，不能写入未来正式relationships。引入策略/批准表属于B03契约细化。别名trgm检索索引及检索服务仍待D05，不调整中文分词优先级。

## 设定与批准服务

`WorldService`是后台与API的共同入口，服务内校验管理员和小说归属。每次写操作需要Idempotency-Key、小说expected_version和reason；使用小说行锁、幂等事务锁及B01命令记录。相同键同载荷重放原结果，异载荷409；旧窗口返回STALE_STATE。操作、版本和命令结果原子提交，失败回滚。归档书只读；本轮仅允许draft/planning编辑，成功写入进入planning，不进入ready。

实体类型：character/location/organization/item/ability/rule。设定字段包含名称、描述、别名、core/minor、主线影响标记、计划引入章、原始来源版本/准确原句、人物卡、规则/范围、待解决冲突与初始关系。

- 来源使用现有premise_versions，引用必须完整出现在一个原始文本字段中；不能以JSON元数据拼接伪造来源。来源登记是设定来源，不声称是正文事件证据。未来AI生成产物来源另由相应任务接入。
- 人物须有角色类型、身份、声音及能力限制；其他稳定属性可逐步完善。世界规则必须有规则和适用范围。登记的冲突非空时禁止批准；本轮不声称具备自动语义矛盾识别。
- 修改追加新版本，保留旧批准指针。人工只能批准当前未停用、无登记冲突且初始关系目标已批准的候选。重新批准新版本也不删除旧批准证据。
- 初始关系绑定具体版本，不随目标改名或追加设定而漂移；自指关系、跨书引用被拒绝。关系中引用的尚未批准版本会阻止本版本批准。
- `assertReference`按明确版本ID检查同书与批准资格；未批准或停用返回UNAPPROVED_ENTITY。历史读取仍允许查看旧版本和批准证据；该检查仅为后续正式提交模块提供基础，不等于已经实施Canonical Commit。
- 别名合并把来源名称/别名复制为目标的新候选，并保存alias_origin_version_id；不删除来源实体，不修改来源指针，不重绑初始关系或历史证据。同名歧义仍需作者辨别。
- 停用保留所有历史与引用，禁止新引用和后续设定修改；停用不是死亡。没有删除实体接口。直接写current_state/alive/location_id/knowledge/state_version被拒绝。

## 冻结的受限路人策略

无策略、关闭策略或旧策略版本均不能自动接纳。作者保存策略即批准新的不可变策略版本，记录原因；新接纳必须显式引用最新策略ID。策略包含minor-v1及完整约束快照，后续改变规则语义需新schema/策略版本，不能悄悄解释旧记录为另一套权限。

当前固定限制：只能是character、minor、extra；affects_main_plot=false；没有能力、初始关系或世界规则；计划引入章在策略章窗口和小说目标内；此策略版本的累计接纳数未超过作者指定上限（1—1000）。核心实体必须人工批准。接纳记录保存policy_version_id、来源设定版本与操作者，后续即使关闭策略也保留审计。

数量上限明确按策略版本累计，作者新批准策略是新的额度决定；不暗示这是全书跨策略总额度。当前提供可手动触发的确定性接纳命令，没有AI调用、队列或自动生成调度。

“非关键”“无主线影响”等依赖作者结构化分类，不能证明任意自然语言描述确实无隐含关键作用。正文中的新能力/关键人物及终局临时解法仍需E03/终局模块语义与证据校验。本轮默认关闭，未配置或擅自放宽任何小说的策略。

## 接口

根路径：`/api/v1/novels/{novel}/world`。同源会话+CSRF，沿用A04统一成功/错误包与字符串ID。下表同步操作成功200。POST必须带Idempotency-Key、expected_version、reason；未登录401、非管理员403、越权/跨书404、输入错误422、状态/策略/并发冲突409；CSRF可先返回419。

| 方法 / 后缀 | 范围 |
| --- | --- |
| GET / | 实体列表及策略历史；type/status/q/role/faction/unreferenced筛选；cursor分页默认20最大100；q查名称/当前别名 |
| GET /entities/{entity} | 全部设定版本、批准证据、初始关系反向引用及别名合并来源反查 |
| GET /versions/{definition} | 指定设定快照、内容哈希校验、批准记录；不可变历史 |
| GET /versions/{definition}/reference | 检查该版本是否具备新的设定引用资格；不写正式状态 |
| POST /definition | 新建/修订完整候选；entity_id缺省新建；必填type/name/description/criticality/source_version_id/source_quote/introduced_chapter/affects_main_plot；可含aliases/profile/rule/scope/conflict/relations |
| POST /approve | definition_version_id；人工批准当前候选，不允许附带policy_version_id冒充策略批准 |
| POST /accept-minor | definition_version_id及最新policy_version_id；执行冻结的受限规则 |
| POST /policy | enabled/start_chapter/end_chapter/max_acceptances；保存新的作者批准策略版本 |
| POST /merge-aliases | entity_id及from_definition_version_id；复制别名为新候选，保留来源链 |
| POST /retire | entity_id；保留历史并禁止新的引用 |

profile字段见上表，role为primary/supporting/extra。relations为target_definition_version_id、relation_type、description列表。definition为完整更新语义：可选集合省略时成为空集合，不是局部PATCH；后台修订预填现有完整数据。

列表中的引用数明确命名initial_reference_count，canonical_reference_count=null；unreferenced仅指无初始关系引用，不意味着不存在别名来源链或正式正文引用。版本及实体路径拒绝超出bigint范围的ID，游标无效时422。

## Filament 页面

小说列表新增“世界与人物”入口，页面 `/admin/novels/{id}/world`，合并提供UI17/UI18当前B03部分。

| 区域 | 已实现字段、筛选、动作与状态 |
| --- | --- |
| 实体/人物表 | 名称、类型、当前别名、角色、阵营、计划引入章、当前版本/批准指针、初始关系引用数；正式存亡/位置/引用数明确显示未接入 |
| 筛选与分页 | 类型、candidate/approved/conflicting/retired、名称/当前别名、主角/配角/路人、阵营、无初始关系引用；每页20，下一页/返回首页 |
| 候选编辑 | 全部上述设定字段、人物属性、准确来源、初始关系目标版本选择；变更原因必填；类型在已有实体上不可改；新增/修订都追加版本 |
| 批准动作 | 人工批准与按策略接纳分开；提示不会改变正式动态状态；服务器复验冲突、版本与引用，不以按钮可见性代替门禁 |
| 历史与比较 | 选择具体版本、查看哈希/来源/批准方式、人物声音/限制、规则/范围、初始关系完整内容、别名来源与反向引用；选版本比较内容/哈希 |
| 别名/停用 | 合并为候选，不重定向稳定实体；停用明确不是死亡；归档小说隐藏写动作 |
| 策略 | 人工批准新版本，开关/章范围/上限/原因；展示完整策略历史及minor-v1标识；无策略显示关闭 |

UI17正式知识/经历、动态关系、实际出场范围与存亡筛选，UI18正式正文引用跳转及语义规则冲突检测依后续模块接入，不提供伪数据。当前页面包含作者登记的冲突筛选，不能冒称自动语义审查通过。后台实施状态页已同步到B01/B02/B03。

## 实际验证

- 最终完整 `php artisan test`：**89项，88通过、1跳过，443断言**。唯一跳过项为既有中文分词测试；独立测试Redis/队列探针已执行。
- 扩展定向回归（策略快照补全前）：WorldTest+NovelConcurrencyTest共27项通过、159断言。最后补充minor-v1/完整限制快照断言后，以最终完整回归结果为准。
- 正例：候选→人工批准→引用资格；追加候选仍读取旧批准版本；人物声音/限制查询；无冲突规则批准；正确初始关系批准；合法路人按策略接纳；别名合并保留原实体、旧别名、来源版本和引用。
- 反例：未批准版本、过期候选、核心实体自动接纳、特殊能力、主线影响、世界规则、章窗口越界、策略关闭/过期/额度用完、跨书来源/关系/别名/批准、无效原句、旧expected_version、同键异载荷、归档修改、类型变更及动态状态直写。
- PostgreSQL直写验证：不可变设定/人物卡/批准/策略保护、sealed后增补别名拒绝、无批准记录的批准指针拒绝、核心实体绕过服务进行策略批准拒绝、跨书设定父级和初始关系拒绝、实体硬删除拒绝。
- 故障注入：人物卡写入中断时实体、设定版本、命令、小说row_version全部回滚；下一次保存从版本1成功。
- 两个真实PHP进程同键保存只产生1实体；两个不同路人并发竞争1个额度只批准1个，失败方刷新expected_version后仍因POLICY_LIMIT被拒绝。B01/B02并发回归继续通过。
- Filament/Livewire实际落库验证：人物候选、新版本、人工批准、策略保存、世界规则保存；历史读取、筛选、归档写动作隐藏及HTTP页面渲染通过。
- 空测试库已验证B03+B02+B01回退与重新应用。所有数据库测试只在novel_forge_test运行，继续使用现有DB_URL防绕过与存储隔离。
- `npm run build`通过，仍有既有可选fontaine提示，未新增依赖。18个本轮PHP变更文件格式检查通过；diff空白检查通过；11条world相关路由注册核查通过。
- 没有进行人工浏览器视觉/移动端验收，没有真实模型、正文事件位置证据、Canonical Commit或AC12/AC13端到端验收。前十章成品、百章伏笔回收不在本轮范围。

## 开发库与回退

先备份并核验归档目录，再只应用B03单条迁移，退出码0。开发库用户数1→1、小说数0→0；实体和设定版本均0，没有示例人物、规则、默认开启策略或业务数据。未对开发库执行migrate:fresh。

备份：`/Users/webb/Herd/NovelForge/.local/backups/nf-b03-before-20261023-091113.dump`；SHA256：`38a4453c21530030627141a23f997ec965468f659f243a5b5fe377bb5b6f973b`。文件名使用进程UTC时间，本文日期使用上海时间。

备份通过pg_restore --list检查，未进行实际恢复演练。down会删除本轮八张表，只有空测试库回退已验证；存在设定/批准历史时应先保护数据，采用前向修复或单独制定恢复方案，不直接回退删除历史。

## 未关闭事项

- D01/D02/E03/E06：正式状态、事件证据、动态关系/知识/经历及提交时实体门禁联合验收。当前只完成设定侧基础，AC12/AC13整体仍未执行。
- D05：别名检索优化与相关索引；中文分词仍是后期最低优先级。
- 人工浏览器视觉/移动端验收尚未执行。
- 当前B03没有需要新增用户决定的阻塞项；既有模型、预算、验收小说与部署待决事项保持原阶段节点。

本记录不授权继续推进其他任务。
