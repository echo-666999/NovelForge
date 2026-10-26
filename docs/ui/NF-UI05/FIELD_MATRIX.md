# NF-UI05 字段与状态矩阵

| 区域 | 可编辑字段或选择 | 来源与门禁 |
| --- | --- | --- |
| 总览 | 小说状态、上海日期起止、每页10/25/50/100、分页 | WorkspaceService；仅本用户小说，过滤失败和无匹配区分；指标未知 |
| 正文上下文 | 实际章节版本、紧邻前章有效候选、同正文重提取来源、作者上下文 | 本书真实版本；正文只读；记录来源指纹及乐观行/状态版本 |
| 候选事件 | key、definition_version_id、holder_id、fact_kind、action、supersedes_id、attribute、类型化value、confidence、time、reason、evidence、uses | 主体为批准设定版本；引用和说法持有者用实际选项；证据含hash/起止码点/逐字引文，最终由服务重验 |
| 事件可选约束 | activity、location_id、ability_id、ability_units、previous_owner_id、revival_rule_id | 明确添加/移除；地点/能力/人物/规则按类型选择，单位需填写，不猜规则或行动 |
| 时间 | kind、clock、start、end | unknown/exact/range、相对秒/UTC；可选字段显式增加，不自动填0推断时刻 |
| 依赖 | predecessor_ref、successor_ref、relation_type | local:稳定事件键或event:前章事件；before/depends_on/contains/simultaneous |
| 知识 | knower_id、fact_ref、acquired_ref、method、source_knower_id | 实际人物及事件；witness/told/inference，服务验证获知时点和来源 |
| 关系 | event_ref、from_entity_id、to_entity_id、relation_type、state | 同书实体与事件；状态多行文本，不替代服务一致性校验 |
| 伏笔 | plan_id、event_ref、kind | 本书实际计划版本；plant/reinforce/payoff，窗口仍由服务核对 |
| 候选结果 | 状态、问题、诊断、事件、补丁、伏笔、回放投影 | 全部只读；问题事件键可定位编辑行，复制响应新增不可变记录 |
| 历史 | 最近50条内查询/分页、读取、复制 | 只读不可变记录；完整分页另列UI12 |
| 离线流水线 | 已发布extraction.events/offline_fake路由、预算政策、对应模型报价复选、本书提取运行 | 与纯验证分开；复用冻结/预算/请求证据；回放服务端拒绝非提取运行 |
| 高级入口 | 响应导入/导出 | 导入不改变书/正文身份，重复键或非法结构不覆盖；未知字段保留，可显式移除；不要求日常手写JSON |

树与路径映射采用Locked，客户端只有jsonInputs扁平标量更新。字段名不参与绑定路径；数组顺序保持，对象展示按协议而不是存储排列。null仅在明确选择新值时恢复其契约类型，非法数组值不会先修改原类型。折叠列表只渲染当前行；没有提高Livewire默认请求嵌套和大小限制。

| 状态 | 表现与验证 |
| --- | --- |
| 0条/未选择 | 总览首次创建、无书提取、无正文、空历史分别说明，不冒充通过 |
| 正常 | 真实小说/来源/候选数据，明确候选身份及实际版本 |
| 长数据 | 中文长书名固定列宽；正文阅读行宽/局部纵向滚动；表格局部横向滚动；字段换行 |
| 错误 | 筛选和读取失败保留重试；输入错误保留字段；证据失败显示原文位置和稳定事件键 |
| 加载 | 公共status提示和动作disabled，实测重复提交加载状态 |
| 禁用 | 未读取正文、无原因、缺路由/预算/报价、不可用候选时相关动作禁用；服务端仍验证 |
| 冲突/成功 | 乐观冲突不覆盖；同正文修正后形成新候选，旧记录保留，非正式剧情 |
