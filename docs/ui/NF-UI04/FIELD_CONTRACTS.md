# NF-UI04 字段契约与组件约定

数据仍经 `AuthoringConfigurationService` / `ProviderConfigurationService`；UI不直接写配置表。共有身份 key/stage/language、变更说明 reason、来源版本和打开时 expected_version；key/stage/language在复制时不可改名。保存采用当前幂等键，成功后关闭编辑器；测试/发布/退役各有独立确认。

| 页面 | 普通字段与组件 | 结构化字段与校验 |
| --- | --- | --- |
| Prompt | 用途Input、正文Textarea、要求结构化Toggle | variable_schema / output_schema 语义递归编辑；examples 类型树；capability_requirements.structured_output；安全变量插入 |
| 路由 | 实际Prompt/文风/主模型能力版本Select、mode菜单、输出Token整数 | fallbacks最多2项有序Repeater：实际能力版本、明确批准、理由；parameters仅映射已有max_output_tokens，其他键只读保留 |
| 文风 | genre、pov、tone、pace、description、禁用模式TagsInput、授权样例简单Repeater、修订上限整数 | rubric对象逐维description/threshold、样例text/scores/expected_pass类型树；评分来自人工标注，不称为模型文学评审 |
| 供应商 | 原生POST名称/接口/HTTPS端点/超时/连接超时/限流/并发/启用/理由/密码 | allowed_hosts逐行，不将逗号JSON作为主入口；编辑保留稳定名称、供应商ID和expected_version；密码空值保持既有密钥，不回填 |
| 模型 | 原生POST实际供应商、模型标识、上下文/输出容量、结构化/流式/推理计量枚举、来源说明、理由 | 空容量映射null；容量仍由原服务验证；版本始终未验证，选择的是能力版本ID而非稳定profile ID |
| 详情与比较 | 安全字段表、类型、友好名称与JSON Pointer、变化标记 | 已发布内容只读，复制另存版本；对象键语义比较，数组位置有意义，不排序掩盖差异；缺失/null/空文本分开 |

Schema类型限 object/array/string/integer/boolean/null；对象属性按行填写，required逐项切换，additionalProperties严格false；数组items递归声明；maxLength/maxItems使用整数。切换类型不自动删除旧关键词，冲突显式阻止保存。删除属性同时移除对应required记录；普通样例/列表需显式排序或移除。

类型树识别object/array/string/integer/number/boolean/null；导入解析上限200000字节、JSON深度32，解码树深度24、单节点子行最多1000。重复键（含转义后重名）拒绝，错误导入不覆盖原表单；number可保留/导出，现有保存规范拒绝小数。整数支持以PHP当前整数范围为限，不保证JSON数字文本形式完全相同。

`EditsJsonFields`保存服务端锁定tree和字段映射，前端只绑定 `jsonInputs.f...`，深节点按需展开。只有映射中标量字段可变更；不能通过任意路径改来源/身份。不提高框架请求深度、大小阈值。schema、examples、rubric与原生Repeater的错误路径分别映射到真实控件，保存失败保留非敏感状态。

高级导入仅作用于content，不能导入身份/expected_version；高级导出恢复简单Repeater的有序语义。未知根键或voice/parameters/capability扩展只读展示并保留导出；旧服务拒绝的字段仍阻止保存，不在本批改变Schema或模型能力合同。

供应商历史查询显式列白名单，最多最近100版；历史签名状态不含secret_id/ciphertext/key。每类只展开一份供应商/模型编辑表单。原生POST失败白名单回填，URL只保留同源 `/admin/providers` 的q/interface/enabled/structured/page参数和合法表单锚点。API JSON调用仍保留原格式和错误语义。
