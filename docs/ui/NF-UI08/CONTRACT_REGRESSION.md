# NF-UI08 契约回归映射

依据[PRD](../../PRD-V1.1.md)、[A04](../../phase-a/A04_CONTRACTS.md)及现有实现。下列类名均从实际两轮JUnit提取；跨维度类允许重复列出，表内计数不相加。精确方法名、断言及来源见[逐例汇总](MERGED_RESULTS.json)。

| 契约维度 | 已执行测试类 | 覆盖约束/界限 |
| --- | --- | --- | --- |
| 隔离与基础 | `Tests.Feature.FoundationTest`、`Tests.Feature.TestIsolationTest`、`Tests.Feature.UiFoundationTest`、`Tests.Unit.TestIsolationTest` | DB_URL、批准测试库、Redis/队列/文件边界；中文分词仍跳过 |
| 小说、结构、世界与批准 | `Tests.Feature.BlueprintTest`、`Tests.Feature.NovelConcurrencyTest`、`Tests.Feature.NovelIntentTest`、`Tests.Feature.RollingPlanTest`、`Tests.Feature.StructureTest`、`Tests.Feature.WorldTest` | 五层归属、不可变版本、批准/覆盖与规划门禁 |
| 供应商及配置版本 | `Tests.Feature.AuthoringConfigurationTest`、`Tests.Feature.ProviderGatewayTest` | 能力、路由、Prompt/文风版本、凭据遮蔽与冻结引用 |
| 运行、预算及恢复 | `Tests.Feature.BillingConcurrencyTest`、`Tests.Feature.BillingSelectTest`、`Tests.Feature.BillingTest`、`Tests.Feature.CanonicalMemoryRecoveryTest`、`Tests.Feature.DraftingRecoveryTest`、`Tests.Feature.ExtractionRecoveryTest`、`Tests.Feature.GenerationConcurrencyTest`、`Tests.Feature.GenerationTest`、`Tests.Feature.OutboxRecoveryTest`、`Tests.Feature.OutboxTest`、`Tests.Feature.PlanningRecoveryTest`、`Tests.Feature.RecoveryConcurrencyTest`、`Tests.Feature.RecoveryTest`、`Tests.Feature.ReviewRecoveryTest`、`Tests.Feature.RevisionRecoveryTest`、`Tests.Feature.RunCenterConcurrencyTest`、`Tests.Feature.RunCenterTest` | 事务outbox、租约/幂等、金额精度/未知费用/原子预算与恢复 |
| 故事与检索上下文 | `Tests.Feature.ContextAssemblyTest`、`Tests.Feature.HybridSearchTest`、`Tests.Feature.LayeredMemoryTest`、`Tests.Feature.SearchCorpusTest`、`Tests.Feature.StoryConsistencyConcurrencyTest`、`Tests.Feature.StoryConsistencyTest`、`Tests.Feature.StoryStateTest`、`Tests.Unit.ContextComposerTest` | 事件证据、可回放、双时间/知识、跨书/章位/POV过滤、有效性与上下文 |
| 生产与正式边界 | `Tests.Feature.CanonicalCommitTest`、`Tests.Feature.CanonicalMemoryRecoveryTest`、`Tests.Feature.CanonicalMemoryTest`、`Tests.Feature.ChapterReviewTest`、`Tests.Feature.ExtractionPipelineTest`、`Tests.Feature.ExtractionRecoveryTest`、`Tests.Feature.ExtractionTest`、`Tests.Feature.ExtractionUiTest`、`Tests.Feature.PlanningPipelineTest`、`Tests.Feature.ReviewPipelineTest`、`Tests.Feature.ReviewRecoveryTest`、`Tests.Feature.RevisionContractTest`、`Tests.Feature.RevisionFrozenPolicyTest`、`Tests.Feature.RevisionPipelineTest`、`Tests.Feature.RevisionRecoveryTest`、`Tests.Feature.RevisionTest` | 候选规划/正文、评审修订、正式提交、提交后索引与连续章 |
| UI与导航 | `Tests.Feature.BillingSelectTest`、`Tests.Feature.ConfigurationUiTest`、`Tests.Feature.ExtractionUiTest`、`Tests.Feature.NovelNavigationTest`、`Tests.Feature.ProductionUiTest`、`Tests.Feature.StoryUiTest`、`Tests.Feature.UiFoundationTest` | 字段往返/错误保值、选择器与入口、只读及转义；不代替视觉验收 |

其他实际执行类：`Tests.Feature.CanonicalConcurrencyTest`、`Tests.Feature.DraftingRecheckTest`、`Tests.Feature.DraftingTest`、`Tests.Feature.ExampleTest`、`Tests.Feature.ForeshadowConcurrencyTest`、`Tests.Feature.ForeshadowLedgerTest`、`Tests.Feature.MemoryConcurrencyTest`、`Tests.Feature.ProviderConcurrencyTest`、`Tests.Feature.SearchConcurrencyTest`、`Tests.Feature.WorkspaceTest`、`Tests.Unit.ExampleTest`、`Tests.Unit.IntentCanonicalTest`。

## 按类核对清单

同一用例按最新结果：457例，456通过、1个后置中文分词跳过。首次全量失败与49项定向通过分别保留，不构造一次绿色全量JUnit。

| 实际测试类 | 唯一用例 | 最新通过 | 最新跳过 |
| --- | --- | --- | --- |
| `Tests.Feature.AuthoringConfigurationTest` | 16 | 16 | 0 |
| `Tests.Feature.BillingConcurrencyTest` | 1 | 1 | 0 |
| `Tests.Feature.BillingSelectTest` | 4 | 4 | 0 |
| `Tests.Feature.BillingTest` | 16 | 16 | 0 |
| `Tests.Feature.BlueprintTest` | 20 | 20 | 0 |
| `Tests.Feature.CanonicalCommitTest` | 9 | 9 | 0 |
| `Tests.Feature.CanonicalConcurrencyTest` | 2 | 2 | 0 |
| `Tests.Feature.CanonicalMemoryRecoveryTest` | 3 | 3 | 0 |
| `Tests.Feature.CanonicalMemoryTest` | 11 | 11 | 0 |
| `Tests.Feature.ChapterReviewTest` | 7 | 7 | 0 |
| `Tests.Feature.ConfigurationUiTest` | 14 | 14 | 0 |
| `Tests.Feature.ContextAssemblyTest` | 11 | 11 | 0 |
| `Tests.Feature.DraftingRecheckTest` | 3 | 3 | 0 |
| `Tests.Feature.DraftingRecoveryTest` | 1 | 1 | 0 |
| `Tests.Feature.DraftingTest` | 9 | 9 | 0 |
| `Tests.Feature.ExampleTest` | 1 | 1 | 0 |
| `Tests.Feature.ExtractionPipelineTest` | 2 | 2 | 0 |
| `Tests.Feature.ExtractionRecoveryTest` | 2 | 2 | 0 |
| `Tests.Feature.ExtractionTest` | 11 | 11 | 0 |
| `Tests.Feature.ExtractionUiTest` | 10 | 10 | 0 |
| `Tests.Feature.ForeshadowConcurrencyTest` | 1 | 1 | 0 |
| `Tests.Feature.ForeshadowLedgerTest` | 9 | 9 | 0 |
| `Tests.Feature.FoundationTest` | 7 | 6 | 1 |
| `Tests.Feature.GenerationConcurrencyTest` | 1 | 1 | 0 |
| `Tests.Feature.GenerationTest` | 12 | 12 | 0 |
| `Tests.Feature.HybridSearchTest` | 5 | 5 | 0 |
| `Tests.Feature.LayeredMemoryTest` | 9 | 9 | 0 |
| `Tests.Feature.MemoryConcurrencyTest` | 1 | 1 | 0 |
| `Tests.Feature.NovelConcurrencyTest` | 6 | 6 | 0 |
| `Tests.Feature.NovelIntentTest` | 16 | 16 | 0 |
| `Tests.Feature.NovelNavigationTest` | 8 | 8 | 0 |
| `Tests.Feature.OutboxRecoveryTest` | 3 | 3 | 0 |
| `Tests.Feature.OutboxTest` | 11 | 11 | 0 |
| `Tests.Feature.PlanningPipelineTest` | 11 | 11 | 0 |
| `Tests.Feature.PlanningRecoveryTest` | 1 | 1 | 0 |
| `Tests.Feature.ProductionUiTest` | 12 | 12 | 0 |
| `Tests.Feature.ProviderConcurrencyTest` | 1 | 1 | 0 |
| `Tests.Feature.ProviderGatewayTest` | 17 | 17 | 0 |
| `Tests.Feature.RecoveryConcurrencyTest` | 1 | 1 | 0 |
| `Tests.Feature.RecoveryTest` | 11 | 11 | 0 |
| `Tests.Feature.ReviewPipelineTest` | 2 | 2 | 0 |
| `Tests.Feature.ReviewRecoveryTest` | 1 | 1 | 0 |
| `Tests.Feature.RevisionContractTest` | 2 | 2 | 0 |
| `Tests.Feature.RevisionFrozenPolicyTest` | 2 | 2 | 0 |
| `Tests.Feature.RevisionPipelineTest` | 2 | 2 | 0 |
| `Tests.Feature.RevisionRecoveryTest` | 1 | 1 | 0 |
| `Tests.Feature.RevisionTest` | 7 | 7 | 0 |
| `Tests.Feature.RollingPlanTest` | 19 | 19 | 0 |
| `Tests.Feature.RunCenterConcurrencyTest` | 1 | 1 | 0 |
| `Tests.Feature.RunCenterTest` | 8 | 8 | 0 |
| `Tests.Feature.SearchConcurrencyTest` | 1 | 1 | 0 |
| `Tests.Feature.SearchCorpusTest` | 1 | 1 | 0 |
| `Tests.Feature.StoryConsistencyConcurrencyTest` | 1 | 1 | 0 |
| `Tests.Feature.StoryConsistencyTest` | 13 | 13 | 0 |
| `Tests.Feature.StoryStateTest` | 8 | 8 | 0 |
| `Tests.Feature.StoryUiTest` | 9 | 9 | 0 |
| `Tests.Feature.StructureTest` | 16 | 16 | 0 |
| `Tests.Feature.TestIsolationTest` | 10 | 10 | 0 |
| `Tests.Feature.UiFoundationTest` | 7 | 7 | 0 |
| `Tests.Feature.WorkspaceTest` | 10 | 10 | 0 |
| `Tests.Feature.WorldTest` | 23 | 23 | 0 |
| `Tests.Unit.ContextComposerTest` | 3 | 3 | 0 |
| `Tests.Unit.ExampleTest` | 1 | 1 | 0 |
| `Tests.Unit.IntentCanonicalTest` | 2 | 2 | 0 |
| `Tests.Unit.TestIsolationTest` | 11 | 11 | 0 |

服务约束通过不代表真实模型已验证；检索合成向量、人工评分、测试Canonical Commit不构成前10章文学成品或百章伏笔回收验收。NF-E08、NF-H01/H03以及其他未执行业务仍保持原状态。
