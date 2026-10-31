# NF-F01：Responses 离线协议基础

本页记录首批协议基础；可选字段适配的最新结果见 [五阶段传输Schema适配](SCHEMA_ADAPTATION.md)，原5条阻断已在本地解除。

日期：2026-10-30。状态：协议转换已实现并离线测试，尚未接入真实网关、生产阶段或后台启动。原有真实请求门禁不变。

## 实现与证据

- `app/ProvidersGateway/OpenAiResponsesProtocol.php`：纯请求构造/响应解析，不实现send、不持有密钥、不读写数据库、不调用HTTP。
- 请求采用非流式Responses、text.format.json_schema；关闭store/stream/background，禁用截断，固定default服务层。模型标识原样保留，不推定Terra/Sol账户可用性。
- max_output_tokens作为可见输出与推理的总上限。未知参数或非零独立max_reasoning_tokens直接拒绝，不能静默忽略冻结参数。
- 严格Schema递归要求所有属性必填，根对象和空properties对象类型明确；不擅自将既有可选字段改为必填/nullable。
- 解析真实API形状的output消息，忽略reasoning项但不把它当正文；拒绝refusal、incomplete、未知工具输出、多个文本输出、非JSON及不匹配Schema。该转换器当前有意限制为单个结构化文本，不声称支持所有Responses输出形态。
- 原始响应字节、HTTP状态和重试提示与解析结果分别保留；拒绝、截断、HTTP失败仍提取usage。response_id是响应资源ID，不冒充HTTP x-request-id。
- 映射嵌套缓存读写与推理计量；未提供不补零。缓存写入非零必须有TTL明细，12小时费率尚不支持则usage标记未知；不能用30分钟价格低估。原始证据仍保留供后续对账。响应正文成功与计量未知分开，接线时仍须BudgetService保留预留/对账门禁。
- 新增代码及测试含中文注释，无DDL，不涉及数据迁移。

单元测试9项、65断言通过，证据 `.local/nf-f01-protocol-tests.xml`；包括请求边界、嵌套可选字段、空对象、原始证据、计量去重、拒绝/截断/HTTP错误、未知usage和错误输出。夹具为人工合成，不是录制的供应商响应。直接使用PHPUnit TestCase，无Laravel启动、数据库或网络。Pint及git diff --check通过。

## 当前9条路由的只读兼容性检查

`.local/nf-f01-protocol-audit-20261030.json`记录所有阻断字段路径。

| 结果 | 阶段 |
|---|---|
| 本地构造器通过 | planning.intake、drafting.scene、review.chapter、revision.propose |
| 嵌套可选属性阻断 | planning.blueprint、planning.outline、planning.chapter_plan、planning.scene_plan、extraction.events |

例如规划的nodes/foundation_nodes.items.details以及提取的events、time、knowledge中存在可选属性。现有StructuredOutput允许可选字段，所以此前离线回归通过并不与本轮供应商严格协议阻断矛盾。4条本地通过也不是服务端或模型能力验证。

本轮只读事务检查现有模板与首个离线样例，不保存配置/小说数据：请求0、已发布路由0、启用供应商0。

## 下一步技术接线

1. 为5个阶段设计显式的供应商传输Schema和反向转换契约；优先保持业务可选语义，不把所有字段随意改成必填。若采用可空传输字段，必须区分缺失/显式null并有测试；当前业务Schema不支持类型联合，不能直接塞进模板绕过校验。
2. 接入协议转换边界，分开保存线上原始响应与内部解析产物；现有执行器直接读取response.output，不可用改写原始证据来迁就它。
3. 对模型身份、实际服务层、缓存TTL与冻结报价做一致性验证；协议本地通过不能替代能力探测。真实传输复用公开DNS固定、禁重定向、超时、脱敏和预算预留，不能只解除现有测试开关。
4. 然后接真实/离线模式、统一预算、冻结配置与后台单章编排；继续保持真实请求关闭直到具体运行包获得授权。

## 官方依据

本轮已检索并读取官方文档；接口参考抓取只返回页面标题，字段细节以实际读取的指南为准：

- [Structured Outputs](https://developers.openai.com/api/docs/guides/structured-outputs)：text.format、严格属性要求、refusal及incomplete。
- [Reasoning](https://developers.openai.com/api/docs/guides/reasoning)：总输出上限与reasoning_tokens、截断仍可能计费。
- [Prompt caching](https://developers.openai.com/api/docs/guides/prompt-caching)：cached_tokens、cache_write_tokens及TTL计量分拆。

这些协议文档不证明当前账户模型权限或现存价格凭证准确；本轮没有调用模型或替换价格。
