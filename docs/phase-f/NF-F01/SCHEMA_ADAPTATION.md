# NF-F01：五阶段传输Schema适配

日期：2026-10-30。范围：planning.blueprint、planning.outline、planning.chapter_plan、planning.scene_plan、extraction.events的可选字段传输。仅协议纯函数、离线测试及只读兼容检查；不扩展小说内容，不修改模板，不批准/发布/启用或发起真实请求。

## 实现

新增 `OpenAiTransportSchema`，契约版本 `optional-envelope-v1`，由 `OpenAiResponsesProtocol`请求构造及响应还原调用。业务Schema继续使用原有有界子集，供应商Schema独立生成，不向业务校验器添加anyOf或改变原required。

可选业务属性 `x` 在传输中成为必填封装：

- 原字段缺失：`"x": {"state":"absent"}`，还原时不创建x。
- 原字段有值：`"x": {"state":"present","value":原值}`，value按该字段的原Schema递归适配。
- 原Schema允许null时，present/value=null保留显式null；原Schema不允许null时拒绝，不用null冒充缺省。

两个分支通过嵌套anyOf描述，均为additionalProperties=false的封闭对象，各自全部属性required；根节点仍为对象。必填业务属性不包封装，所有数组保持顺序。空对象、空数组、空字符串、0、false和缺省保持区别；业务属性名state/value不会与封装冲突。

解码先检查传输对象键集合、分支、value及类型，再对还原结果执行原业务Schema校验。缺少封装、absent携带value、present缺少value、未知标签、额外字段、必填缺失、类型和长度错误均拒绝，不静默丢弃或补齐。maxLength/maxItems继续保留，传输扩展后检查10层嵌套和5000属性上限。

原始HTTP响应字符串始终留在raw_body；仅output替换为还原业务对象，返回transport_schema_version。转换失败也保留原字节及可用usage，不能因为失败就记零费用。纯函数不持有密钥、不联网、不写库；将来持久化时仍需网关脱敏/加密，原始证据与内部产物分别保存，不能直接覆盖现有response证据以迁就旧执行器。

## 验证

- `OpenAiResponsesProtocolTest` + `OpenAiTransportSchemaTest`：19项、652断言通过，证据 `.local/nf-f01-schema-tests.xml`。
- 测试直接使用5个阶段的实际PlanningContract/ExtractionContract Schema，分别覆盖嵌套可选字段全部缺省及全部存在；不使用虚构的简化阶段Schema代替。
- 补充显式null/false/0/空值/空容器、同名属性、数组顺序、错误封装、长度限制、深度限制及失败响应计量/证据保留。
- 只读事务检查开发库9条路由及全部12个既有离线样例：全部通过请求构造和JSON传输往返，业务对象语义一致，原始响应字符串逐字一致。
- 原5条阻断已解除；证据 `.local/nf-f01-schema-adaptation-audit-20261030.json`。这是本地协议兼容结果，不是服务端Schema接受或真实模型能力验证。
- Pint、git diff --check通过。没有DDL，无数据库表/字段变更；业务代码与测试新增说明使用中文。
- 检查时ai_requests=0、published_routes=0、enabled_providers=0；只读事务没有保存模板、设定或运行状态。

## 剩余边界

本轮没有接入实际HTTP传输、真实模式路由发布或生产阶段，NF-F01仍未完成。后续须在冻结配置中携带业务Schema、传输版本及实际线上请求证据；核算增加的Schema/封装token开销，不能继续沿用未包含Schema的容量估算。随后接网关/执行器的双证据路径，再完成后台单章编排与隔离故障联调。真实请求仍需后续明确授权。

## 协议依据

本轮复核 [OpenAI Structured Outputs官方文档](https://developers.openai.com/api/docs/guides/structured-outputs)：根对象、所有属性required、嵌套anyOf、封闭对象以及大小/深度限制。选择显式标签封装而非单纯nullable，是为保留业务“字段缺失”与“显式null”的区别；这是一项本项目传输设计，不是官方默认格式。
