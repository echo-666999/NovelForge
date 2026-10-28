# NF-UI21 页面核查矩阵

真实Chrome，最终固定构建；合成小说当前规划/空态。行内元素包含控件及同行说明；0表示没有适用的自定义行，不表示全量原生控件都已验证。前后回归曾重建测试夹具，书ID不同，不改变本次布局比较条件。

| 页面 | 路径 | 桌面行内元素 | 窄屏元素 | 根页面横向溢出 |
| --- | --- | ---: | ---: | --- |
| 创作总览 | `/` | 10 | 10 | 无 |
| 实施状态 | `/project-status` | 0 | 0 | 无 |
| 小说 | `/novels` | 0 | 0 | 无 |
| 故事管理 | `/story-hub?novel=1` | 3 | 3 | 无 |
| 时间线与角色知识 | `/story-timeline?novel=1` | 3 | 3 | 无 |
| 伏笔与主支线账本 | `/foreshadow-ledger?novel=1` | 3 | 3 | 无 |
| 生成运行中心 | `/run-center?novel=1` | 9 | 9 | 无 |
| 正文提取与候选事件账本 | `/extraction-ledger?novel=1` | 24 | 24 | 无 |
| 评审中心与文风策略 | `/review-center?novel=1` | 5 | 5 | 无 |
| 受限修订与完整章节复评 | `/revision-desk?novel=1` | 7 | 7 | 无 |
| 正式提交与基线 | `/canonical-commit-center?novel=1` | 9 | 9 | 无 |
| 上下文检查器 | `/context-inspector?novel=1` | 7 | 7 | 无 |
| 运行恢复台 | `/recovery-desk?novel=1` | 3 | 3 | 无 |
| 分层记忆库 | `/memory-library?novel=1` | 3 | 3 | 无 |
| 正式记忆与索引 | `/canonical-memory-center?novel=1` | 3 | 3 | 无 |
| 检索实验室与索引 | `/search-lab?novel=1` | 3 | 3 | 无 |
| Prompt 模板与发布 | `/prompt-templates` | 9 | 9 | 无 |
| 供应商与模型 | `/providers` | 28 | 28 | 无 |
| 模型主备路由 | `/model-routes` | 9 | 9 | 无 |
| 文风与评分策略 | `/style-profiles` | 9 | 9 | 无 |
| 价格与原子预算 | `/billing` | 3 | 3 | 无 |
| 审计与系统健康 | `/audit-center` | 7 | 7 | 无 |
| 创作工作台 | `/novels/1/workspace` | 15 | 15 | 无 |
| 候选正文 | `/novels/1/draft-editor` | 5 | 5 | 无 |
| 原始要求与覆盖矩阵 | `/novels/1/intent` | 9 | 9 | 无 |
| 蓝图与终局契约 | `/novels/1/blueprints` | 3 | 3 | 无 |
| 五层结构与版本 | `/novels/1/structure` | 14 | 14 | 无 |
| 滚动细化与章节场景计划 | `/novels/1/plans` | 14 | 14 | 无 |
| 世界实体与人物设定 | `/novels/1/world` | 12 | 12 | 无 |
| 离线规划候选 | `/novels/1/planning-candidates` | 10 | 10 | 无 |
| 创建 小说 | `/novels/create` | 0 | 0 | 无 |
