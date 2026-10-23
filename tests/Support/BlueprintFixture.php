<?php

namespace Tests\Support;

use App\Models\Novel;
use App\Models\User;
use App\Services\BlueprintService;
use App\Services\NovelIntentService;
use App\Services\StructureService;
use App\Services\WorldService;
use Illuminate\Support\Str;

final class BlueprintFixture
{
    public static function create(?User $actor = null, int $chapterCount = 10, int $targetChapters = 10): array
    {
        $actor ??= User::factory()->create(['is_admin' => true]);
        $intent = app(NovelIntentService::class);
        $book = $intent->execute($actor, 'create', null, ['title' => '蓝图夹具', 'genre' => '悬疑', 'background' => '档案馆不允许无代价复活', 'premise_raw' => '许砚保留铜钥匙缺口作为证据', 'target_chapters' => $targetChapters, 'length_policy' => ['min' => 2000, 'max' => 3000]], (string) Str::uuid());
        $id = $book['novel']['id'];
        $command = function (string $service, string $command, array $data) use ($actor, $id) {
            return app($service)->execute($actor, $id, $command, $data + ['expected_version' => Novel::find($id)->row_version, 'reason' => '测试夹具'], (string) Str::uuid());
        };
        foreach ($intent->requirements(Novel::find($id)) as $req) {
            $intent->execute($actor, 'requirement', $id, array_replace($req, ['strength' => 'hard', 'decision_status' => 'accepted', 'author_decision' => '作者确认测试要求', 'expected_version' => Novel::find($id)->row_version, 'reason' => '确认']), (string) Str::uuid());
        }
        $person = $command(WorldService::class, 'definition', ['type' => 'character', 'name' => '许砚', 'description' => '档案修复师', 'criticality' => 'core', 'source_version_id' => $book['sources'][0]['id'], 'source_quote' => '许砚', 'profile' => ['role' => 'primary', 'identity' => '修复师', 'voice' => '先问证据', 'limitations' => '不能复活'], 'introduced_chapter' => 1, 'affects_main_plot' => true]);
        $command(WorldService::class, 'approve', ['definition_version_id' => $person['definition']['id']]);
        $thread = $command(StructureService::class, 'thread', ['title' => '追查铜钥匙', 'objective' => '公开档案真相'])['thread'];
        $common = ['source_version_id' => $book['sources'][0]['id'], 'sequence' => 1, 'title' => '铜钥匙旧档', 'objective' => '以证据追查真相', 'status' => 'detailed', 'details' => ['pov' => '许砚', 'transition_in' => '承接旧线索', 'transition_out' => '留下新线索', 'target_words' => 2500]];
        $volume = $command(StructureService::class, 'node', $common + ['type' => 'volume', 'start_chapter' => 1, 'end_chapter' => $targetChapters])['node'];
        $arc = $command(StructureService::class, 'node', $common + ['type' => 'story_arc', 'parent_id' => $volume['id'], 'start_chapter' => 1, 'end_chapter' => $targetChapters, 'thread_ids' => [$thread['id']]])['node'];
        for ($i = 1; $i <= $chapterCount; $i++) {
            $chapter = $command(StructureService::class, 'node', array_replace($common, ['type' => 'chapter', 'parent_id' => $arc['id'], 'sequence' => $i, 'title' => '铜钥匙线索推进'.$i]))['node'];
            $command(StructureService::class, 'node', $common + ['type' => 'scene', 'parent_id' => $chapter['id']]);
        }
        $outline = $command(StructureService::class, 'snapshot', [])['version'];
        $requirements = $intent->requirements(Novel::find($id));
        $body = array_fill_keys(BlueprintService::FIELDS, '测试作者填写的具体规划');
        $body['conflicts'] = '';
        $body['synopsis'] = implode("\n", array_column($requirements, 'source_quote'));
        $payload = ['outline_version_id' => $outline['id'], 'body' => $body, 'entity_version_ids' => [$person['definition']['id']],
            'threads' => [['thread_id' => $thread['id'], 'kind' => 'main', 'goal' => '追查证据', 'resolution' => '公开封记比对结果', 'turning_points' => '铜钥匙断口吻合', 'character_arc' => '由逃避转为公开证据']],
            'ending' => ['target_chapters' => $targetChapters, 'start_chapter' => $targetChapters - 1, 'end_chapter' => $targetChapters + 1, 'resolution' => '公开证据解决主冲突', 'final_choice' => '承担公开档案的代价', 'core_reversal' => '封记被调换', 'final_relationships' => '与同事重建信任', 'forbidden_solutions' => '禁止突然获得复活能力', 'evidence_requirements' => '正文中提供证据比对及选择代价', 'outcomes' => [['entity_definition_version_id' => $person['definition']['id'], 'outcome' => '活着承担后果']], 'required_foreshadows' => ['铜钥匙缺口'], 'open_items' => ['馆外旧案']],
            'coverages' => array_map(fn ($req) => ['requirement_version_id' => $req['id'], 'field' => 'body.synopsis', 'quote' => $req['source_quote']], $requirements), 'author_checks' => array_fill_keys(BlueprintService::CHECKS, true)];

        return compact('actor', 'book', 'payload', 'person', 'outline', 'thread');
    }
}
