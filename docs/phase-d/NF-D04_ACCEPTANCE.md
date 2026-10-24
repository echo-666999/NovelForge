# NF-D04 分层记忆、摘要版本与来源有效性

日期：2026-10-24。实施范围仅 NF-D04。未推进 D05 检索/嵌入、D06 上下文或 E07 正式提交消费；没有调用真实模型，没有处理中文分词。

## 实现与边界

- `LayeredMemoryService` 提供事实清单、摘要候选、分块、激活、停用与来源链校验；所有调用按作者、小说及明确候选分支鉴权。
- `LayeredMemoryController` 提供6条同源会话接口；`MemoryLibrary` 提供 `/admin/memory-library` 记忆库，小说创作导航增加入口。
- 新迁移 `2026_10_24_000016_create_layered_memory.php`：9张表、78字段；全部添加实际 PostgreSQL 中文备注，开发库系统目录核验完整。另对既有来源/证据表增加同源、同分支复合唯一约束，用于强外键，不改变既有正文。

当前 `source_documents` 只注册既有不可变产物完整文本，尚无正式正文/提交/基线来源。本轮记忆仅允许 `scope=candidate`，`branch_id` 强绑定同书持久运行，`revision_id` 必须为空。读取正式范围明确返回 `FORMAL_MEMORY_UNAVAILABLE`，绝不回退读取候选。正式 schema 接入与消费按 E07 依赖完成，本轮不制造假正式基线或正式记忆。

语义记忆可以保存带证据的实体、规则、关系与目标摘要，但当前来源仍是同分支产物。世界规则直接强类型来源、正式正文与事件来源要随对应注册表扩展落地，不能用多态数字指针替代外键。

## 分层、版本和事实保留

| kind | 用途 |
| --- | --- |
| working | 最近场景、当前章节的工作摘要 |
| episodic | 章、弧、卷的情节摘要 |
| semantic | 实体规则、关系和长期目标摘要 |
| event | 有原文证据的关键经历 |

`memory_collections` 是分支内主题身份，kind/name固定；每次保存都创建新的 `memory_items.version`。完整摘要、可见范围、章位、重要度、来源/父版本哈希和输入清单冻结在payload中。building→sealed 后父记录不可改删，来源、事实、父链、分块子记录不可增改删。模型和索引不会在保存事务内调用。

事实清单分类为 irreversible、causal、knowledge、open_issue、other。每条绑定同书、同分支、同来源的精确 evidence_span，statement 必须是该引文内的非空片段。事实原文哈希用于长文本去重，避免将长正文直接放进B-tree唯一键；相同事实不能另设较早章位。

摘要压缩或修订会自动继承本主题当前活动版本的全部来源和事实，即使请求省略 parent_ids/fact_ids 也不能绕过。显式父版本的完整来源和事实同样展开保留。新摘要需要逐字保留所有登记事实片段；遗漏任一片段或把有效章位提前到事实之前，会保存 `failed` 候选和具体fact_id问题，但拒绝激活，旧活动版本仍可读取。

这一比较是保守的**登记事实保留检查**，不是语义理解或独立文学评审。换一种说法但未保留原片段也会失败；初始事实清单是否穷尽所有不可逆变化仍需作者复核。报告明确 `semantic_completeness=author_inventory_only`，不将检查通过声称为全文事实完整性证明。

失败重试使用 retry_of_id，必须是同主题的失败版本。重试追加新版本、保留原失败报告和父链，不重写历史。成功候选也不会自动替换旧活动版本，须显式activate；同主题仅最后一条成功激活记录对应版本为active。旧激活版本作为非当前版本保留，列表归pending；不会因为新摘要失败而自动降级旧活动版本。

## 来源有效性与隔离

- 读写均检查小说管理员及归属。候选读取必须明确scope和branch_id；不接受候选查询夹带revision_id。
- memory_sources 对 source_documents 的 `(novel_id,branch_id,source_id)` 具有复合FK；事实再通过 `(novel_id,source_id,evidence_id)` 绑定原文证据。跨小说、跨分支引用不成立。
- 每次激活及读取重新核验 source_documents 正文SHA256、来源产物完整内容哈希和规范化文本；不是只看记忆自身状态。
- 父链保存具体parent_memory_id及哈希，父ID必须小于新版本ID；新版本保存时展开所有来源。递归重验父哈希与停用状态，不能通过多层摘要隐藏过期来源。
- 停用来源追加memory_invalidations；所有依赖它的摘要立即显示stale，不能再激活，也不出现在status=active结果中。停用错误派生记忆同样使其后代失效，不影响同来源但无此父链的独立摘要。
- 运行分支状态canceled视为来源失效。历史摘要和原文仍可由作者查看，不删除失败证据，不自动换用其他分支内容。
- 激活、停用和读取共用小说行锁；读取在事务中形成一致的有效性快照。跨请求仍按各自读取时刻判断，不承诺永远有效的客户端缓存。

可见范围为author_only或characters及同书批准人物列表。压缩不能扩大父版本的角色集合或提前有效章位。此范围只供作者核对，**不是D02的合法知识链证明**；所有返回均标注pov_usable=false、formal_usable=false，不提供可直接用于人物POV的内容接口。E07/D06必须按正式来源和知识路径再次验证。

来源章位当前由作者登记；没有正式章节来源时不猜测真实发生章位。截止章筛选以登记的introduced_chapter为准，不宣称已经验证正式叙述时间。

## 分块、索引与成本

分块使用Unicode码点半开区间，按chunk_size（32–4000，默认1000）拆分规范化摘要，不重叠、不丢字符；各块保存text/hash，数据库再次检查范围、子串和哈希，可以无损拼回原文。中文、罕见字与emoji不按UTF-8字节切断。

content_status 为pending/active/stale/failed派生状态，index_status独立保存为not_requested。D05尚未执行，当前不允许作者勾选为已嵌入；索引失败筛选是明确未接入的空态。不会将“摘要活动”显示成“向量可用”。

当前origin=author_supplied，人工或离线摘要不发供应商请求，不创建假费用、价格版本或Token用量。码点数不是Token数。本任务依赖C04/C05的后续模型摘要流程，仍必须通过已发布Prompt/路由、价格版本、预算预留与对账；本轮未绕过这些机制，也未启用自动摘要模型执行器。

## 数据库映射

| 表 | 核心职责/约束 |
| --- | --- |
| memory_collections | 稳定主题和四层类别，分支绑定generation_runs；仅追加 |
| memory_facts | 事实片段、分类、来源、精确证据、章位、去重哈希；SQL校验statement在quote内 |
| memory_items | 不可变摘要版本、候选范围、报告、父输入、哈希、失败重试、独立index_status；书/分支/章位索引 |
| memory_sources | 完整展开的来源与哈希；source→memory依赖索引 |
| memory_item_facts | 同书同分支事实清单；memory/fact唯一 |
| memory_parents | 父摘要具体版本/哈希及有向无环约束；parent→child索引 |
| memory_chunks | 序号、Unicode码点、文本和哈希；memory/chunk_no唯一 |
| memory_activations | 仅通过校验的sealed候选可激活；追加操作理由与作者 |
| memory_invalidations | source或memory二选一、同分支强FK、不可逆停用理由 |

记忆、事实、激活和停用接入C07审计元数据。摘要全文、事实原文不写入通用审计快照。数据库没有直接写入正式scope或非空revision的路径。

## API与页面

API前缀 `/api/v1/novels/{novel}/memories`：GET列表、GET/{memory}详情；POST/fact、/save、/activate、/invalidate。会话/CSRF及private,no-store策略沿用既有接口。所有写入要求Idempotency-Key、branch_id、expected_version=novels.row_version、reason。幂等先于版本比较；重复同键同内容返回原响应，同键不同内容冲突，竞争只允许一个版本成功；不推进正式state_version。

列表显式page分页，每页50条，返回total/next_page；筛选kind、status、index_status、chapter、character_id。当前实现对单分支候选版本逐条验证再分页，尚未做千章规模性能验收。详情返回完整原文、证据码点、事实、父版本、分块和分支停用记录，供作者比较和审计。

UI22页面包括：

- 小说与持久分支选择；无分支、无来源、无记忆空态；明确正式基线与嵌入未接入。
- 表格展示主题/版本、类别/章位、内容状态、独立索引状态、时间及来源查看动作。
- 事实登记：来源、已有证据、事实分类、精确片段、章位。
- 摘要表单：四层类别、主题、完整摘要、章位、重要度、作者/角色范围、来源/事实/父版本多选、分块码点数。
- 保存并检查、读取原文、比较父摘要、载入为修订/失败重试、显式激活、停用来源或错误派生记忆、刷新版本基线。
- 自动重新提取与嵌入重建明确留待后续任务，没有可执行的虚假成功按钮。页面显示文本均转义。

## 验证与迁移结果

- D04定向：10项全部通过，209断言，17.968秒，见 `NF-D04_TARGET_TEST_RESULTS.json`。
- 全量：281项，280通过、1项中文相关测试按既有设置跳过，2650断言，319.463秒，见 `NF-D04_TEST_RESULTS.json`。
- 覆盖四层保存与激活、Unicode罕见字/emoji分块可逆、内容与索引状态分离、遗漏不可逆/知情/未解事实拒绝替换、自动父事实继承防绕过、失败重试保留旧报告、来源停用与多层派生失效、单独停用错误父摘要、取消分支失效、跨书/跨分支隔离、不同正式基线请求均不回退草稿、可见范围/章位收紧、证据同源、幂等/CAS、SQL不可变与激活门禁、表字段中文备注、接口鉴权、Livewire实际服务动作和XSS转义。
- 独立双进程测试：同键同载荷只产生一份记忆版本；不同键同版本竞争仅一个成功，另一个STALE_STATE。无正式事件。
- Pint检查10个涉及PHP文件通过，`git diff --check`通过；已核对6条API与1条Filament路由。
- 测试仅使用novel_forge_test和专用Redis Unix socket，收尾已关闭测试Redis。测试产物全部离线，无供应商请求或真实模型成本。

开发库迁移退出0；先备份 `.local/backups/nf-d04-before-20261024-083356.dump`，SHA256 `c9d5383403cd07f9e82759b3ae907b385880c8c63be8ed459c891ef093f6f659`，归档目录检查通过，未执行恢复演练。仅应用D04迁移；users 1→1、novels 0→0，providers/model_profiles/ai_requests仍为0；9表78字段备注完整，详见 `NF-D04_MIGRATION_RESULT.json`。

## 尚未验证或开放

未执行真实模型摘要、正式提交消费、向量索引、全文/中文分词、实际浏览器视觉/移动端/键盘验收、千章性能压测或备份恢复演练。测试通过仅证明上述候选范围的来源、版本和保留门禁，不证明正式长期记忆已投产。未产生新的待用户选型事项。
