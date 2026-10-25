<?php

namespace Tests\Support;

use App\Extraction\ExtractionContract;
use App\Planning\PlanningContract;
use App\Services\AuthoringConfigurationService;
use App\Services\ExtractionPipelineService;
use App\Support\ConfigurationExamples;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** 模板、路由和价格均为明确离线测试配置，不代表真实模型能力。 */
final class ExtractionPipelineFixture
{
    public static function route(array $f, array $output): string
    {
        $release = function ($kind, $content) use ($f) {
            $service = app(AuthoringConfigurationService::class);
            $d = ['key' => 'extraction.'.$kind.'.'.Str::lower(Str::random(8)), 'stage' => 'extraction.events', 'language' => 'zh-CN', 'expected_version' => 0, 'reason' => '离线事件提取验收', 'content' => $content];
            $v = $service->execute($f['actor'], $kind, 'save', $d, (string) Str::uuid());
            $test = $service->execute($f['actor'], $kind, 'test', ['version_id' => $v['id']], (string) Str::uuid());
            if (! $test['regression']['passed']) {
                throw new \RuntimeException(json_encode($test));
            }

            return $service->execute($f['actor'], $kind, 'publish', ['version_id' => $v['id']], (string) Str::uuid());
        };
        $prompt = $release('prompt', ['purpose' => '从实际候选正文提议事件和精确证据，不能批准或提交', 'body' => '保留事实类别与秘密边界：{{json:context}}', 'variable_schema' => PlanningContract::object(['context' => ['type' => 'string', 'maxLength' => 100000]]), 'output_schema' => ExtractionContract::schema(), 'capability_requirements' => ['structured_output' => true], 'examples' => [['variables' => ['context' => '离线测试资料'], 'output' => $output, 'required_facts' => []]]]);
        $style = $release('style', ConfigurationExamples::all()['style']['content']);
        $original = json_decode(DB::table('route_versions')->where('id', $f['route'])->value('content'), true);

        return $release('route', ['prompt_version_id' => $prompt['id'], 'style_version_id' => $style['id'], 'primary_model_version_id' => $original['primary_model_version_id'], 'fallbacks' => [], 'parameters' => ['max_output_tokens' => 1000], 'mode' => 'offline_fake'])['id'];
    }

    public static function start(array $f, array $output, string $route, array $extra = [], ?string $key = null): array
    {
        return app(ExtractionPipelineService::class)->start($f['actor'], $f['novel'], ExtractionFixture::payload($f, $output, $extra) + ['route_version_id' => $route, 'billing' => $f['billing']], $key ?? (string) Str::uuid());
    }
}
