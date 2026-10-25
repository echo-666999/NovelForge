<?php

namespace Tests\Support;

use App\Drafting\BodyContract;
use App\Models\Novel;
use App\Planning\PlanningContract;
use App\Services\AuthoringConfigurationService;
use App\Services\DraftingService;
use App\Services\GenerationService;
use App\Services\OutboxService;
use App\Services\ProviderConfigurationService;
use App\Services\RollingPlanService;
use App\Services\StructureService;
use App\Support\ConfigurationExamples;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** 真实 PostgreSQL 冻结计划与虚构离线模型，正文仅作协议测试资料。 */
final class DraftingFixture
{
    public static function create(bool $twoScenes = false, ?\Closure $prepare = null): array
    {
        $provider = ProviderFixture::create();
        $f = RollingPlanFixture::create(owner: $provider['actor']);
        $f['novel'] = $f['book']['novel']['id'];
        if ($twoScenes) {
            // 在计划冻结前由作者细化两场景预算，不能事后放宽测试门禁。
            $structure = fn ($d) => app(StructureService::class)->execute($f['actor'], $f['novel'], 'node', $d + ['expected_version' => Novel::find($f['novel'])->row_version, 'reason' => '两场景验收'], (string) Str::uuid());
            $row = $f['scene']->toArray();
            $row['details'] = array_replace($row['details'], ['min_words' => 1000, 'target_words' => 1250, 'max_words' => 1500]);
            $structure(array_replace($row, ['type' => 'scene', 'node_id' => $row['id']]));
            unset($row['id']);
            $f['second'] = $structure(array_replace($row, ['type' => 'scene', 'sequence' => 2, 'title' => '册页的日期', 'objective' => '比对册页日期并决定公开证据']))['node'];
        }
        // E03可在计划冻结前准备作者批准设定，不在冻结后放宽测试来源门禁。
        if ($prepare) {
            $f = $prepare($f);
        }
        $cmd = fn ($action, $d) => app(RollingPlanService::class)->execute($f['actor'], $f['novel'], $action, $d + ['expected_version' => Novel::find($f['novel'])->row_version, 'reason' => '正文验收计划'], (string) Str::uuid());
        $f['plan'] = $cmd('save', $f['plan_payload'])['plan'];
        $check = $cmd('validate', ['rolling_plan_id' => $f['plan']['id']])['validation'];
        $cmd('freeze', ['rolling_plan_id' => $f['plan']['id'], 'validation_id' => $check['id'], 'report_hash' => $check['report_hash']]);
        $m = $provider['model_payload'];
        $m['provider_model_id'] = 'draft-offline-fixture';
        $m['declared_capabilities']['context_tokens'] = 1000000;
        $m['declared_capabilities']['max_output_tokens'] = 10000;
        $model = app(ProviderConfigurationService::class)->execute($f['actor'], 'model', $m, 'draft-model')['version_id'];
        $price = BillingFixture::price($f['actor'], $model, 'draft-price');
        $release = function ($kind, $d) use ($f) {
            $s = app(AuthoringConfigurationService::class);
            $v = $s->execute($f['actor'], $kind, 'save', $d + ['key' => 'draft.'.$kind, 'stage' => 'drafting.scene', 'language' => 'zh-CN', 'expected_version' => 0, 'reason' => '离线正文验收'], (string) Str::uuid());
            $s->execute($f['actor'], $kind, 'test', ['version_id' => $v['id']], (string) Str::uuid());

            return $s->execute($f['actor'], $kind, 'publish', ['version_id' => $v['id']], (string) Str::uuid());
        };
        $prompt = $release('prompt', ['content' => ['purpose' => '离线场景正文协议验收', 'body' => '遵守冻结资料，只输出完整正文：{{json:context}}', 'variable_schema' => PlanningContract::object(['context' => ['type' => 'string', 'maxLength' => 100000]]), 'output_schema' => BodyContract::schema(), 'capability_requirements' => ['structured_output' => true], 'examples' => [['variables' => ['context' => '铜钥匙缺口'], 'output' => ['body' => '许砚收起铜钥匙。'], 'required_facts' => []]]]]);
        $style = $release('style', ['content' => ConfigurationExamples::all()['style']['content']]);
        $f['route'] = $release('route', ['content' => ['prompt_version_id' => $prompt['id'], 'style_version_id' => $style['id'], 'primary_model_version_id' => $model, 'fallbacks' => [], 'parameters' => ['max_output_tokens' => 1000], 'mode' => 'offline_fake']])['id'];
        $f['billing'] = array_replace($provider['payload']['billing'], ['price_version_ids' => [$price['id']], 'category' => 'generation', 'chapter_id' => $f['chapter']->id, 'max_output_tokens' => 1000]);

        return $f;
    }

    public static function body(): string
    {
        // 足量确定性段落用于上下限和换行测试，不声称经过文学质量验收。
        $paragraphs = [];
        for ($i = 1; $i <= 50; $i++) {
            $paragraphs[] = '第'.$i.'处登记旁，许砚停下脚步，借着窗边的光核对纸上的日期。他把铜钥匙放在桌沿，细看缺口留下的痕迹，再将发现记入册中。';
        }

        return implode("\n", $paragraphs);
    }

    public static function payload(array $f, ?string $body = null): array
    {
        return ['plan_id' => $f['plan']['id'], 'scene_id' => $f['scene']->id, 'pov_id' => $f['person']['entity']['id'], 'route_version_id' => $f['route'], 'billing' => $f['billing'], 'offline_output' => ['body' => $body ?? self::body()], 'expected_version' => Novel::find($f['novel'])->row_version, 'expected_candidate_version' => 0, 'reason' => '明确离线正文回放'];
    }

    public static function generate(array $f, ?string $body = null): array
    {
        $run = app(DraftingService::class)->start($f['actor'], $f['novel'], self::payload($f, $body), (string) Str::uuid());
        app(OutboxService::class)->consume((string) DB::table('outbox_events')->where('run_id', $run['run_id'])->value('id'));
        $row = DB::table('scene_versions')->where('run_id', $run['run_id'])->first();
        if (! $row) {
            throw new \RuntimeException(json_encode(app(GenerationService::class)->read($f['actor'], $f['novel'], $run['run_id'])));
        }

        return app(DraftingService::class)->read($f['actor'], $f['novel'], 'scene', (string) $row->id);
    }
}
