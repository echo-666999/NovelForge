# NF-D05 核心混合检索、嵌入与索引隔离

日期：2026-10-24。范围：PRD §10/20、A04 检索契约、development_tasks NF-D05。此报告只针对候选来源与离线检索链路；不代表真实语义模型、正式基线或千章性能验收。

## 实现与边界

- 精确文本、受控别名、pg_trgm 和 pgvector 四通道；字符规范化处理大小写、空白和混合标点，无中文分词。短词在已过滤集合内做子串匹配。RRF常数60，以来源版本去重，返回每通道排名和原始分数、来源哈希、证据/事件/记忆块及索引代次。
- 小说、候选分支、活动索引、冻结档案和截止章先过滤；D04检查源正文与产物哈希、来源停用、分支取消及记忆完整依赖链。失效文档在排名前剔除。POV接入D02：仅可用时间线中已获知的事实/隐藏真相；获知证据也必须属于当前有效分支。原始作者文档和摘要不因声明角色可见就取得POV资格。
- POV事件索引的正文必须等于精确证据及事实值，章位与事件一致，标题和别名必须出现于事实原文。防止已知一个事实却带出整篇正文的秘密或额外标题信息。
- 所有通道只在合法候选中排名；本期采用精确向量搜索，完整扫描最多10000个合法候选，不使用ANN，也不通过放宽过滤补满Top20。超过范围显式拒绝；没有宣称达到生产规模性能目标。
- `offline_hash_v1`是通用字符1至3元组哈希编码，不是语义模型，未使用查询标注训练或写入排序规则。入库和查询共用冻结档案的编码器、维度、距离；支持cosine/l2。外部模型档案可绑定C03具体模型版本，但执行器明确关闭，不读取密钥、不发请求、不虚构Token账单。
- 索引生命周期：building→validated→active→retired，退役可重新校验来源并回滚活动。每书每分支至多一个活动代次，锁小说、CAS row_version、幂等键及数据库唯一约束共同保护。更换档案必须构建新代次，不能改旧向量；验证清单封存后禁止补写。切换动作记录审计，正式state_version不变。
- D04索引状态为查询投影，包含not_requested/pending/partial/indexed/stale及分块覆盖数；不改写不可变内容或混淆内容有效性。`indexed`只在活动代次覆盖全部分块时成立。
- SearchEvaluationService对标注查询调用同一个生产检索服务，检索后才读取相关/禁止来源标签计分，存储不可变逐查询报告、语料哈希、映射后查询哈希与索引清单哈希。

本轮的代次校验/手动切换是隔离基础；自动增量追赶、生产模型更换与规模化迁移仍属NF-G02。正式revision表和Canonical来源尚未实施，因此formal请求明确失败，绝不返回候选结果。中文分词仍归后期最低优先级NF-A03。未推进D06等后续业务。

## 数据库

新增5表，表及全部字段均有真实中文COMMENT：

| 表 | 约束及索引 |
| --- | --- |
| embedding_profiles | 同名版本唯一，模型版本外键；编码器/维度/距离/哈希不可变 |
| index_builds | 同书同分支代次唯一、活动部分唯一索引；档案和归属不可变，封存manifest不可重写 |
| search_documents | 正文来源、证据、事件或记忆块强FK；同书同分支组合FK；不可变；B-tree范围/来源索引及gin_trgm_ops GIN |
| memory_embeddings | 档案与代次、代次与文档组合FK；输入哈希、非零向量及真实维度触发器校验；不可变；B-tree范围索引 |
| search_evaluations | 同书索引FK、不可变报告；C07审计 |

vector列采用可变维度类型，逐行通过冻结profile校验。没有把不同维度混入同一个ANN索引；当前根本未建ANN。精确向量及后续HNSW边界遵循[pgvector官方说明](https://github.com/pgvector/pgvector)，模糊召回使用[PostgreSQL 18 pg_trgm](https://www.postgresql.org/docs/18/pgtrgm.html)。

## 接口与后台

会话鉴权、CSRF、private/no-store沿用既有约定：

- GET `/api/v1/novels/{novel}/search-indexes`：档案、代次、最近20个评测摘要。
- POST `/search-indexes/profile`：name、encoder、model_profile_version_id（外部档案必填）、dimensions、distance_metric。
- POST `/search-indexes/build`：branch_id、profile_id。
- POST `/search-indexes/document`：build_id、source_version_id、title、aliases、introduced_chapter；原文evidence_id及可选event_id；或memory_id+chunk_id。
- POST `/search-indexes/validate`、`/activate`：build_id。上述写入都要求Idempotency-Key、expected_version、reason。
- POST `/api/v1/novels/{novel}/search`：scope=candidate、branch_id、build_id、profile_id、query、chapter、audience；POV另须pov_id+context_id；可选limit(1–100)、vector。显式vector=false退化到三个词面通道并返回原因，不伪造向量结果。
- GET `/api/v1/novels/{novel}/search-evaluations/{evaluation}`：按书鉴权读取完整评测。评测写入目前为内部服务，由离线验收调用，不提供任意报告上传入口。

`/admin/search-lab`：小说/分支/档案/代次选择；注册离线档案、追加证据或活动记忆分块、校验封存、切换/回滚；作者/POV检索、章位上限、向量开关与逐通道解释；代次状态/文档数/清单哈希；评测摘要及完整报告入口。包含未选小说、无索引、无评测和过滤后无结果空态，正文使用Blade转义。操作理由和版本刷新显式展示；共享创作导航已接入。

## 验证结果

- 最终全量：288项，287通过、1项既有中文分词测试跳过，2988断言，381.169秒；默认128MB，见 `NF-D05_TEST_RESULTS.json`。
- 隔离修复后定向：100查询评测与既有时间线共14项通过、402断言，73.785秒，见 `NF-D05_TARGET_TEST_RESULTS.json`。独立双进程的索引幂等、切换CAS竞争也已通过全量检查。
- 固定100查询：Recall@20=95%，禁止来源泄露0。精确/别名/错字/短词混合各20条均100%；语义标签20条为75%。未命中Q018/Q028/Q053/Q088/Q098保留，不删除失败标签。
- 最终小语料查询计时：P50=293.196ms、P95=315.184ms、最大328.783ms（nearest-rank）；140个实际来源、其中当前合法候选40个。仅本机合成候选集，未做正式基线、冷缓存/并发或千章规模性能验收。
- 逐查询来源、源哈希、通道贡献、相关/禁止来源映射与清单证据见 `NF-D05_RETRIEVAL_RESULTS.json`；导出省略重复正文/标题，实际数据库报告保留完整结果。A05原始查询与语料未改写，标注只用于结果计分。
- 16个涉及PHP文件Pint检查通过，`git diff --check`通过；核对8条API与1条Filament路由。页面验证为Livewire动作及HTTP鉴权，不冒充真实浏览器交互验收。
- 测试限定novel_forge_test与专用Redis socket，收尾已关闭测试Redis。无真实模型请求；开发库ai_requests保持0。

开发库已备份后仅应用D05迁移，退出0；5表58字段中文备注全部核验。users 1→1、novels 0→0，providers/model_profiles/ai_requests均0，没有导入测试语料。实际pgvector=0.8.1、pg_trgm=1.6。备份为 `.local/backups/nf-d05-before-20261024-092130.dump`，SHA256 `c9e6e1c0659ac5228782517615df834306426e65bc9f45cc811ae49cdb356be2`；归档目录检查通过，未执行恢复演练。详情见 `NF-D05_MIGRATION_RESULT.json`。

## 评测口径与未验证事项

A05原始语料120条、标注100条保持不变；20个正例分别配跨书、未来、私人、旧基线和失效干扰。夹具通过实际离线产物→来源→证据→候选事件→D02知识链建库，补充20条无关菜谱，使合法候选40条，大于Top20。

原语料中的synthetic committed标记仅是设计标签；本轮明确映射为候选分支，oldrev映射另一候选分支，不能据此声称正式revision隔离已验收。formal请求另外验证拒绝。按查询类别记录召回和通道贡献，不把带有“semantic”标签的字符哈希结果称为真实语义质量。

真实模型能力/维度/价格与预算联调、正式来源切换、真实浏览器视觉与键盘验收、千章性能、ANN召回和备份恢复演练均未执行。索引结构验证不等于质量评测；小语料离线Recall达标不能代替真实模型选型验收。

## 验证过程保留

首轮100查询独立测试通过，总Recall@20=95%、泄露0；语义标签20条为75%，其余精确/别名/错字/短词混合四类各20条均100%。初始逐条来源核验的查询P95约5227ms，改为同一事务内批量检查、共用相同哈希规则后重新评测；最终数据以NF-D05_RETRIEVAL_RESULTS.json为准，不调换语料或相关性标签。

第一次完整回归在已通过217项、无断言失败时，后续时间线页面测试耗尽默认128MB。该轮不记为通过，保留NF-D05_FIRST_FULL_FAILURE.json。将大型语料及完整报告HTTP检查放入PHPUnit独立进程、禁止继承全局状态后，语料与时间线定向14项通过、402断言；未提高应用或测试PHP内存限制。最终全量结果另列。

记忆索引覆盖测试曾因夹具原文只有单块而错误预期partial，修正为真实多块摘要；服务以活动代次中去重分块数与总块数比较，未修改判定去迎合错误断言。最终回归同时覆盖POV获知证据失效、源失效、全部四通道与接口/Livewire动作。

最终仅补充测试导出元数据（数据库内既有manifest_hash、corpus_hash、queries_hash与冻结embedding_profile快照），未更改业务逻辑；对应SearchCorpusTest再跑1项通过、209断言、53.741秒，见NF-D05_REPORT_EXPORT_TEST_RESULTS.json。当前逐查询JSON与上述小语料计时来自这次重跑；全量结果来自此前同一业务实现。真实模型与正式基线仍未开放，未产生新的阻塞本轮的用户待决事项。
