# NF-A01 / NF-A04 / NF-A05 设计与验收资料包

日期：2026-10-22。范围：基线核查、契约设计、验收方案；没有实现业务代码、安装服务器扩展或调用模型。

## 阅读顺序

1. [基线核查](A01_BASELINE.md)：本次实际检查、已发现隔离缺口与回归门禁。
2. [领域和执行契约](A04_CONTRACTS.md)：设计基线 v1，含ER图、不可变版本、状态机、提交和恢复协议。
3. [数据字典](data_dictionary.csv)：所有PRD §19/20逻辑实体、关键字段、关联、约束及实施归属。
4. [接口契约](A04_API.md)、[结构样例](contract_examples.json)：尚未实现的接口与输入输出约定。
5. [验收方案](A05_ACCEPTANCE.md)、[AC用例](acceptance_cases.csv)、[故障样例](fault_fixtures.json)。
6. [候选小说契约](novel_contract.json)、[前10章计划](ten_chapter_plan.csv)、[100章候选骨架](hundred_chapter_outline.csv)。
7. [检索语料](retrieval_corpus.jsonl)、[100条查询及正负标注](retrieval_queries.jsonl)。
8. [待决事项](DECISIONS.md)及 [执行记录模板](forms/execution_records.csv)、[单章人工表](forms/chapter_review.csv)、[伏笔人工表](forms/foreshadow_review.csv)。

## 完成状态的准确含义

| 任务 | 本轮交付 | 尚不能宣称的结果 |
| --- | --- | --- |
| NF-A01 | 核查交付；F01–F03 修复通过 | 见 [隔离修复验证](A01_ISOLATION_FIX.md)；F04最小权限归A02，中文项延期 |
| NF-A04 | 可实施设计基线v1已制定并完成文档一致性检查 | 不是代码实现或业务测试通过；需要作者确认的外部决策未冒充批准 |
| NF-A05 | 候选测试数据、100条检索标注、AC01–32步骤与人工记录表已交付 | 尚无模型生成的小说成品；正式验收批次须确认小说/模型/预算后冻结 |

机器可读文件全部是离线设计样例或人工构造夹具，不是生产数据，不可直接写入正式事件表或作为AC01–08成功证据。`manifest.json`固定文件内容哈希；变更需产生新版本，不能覆盖已有验收历史。

文档自检结果见 [VALIDATION.md](VALIDATION.md)，优先级变更见 [CR001](CR001_PRIORITY_CHANGE.md)。

本包采用当前仓库Markdown PRD及CR001为权威。父级 [任务清单](../DEVELOPMENT_TASKS.md) 只更新这三项的文档交付状态，其余任务不推进。


后续执行：NF-B01核心实现见[2026-10-23验收](../phase-b/NF-B01_ACCEPTANCE.md)。本资料包最初的“没有业务实现”描述为当时范围，不代表当前工程仍无业务代码。
