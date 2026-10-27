<?php

// 项目中文校验文案；仅替换展示文本，不更改Laravel校验规则。
return [
    'required' => ':attribute不能为空。', 'string' => ':attribute必须是文本。',
    'integer' => ':attribute必须是整数。', 'numeric' => ':attribute必须是数字。',
    'boolean' => ':attribute必须为是或否。', 'array' => ':attribute必须为符合结构的列表或对象。',
    'regex' => ':attribute格式无效。', 'in' => ':attribute不在允许范围内。',
    'exists' => '所选:attribute不存在。', 'unique' => ':attribute已经存在。',
    'date' => ':attribute必须为有效日期。', 'date_format' => ':attribute必须符合:format格式。',
    'json' => ':attribute必须为有效JSON。', 'email' => ':attribute必须为有效邮箱。',
    'min' => ['numeric' => ':attribute不能小于:min。', 'string' => ':attribute至少:min个字符。', 'array' => ':attribute至少:min项。', 'file' => ':attribute至少:min KB。'],
    'max' => ['numeric' => ':attribute不能大于:max。', 'string' => ':attribute最多:max个字符。', 'array' => ':attribute最多:max项。', 'file' => ':attribute最多:max KB。'],
    'size' => ['numeric' => ':attribute必须等于:size。', 'string' => ':attribute必须为:size个字符。', 'array' => ':attribute必须有:size项。', 'file' => ':attribute必须为:size KB。'],
    'gt' => ['numeric' => ':attribute必须大于:value。', 'string' => ':attribute字符数必须大于:value。', 'array' => ':attribute项数必须大于:value。', 'file' => ':attribute必须大于:value KB。'],
    'between' => ['numeric' => ':attribute必须在:min至:max之间。', 'string' => ':attribute必须为:min至:max个字符。', 'array' => ':attribute必须为:min至:max项。'],
    // 补足现有世界、检索、规划与配置页面使用的关联和条件规则。
    'different' => ':attribute与:other不能相同。', 'same' => ':attribute必须与:other相同。',
    'distinct' => ':attribute不能重复。', 'present' => ':attribute必须提供。', 'prohibited' => '不允许填写:attribute。',
    'required_if' => '当:other为:value时必须填写:attribute。', 'required_with' => '填写:values时必须填写:attribute。',
    'url' => ':attribute必须为有效URL。', 'ip' => ':attribute必须为有效IP地址。', 'not_in' => ':attribute的取值不允许。',
    'gte' => ['numeric' => ':attribute必须大于或等于:value。', 'string' => ':attribute字符数必须大于或等于:value。', 'array' => ':attribute项数必须大于或等于:value。'],
    'lte' => ['numeric' => ':attribute必须小于或等于:value。', 'string' => ':attribute字符数必须小于或等于:value。', 'array' => ':attribute项数必须小于或等于:value。'],
    'attributes' => ['currency' => '币种', 'key' => '稳定标识', 'expected_version' => '预期版本', 'warning_percent' => '预警阈值', 'stop_percent' => '停止阈值', 'timezone' => '时区', 'reason' => '原因', 'limits' => '预算限额', 'model_version_id' => '模型版本', 'date_from' => '开始日期', 'date_to' => '结束日期', 'novel_id' => '小说', 'page' => '页码'],
];
