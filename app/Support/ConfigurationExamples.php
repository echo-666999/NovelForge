<?php

namespace App\Support;

/** 后台可编辑示例只含离线资料，标识需由用户选择实际已发布版本。 */
final class ConfigurationExamples
{
    public static function all(): array
    {
        $base = ['stage' => 'test.echo', 'language' => 'zh-CN', 'expected_version' => 0, 'reason' => '初始离线配置'];

        return [
            'prompt' => $base + ['key' => 'echo.prompt', 'content' => [
                'purpose' => '离线配置契约验证', 'body' => '请根据数据输出 text：{{json:topic}}',
                'variable_schema' => ['type' => 'object', 'properties' => ['topic' => ['type' => 'string', 'maxLength' => 1000]], 'required' => ['topic'], 'additionalProperties' => false],
                'output_schema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string', 'maxLength' => 200]], 'required' => ['text'], 'additionalProperties' => false],
                'capability_requirements' => ['structured_output' => true],
                'examples' => [['variables' => ['topic' => '故乡'], 'output' => ['text' => '故乡的桥'], 'required_facts' => ['故乡']]],
            ]],
            'style' => $base + ['key' => 'echo.style', 'content' => [
                'genre' => '通用', 'voice' => ['pov' => '第三人称有限视角', 'tone' => '克制', 'pace' => '张弛有度', 'description' => '具体行动和感官细节', 'forbidden_patterns' => ['命运的齿轮'], 'licensed_examples' => []],
                'rubric' => ['consistency' => ['description' => '人物一致性', 'threshold' => 80], 'voice' => ['description' => '文风符合度', 'threshold' => 70]], 'max_revision_rounds' => 2,
                'examples' => [['text' => '他推开门，停在门槛前。', 'scores' => ['consistency' => 90, 'voice' => 80], 'expected_pass' => true], ['text' => '命运的齿轮开始转动。', 'scores' => ['consistency' => 90, 'voice' => 80], 'expected_pass' => false]],
            ]],
            'route' => $base + ['key' => 'echo.route', 'content' => ['prompt_version_id' => '请填写已发布提示词版本ID', 'style_version_id' => '请填写已发布文风版本ID', 'primary_model_version_id' => '请填写模型能力版本ID', 'fallbacks' => [], 'parameters' => ['max_output_tokens' => 200], 'mode' => 'offline_fake']],
        ];
    }
}
