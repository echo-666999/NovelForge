<?php

namespace Tests\Support;

use App\Models\Novel;
use App\Planning\PlanningContract;
use App\Services\AuthoringConfigurationService;
use App\Services\NovelIntentService;
use App\Services\OutboxService;
use App\Services\PlanningPipelineService;
use App\Services\ProviderConfigurationService;
use App\Support\ConfigurationExamples;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** 离线规划夹具的容量和报价均为虚构，不代表任何真实供应商能力。 */
final class PlanningFixture
{
    public static function create(int $chapters = 1): array
    {
        $provider = ProviderFixture::create();
        $f = BlueprintFixture::create($provider['actor'], $chapters);
        $f['novel'] = $f['book']['novel']['id'];
        $m = $provider['model_payload'];
        $m['provider_model_id'] = 'planning-offline-only';
        $m['declared_capabilities']['context_tokens'] = 1000000;
        $m['declared_capabilities']['max_output_tokens'] = 10000;
        $f['model'] = app(ProviderConfigurationService::class)->execute($f['actor'], 'model', $m, 'planning-model')['version_id'];
        $price = BillingFixture::price($f['actor'], $f['model'], 'planning-price');
        $f['billing'] = array_replace($provider['payload']['billing'], ['price_version_ids' => [$price['id']], 'category' => 'planning', 'max_output_tokens' => 1000]);

        return $f;
    }

    public static function intake(array $f): array
    {
        return ['requirements' => array_map(fn ($r) => array_intersect_key($r, array_flip(['requirement_id', 'source_quote', 'content', 'category', 'strength', 'critical'])), app(NovelIntentService::class)->requirements(Novel::find($f['novel'])))];
    }

    public static function route(array $f, string $phase, array $output): string
    {
        $examples = ConfigurationExamples::all();
        $release = function ($kind, $p) use ($f) {
            $p['key'] = 'planning.'.$kind.'.'.Str::lower(Str::random(8));
            $s = app(AuthoringConfigurationService::class);
            $v = $s->execute($f['actor'], $kind, 'save', $p, (string) Str::uuid());
            $test = $s->execute($f['actor'], $kind, 'test', ['version_id' => $v['id']], (string) Str::uuid());
            if (! $test['regression']['passed']) {
                throw new \RuntimeException(json_encode($test));
            }

            return $s->execute($f['actor'], $kind, 'publish', ['version_id' => $v['id']], (string) Str::uuid());
        };
        $base = ['stage' => 'planning.'.$phase, 'language' => 'zh-CN', 'expected_version' => 0, 'reason' => '离线规划测试'];
        $prompt = $release('prompt', $base + ['content' => ['purpose' => '仅验证规划候选链路', 'body' => '保留作者特殊细节并仅输出候选：{{json:context}}', 'variable_schema' => PlanningContract::object(['context' => ['type' => 'string', 'maxLength' => 100000]]), 'output_schema' => PlanningContract::schema($phase), 'capability_requirements' => ['structured_output' => true], 'examples' => [['variables' => ['context' => '离线资料'], 'output' => $output, 'required_facts' => []]]]]);
        $style = $release('style', $base + ['content' => $examples['style']['content']]);

        return $release('route', $base + ['content' => ['prompt_version_id' => $prompt['id'], 'style_version_id' => $style['id'], 'primary_model_version_id' => $f['model'], 'fallbacks' => [], 'parameters' => ['max_output_tokens' => 1000], 'mode' => 'offline_fake']])['id'];
    }

    public static function start(array $f, string $phase, array $output, ?string $parent = null, ?string $route = null, ?string $key = null): array
    {
        return app(PlanningPipelineService::class)->start($f['actor'], $f['novel'], ['start_chapter' => in_array($phase, ['chapter_plan', 'scene_plan'], true) ? 1 : null, 'end_chapter' => in_array($phase, ['chapter_plan', 'scene_plan'], true) ? 10 : null, 'phase' => $phase, 'parent_id' => $parent, 'route_version_id' => $route ?? self::route($f, $phase, $output), 'billing' => $f['billing'], 'offline_output' => $output, 'expected_version' => Novel::find($f['novel'])->row_version, 'reason' => '明确离线候选回放'], $key ?? (string) Str::uuid());
    }

    public static function execute(array $f, array $stage): array
    {
        $id = (string) DB::table('outbox_events')->where('run_id', $stage['data']['run']['id'])->value('id');
        app(OutboxService::class)->consume($id);

        return app(PlanningPipelineService::class)->read($f['actor'], $f['novel'], $stage['id']);
    }

    public static function adopt(array $f, array $stage): array
    {
        $a = collect($stage['data']['artifacts'])->last(fn ($a) => $a['type'] === 'planning_candidate');

        return app(PlanningPipelineService::class)->adopt($f['actor'], $f['novel'], $stage['id'], ['artifact_id' => $a['id'], 'expected_version' => Novel::find($f['novel'])->row_version, 'reason' => '作者确认采纳离线规划候选'], (string) Str::uuid());
    }

    public static function confirmRequirements(array $f): void
    {
        foreach (app(NovelIntentService::class)->requirements(Novel::find($f['novel'])) as $r) {
            app(NovelIntentService::class)->execute($f['actor'], 'requirement', $f['novel'], array_replace($r, ['expected_version' => Novel::find($f['novel'])->row_version, 'strength' => 'hard', 'decision_status' => 'accepted', 'author_decision' => '作者确认具体原句', 'reason' => '作者检查']), (string) Str::uuid());
        }
    }

    public static function node(array $row, string $key, string $type, string $summary): array
    {
        return ['key' => $key, 'parent_key' => '', 'type' => $type, 'node_id' => (string) $row['id'], 'parent_id' => (string) ($row['parent_id'] ?? ''), 'sequence' => $row['sequence'], 'title' => $row['title'], 'objective' => $row['objective'], 'summary' => $summary, 'status' => 'detailed', 'start_chapter' => $row['start_chapter'] ?? 0, 'end_chapter' => $row['end_chapter'] ?? 0, 'details' => $row['details'], 'thread_ids' => []];
    }
}
