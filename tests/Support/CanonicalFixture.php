<?php

namespace Tests\Support;

use App\Services\CanonicalCommitService;
use App\Services\DraftingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** 使用实际候选正文、提取、评审和伏笔证据，不调用真实供应商。 */
final class CanonicalFixture
{
    public static function create(bool $plant = true, bool $relationships = false): array
    {
        Http::preventStrayRequests();
        $f = ReviewFixture::create();
        if ($plant || $relationships) {
            $output = ExtractionFixture::output([ExtractionFixture::event($f)]);
            if ($plant) {
                $output['foreshadows'] = [['plan_id' => $f['foreshadow']['id'], 'event_ref' => 'local:observe', 'kind' => 'plant']];
            }
            if ($relationships) {
                $output['knowledge'] = [['knower_id' => $f['person']['entity']['id'], 'fact_ref' => 'local:observe', 'acquired_ref' => 'local:observe', 'method' => 'witness']];
                $output['relationships'] = [['event_ref' => 'local:observe', 'from_entity_id' => $f['person']['entity']['id'], 'to_entity_id' => $f['character'][0]['entity_id'], 'relation_type' => 'trust', 'state' => '以证据重建互信']];
            }
            $f['extraction'] = ExtractionFixture::extract($f, $output);
        }
        $f['review'] = ReviewFixture::review($f);
        $f['revision'] = app(CanonicalCommitService::class)->initialize($f['actor'], $f['novel'], ['expected_state_version' => 0, 'reason' => '建立空根正式基线'], (string) Str::uuid())['revision_id'];
        $f['payload'] = self::payload($f);

        return $f;
    }

    /** 正式前章之后创建第二章的真实离线正文，保留前章提取作为明确基线。 */
    public static function nextChapter(array $f, bool $plant = false): array
    {
        $n = $f;
        $n['chapter'] = DB::table('chapters')->where('novel_id', $f['novel'])->where('sequence', 2)->first();
        $n['scene'] = DB::table('scenes')->where('novel_id', $f['novel'])->where('parent_id', $n['chapter']->id)->orderBy('sequence')->first();
        $n['billing']['chapter_id'] = (string) $n['chapter']->id;
        $scene = DraftingFixture::generate($n, '许砚继续核对封记。'."\n".DraftingFixture::body());
        $n['body'] = app(DraftingService::class)->assemble($f['actor'], $f['novel'], (string) $n['chapter']->id, ['plan_id' => $f['plan']['id'], 'scene_version_ids' => [$scene['id']], 'expected_version' => 0, 'title' => '封记里的第二处痕迹', 'reason' => '连续章节正式前缀测试'], (string) Str::uuid());
        $output = ExtractionFixture::output([ExtractionFixture::event($n, changes: ['value' => '继续核对封记', 'time' => ['kind' => 'exact', 'clock' => 'relative_seconds', 'start' => 10, 'end' => 10]])]);
        if ($plant) {
            $output['foreshadows'] = [['plan_id' => $f['foreshadow']['id'], 'event_ref' => 'local:observe', 'kind' => 'plant']];
        }
        $n['extraction'] = ExtractionFixture::extract($n, $output, ['baseline_id' => $f['extraction']['id']]);
        $n['review'] = ReviewFixture::review($n, extra: ['expected_state_version' => DB::table('novels')->where('id', $f['novel'])->value('state_version')]);
        $n['payload'] = self::payload($n);

        return $n;
    }

    public static function payload(array $f): array
    {
        return ['novel_id' => $f['novel'], 'chapter_id' => $f['body']['node_id'], 'chapter_version_id' => $f['body']['id'], 'review_id' => $f['review']['id'], 'expected_revision_id' => $f['revision'], 'expected_state_version' => DB::table('novels')->where('id', $f['novel'])->value('state_version'), 'plan_node_version_id' => (string) DB::table('plan_nodes')->where('outline_version_id', $f['plan']['outline_version_id'])->where('chapter_id', $f['body']['node_id'])->where('node_type', 'chapter')->value('id'), 'event_artifact_id' => $f['extraction']['id'], 'reason' => '离线候选的正式提交协议验收'];
    }

    public static function proof(array $f, ?array $payload = null): array
    {
        return app(CanonicalCommitService::class)->preflight($f['actor'], $f['novel'], $payload ?? $f['payload'], (string) Str::uuid());
    }

    public static function commit(array $f, string $key = 'commit'): array
    {
        $p = self::proof($f);
        if ($p['status'] !== 'ready') {
            throw new \RuntimeException(json_encode($p, JSON_UNESCAPED_UNICODE));
        }

        return app(CanonicalCommitService::class)->commit($f['actor'], $f['novel'], $f['payload'] + ['validation_artifact_id' => $p['id']], $key);
    }
}
