<?php

namespace Tests\Support;

use App\Models\Chapter;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\StoryArc;
use App\Models\User;
use App\Services\BlueprintService;
use App\Services\NovelIntentService;
use App\Services\RollingPlanService;
use App\Services\StructureService;
use Illuminate\Support\Str;

final class RollingPlanFixture
{
    public static function create(int $targetChapters = 10, ?User $owner = null): array
    {
        $f = BlueprintFixture::create($owner, 10, $targetChapters);
        $actor = $f['actor'];
        $id = $f['book']['novel']['id'];
        $run = function ($service, $cmd, $d) use ($actor, $id) {
            return app($service)->execute($actor, $id, $cmd, $d + ['expected_version' => Novel::find($id)->row_version, 'reason' => '计划夹具'], (string) Str::uuid());
        };
        $reqs = app(NovelIntentService::class)->requirements(Novel::find($id));
        $text = implode("\n", array_column($reqs, 'source_quote'));
        $arc = StoryArc::where('novel_id', $id)->first();
        $run(StructureService::class, 'node', array_replace($arc->toArray(), ['type' => 'story_arc', 'node_id' => $arc->id, 'summary' => $text, 'thread_ids' => [$f['thread']['id']]]));
        for ($i = 11; $i <= $targetChapters; $i++) {
            $run(StructureService::class, 'node', ['type' => 'chapter', 'parent_id' => $arc->id, 'source_version_id' => $arc->source_version_id, 'sequence' => $i, 'title' => '未来线索'.$i, 'objective' => '未来章节骨架目标', 'status' => 'skeleton']);
        }
        $outline = $run(StructureService::class, 'snapshot', [])['version'];
        $f['payload']['outline_version_id'] = $outline['id'];
        $bp = $run(BlueprintService::class, 'save', $f['payload'])['blueprint'];
        $check = $run(BlueprintService::class, 'validate', ['blueprint_version_id' => $bp['id']])['validation'];
        $run(BlueprintService::class, 'approve', ['blueprint_version_id' => $bp['id'], 'validation_id' => $check['id'], 'report_hash' => $check['report_hash'], 'acknowledge_impact' => true]);
        // 蓝图批准之后再细化场景，覆盖正常滚动细化不应使蓝图失效的路径。
        foreach (Chapter::where('novel_id', $id)->where('sequence', '<=', 10)->get() as $ch) {
            $budget = ['min_words' => 2000, 'target_words' => 2500, 'max_words' => 3000];
            $run(StructureService::class, 'node', array_replace($ch->toArray(), ['type' => 'chapter', 'node_id' => $ch->id, 'summary' => $text, 'details' => $budget]));
            $sc = Scene::where('parent_id', $ch->id)->first();
            $details = array_fill_keys(RollingPlanService::SCENE_FIELDS, '作者明确填写的场景信息');
            $details = array_replace($details, $budget, ['pov' => '许砚', 'pov_version_id' => $f['person']['definition']['id']]);
            $run(StructureService::class, 'node', array_replace($sc->toArray(), ['type' => 'scene', 'node_id' => $sc->id, 'summary' => $text, 'details' => $details]));
        }
        $ch = Chapter::where('novel_id', $id)->orderBy('sequence')->first();
        $scene = Scene::where('parent_id', $ch->id)->first();
        $cover = [];
        foreach ($reqs as $r) {
            foreach (['story_arc' => $arc->id, 'chapter' => $ch->id, 'scene' => $scene->id] as $type => $node) {
                $a = mb_strpos($text, $r['source_quote']);
                $cover[] = ['requirement_version_id' => $r['id'], 'type' => $type, 'node_id' => $node, 'field' => 'summary', 'quote' => $r['source_quote'], 'start_offset' => $a, 'end_offset' => $a + mb_strlen($r['source_quote'])];
            }
        }
        $f['plan_payload'] = ['blueprint_version_id' => $bp['id'], 'start_chapter' => 1, 'end_chapter' => 10, 'coverages' => $cover];
        $f['blueprint'] = $bp;
        $f['chapter'] = $ch;
        $f['scene'] = $scene;

        return $f;
    }
}
