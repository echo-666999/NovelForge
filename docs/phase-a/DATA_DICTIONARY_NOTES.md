# 数据字典读取约定

每行描述拟建逻辑实体，不是现有表。除关联表明确使用复合主键的未来决定外，各表统一id bigint主键、created_at timestamptz；可变聚合另有updated_at和row_version，不可变记录不做原地内容更新。所有小说范围表增加novel_id bigint NOT NULL及UNIQUE(novel_id,id)，书内引用使用复合FK。全局provider/model/prompt/style/price配置不加虚假novel_id；运行冻结引用其版本。

CSV的key_fields_and_types列为业务必需关键字段，加上本约定构成设计字典；未列全部审计展示字段。JSONB内内容在所属实现任务补输出Schema，不允许只因JSON可存就绕过结构验证。枚举值与A04状态表保持一致。

source_documents是本设计补充的强类型来源注册表：chapter/scene正文版本、正式event、rule版本、artifact、blueprint中恰有一个非空FK，kind与字段匹配；hash指该源冻结内容。evidence_spans/memory_items/search_documents的source_version_id统一指注册表主键，避免多态数字指针没有数据库引用约束。旧PRD中source_version_id的逻辑来源语义不变；API如同时返回chapter_version_id，必须与注册表解析结果一致。

新增辅助实体（chapter_scene_versions、entity_definition_versions、embedding_profiles、model_profile_versions、budget_policies、canonical_chapters、consumer_receipts、idempotency_requests、source_documents）用于落实PRD已经要求的版本、提交、来源和预算规则，不扩大产品业务范围。关系/伏笔涉及实体列表在实施时使用明确关联表，不能用未校验JSON ID绕过同书约束。

price/budget/provider/model/route/style/prompt等与小说范围的界限应按实际聚合范围决定；任何允许跨书共享的配置不允许带入另一书正文或事实。候选基线的state版本和活动小说全局state_version分离，详见A04。

## NF-B02 实际结构映射（2026-10-23）

data_dictionary.csv保留完整目标设计，不作为所有表已落地的证明。B02实施：卷根父级使用novel_id；弧/章/场景用parent_id；plan_nodes稳定引用拆为四个具体外键并检查类型；outline_versions增加building/sealed结构快照状态，不是批准状态。blueprint_version_id待B04/B05真实版本表及门禁建立后迁移加入；Thread仅完成B02关联字段。完整实际表/索引和延期依赖见../phase-b/NF-B02_ACCEPTANCE.md。

## NF-B03 实际结构映射（2026-10-23）

entities批准资格由current/approved_version_id及独立entity_approvals表达，替代在不可变内容中原地修改approved_at；entity_aliases按具体设定版本唯一，保留历史。world_rules存版本化规则原文与scope；可执行状态验证仍在D01/E03。character_profiles按版本不可变；entity_initial_relations绑定目标具体版本，不能代替正式relationships。新增entity_introduction_policies固化minor-v1完整限制/范围/数量/hash，默认关闭；审批审计单独保存。别名合并来源以同书alias_origin_version_id复合FK保存。完整实际表和延期边界见../phase-b/NF-B03_ACCEPTANCE.md；原CSV仍是全系统目标设计，非全部已落地证明。

## NF-B04 实际结构映射（2026-10-23）

新增blueprint_versions、ending_contracts、blueprint_thread_versions、blueprint_entity_refs、blueprint_requirement_coverages、blueprint_validations、blueprint_approvals；requirement_versions补(novel_id,id)唯一索引。蓝图sealed和批准分离；批准及检查为追加审计。终局结构存于不可变JSON内容，范围/结局门禁由共用服务验证。主支线引用B02稳定Thread并保存身份快照；实体引用B03具体版本。蓝图覆盖按要求版本/字段/准确原文保存，不提前实现B05的场景覆盖。完整字段、约束与延期事项见../phase-b/NF-B04_ACCEPTANCE.md；原CSV仍为全系统目标设计。

### NF-B05 实际数据库补充

新增rolling_plans、requirement_coverages、plan_validations、plan_freezes四表，plan_nodes(novel_id,id)唯一索引，scenes.retired。B02 outline_versions保持不变，由rolling_plans承载蓝图和新结构快照的双向聚合关联；覆盖指向该快照内plan_nodes，同书复合FK、码点偏移/quote/字段hash。封存与子记录不可变，冻结窗口及祖先数据库写保护。旧场景退休但不删除，完整说明见../phase-b/NF-B05_ACCEPTANCE.md；字典其他待建表仍是设计，不因B05交付视为已存在。

## NF-C01 实际数据库补充（2026-10-23）

generation_runs/steps/artifacts 已落地，新增 generation_dependencies 保存同运行产物引用；scope 位于冻结 DAG 的节点 key，实际尝试使用 scope_key。检查点指向本步骤产物；新尝试的 resume_artifact_id 指向同运行旧产物。内容暂为私有 DB JSONB，storage_ref=db:UUID。命令幂等复用 novel_commands，不重复建设 idempotency_requests。租约/心跳/fencing、parent_run、预算/供应商请求等 CSV 目标字段不代表本轮已实现；分别随 C02/C03/C05/C06 的真实需求加入。全部实际字段/索引/约束见 ../phase-c/NF-C01_ACCEPTANCE.md；CSV 保持完整目标设计含义。

## NF-C02 实际数据库补充（2026-10-23）

新增 outbox_events、consumer_receipts、generation_book_slots，generation_steps 加 fencing_token/lease_owner/lease_until/heartbeat_at。租约时间以 PostgreSQL clock_timestamp() 判定；意图字段与回执不可变，书槽按同书复合FK约束。字典中的其他目标字段（真实请求、预算、完整人工控制等）仍未落地；完整字段/索引与实际恢复测试见 ../phase-c/NF-C02_ACCEPTANCE.md。

## NF-C03 实际数据库补充（2026-10-24）

新增providers/provider_secrets/provider_versions、model_profiles/model_profile_versions、ai_requests/ai_request_evidence、provider_commands八表，每表及每字段均有中文COMMENT。provider_versions.secret_id为同供应商加密密钥版本引用，前端只返回has_key。model能力保留声明与来源，unverified不能通过配置升级。ai_requests实际包含C02令牌与同书阶段复合FK，原始证据分表加密脱敏；route_version_id/price_version_id与预算账本尚待C04/C05，未填虚构外键。字典仍为全系统目标设计；本轮字段/索引/约束实测见 ../phase-c/NF-C03_ACCEPTANCE.md。

## NF-C04 实际结构（2026-10-24）

目标 CSV 仍描述最终设计。当前新增 prompt_templates/model_routes/style_profiles（id,key,stage,language,default_version_id,created_at）和对应 prompt_versions/route_versions/style_versions（id,parent_id,version,content,content_hash,status,actor_id,reason,created_at）。具体配置字段存于严格校验的 content JSONB；route_versions 另有 prompt_version_id/style_version_id 外键且须与 JSON 引用一致。

每个身份 key 唯一，版本 parent_id+version 唯一，默认指针采用 parent_id+id 复合外键；版本状态索引支持后台筛选。configuration_regressions 保存 kind/version_id/content_hash/passed/report/actor_id/created_at，由触发器校验所属版本摘要，索引 kind+version_id+id；configuration_commands 对 actor_id+command_key 唯一，保存请求摘要与首次响应。

generation_runs 新增 configuration_route_version_id 外键；ai_requests 新增 route_version_id/prompt_version_id/style_version_id 外键及 configuration_hash。旧测试记录允许空引用；新绑定运行由网关验证完整快照及尝试选择。上述新表和新字段均有中文 PostgreSQL COMMENT，版本/回归/命令历史不可覆盖删除。价格版本未在 C04 创建，快照显式 null/unknown。

## NF-C05 实际结构（2026-10-24）

price_versions保存model_version_id/version唯一、币种、五类单价JSONB、缓存/推理计量方式、生效时间与凭证；budget_policies保存key/version唯一、币种、五级limits、warning/stop百分比、UTC时区及原因。新表均有管理员和创建时间。

budget_buckets按scope_type/scope_key/currency唯一共享余额身份；budget_reservations每request唯一，冻结policy/price、用途、币种、numeric(24,10)最坏amount及bounds，actual_cost未知为null，状态reserved/unknown/settled。budget_allocations关联预留和四类桶，复合主键并索引bucket_id。请求上限直接按冻结policy检查。

usage_settlements按request/revision唯一保存归一化计费数量、各类金额、实际总额、输入摘要与凭证。billing_identities按provider/external_charge_id唯一指向原请求。cost_ledger按settlement/category/entry_kind唯一，保存价格、币种、bigint Token（账单未提供时可空）、有符号numeric金额和模拟证据性质；更正使用reversal与charge追加，不删除历史。billing_commands按actor/command_key唯一保存首次响应。ai_requests新增price_version_id外键。

关键约束/索引包含价格版本唯一、政策版本唯一、预算桶唯一、请求预留唯一、预留状态时间、桶分配反向索引、计费身份唯一、核销修订唯一和请求流水索引。全部新增表/字段具有中文数据库COMMENT。最终目标CSV的预算expires_at不在当前实现中：unknown预留无自动过期释放，防止风险费用消失。

## NF-C06 实际增量

- recovery_decisions：运行内人工动作、原因、规范化请求哈希、冻结预览和首次响应；actor/run/command_key 唯一，run/id 索引，记录不可变。
- generation_late_evidence：绑定同书同运行步骤及旧租约，content/hash/reason 留证；step/token/owner/hash 去重，记录不可变。
- recovery_outputs：同书同运行决定及来源产物复合外键、来源哈希、离线重提取内容及 schema_version；每个决定只产生一个结果，记录不可变。
- ai_requests.retry_after_seconds：供应商最短等待秒数，pending完成时记录，非负或NULL，终态不可修改。

所有新增表/字段实际添加中文 COMMENT，实测数量见 NF-C06_MIGRATION_RESULT.json。目标全量数据字典仍不是所有模块已实施的声明。

## NF-C07 实际增量

run_traces(run_id主键外键, trace_id唯一UUID, origin, created_at)只追加，历史补建与实时产生明确区分。audit_events保存根/操作追踪、所属书/运行/操作人、对象及动作、结果、高风险标记、原因、前后白名单快照、数据库时间；trace/operation/run及时间分别建索引，同书运行复合外键，UPDATE/DELETE被数据库拒绝。新增表及字段均有中文COMMENT。原始供应商证据沿用C03加密存储，没有新增公开文件或复制密钥字段。

## D01 当前物理实现（2026-10-24）

候选层新增 proposed_events/proposed_state_patches；source_documents 当前仅 artifact 一种强类型来源。state_snapshots 与 entity_state_versions 当前属于 candidate 快照，不能将其 local_state_version 用作小说正式状态令牌。story_events 仅预备结构，CHECK(false) 阻止写入；正式 revision/commit/chapter_version 的复合 FK 需 E06 表存在后补齐。上表保留最终设计目标，不意味着未来来源和正式状态现已开放。具体列、约束及验收见 ../phase-d/NF-D01_ACCEPTANCE.md。
