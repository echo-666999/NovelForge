<?php

namespace Tests\Support;

use App\Models\Novel;
use App\Services\DraftingService;
use App\Services\ExtractionService;
use App\Services\ForeshadowLedgerService;
use App\Services\StoryConsistencyService;
use App\Services\StoryStateService;
use App\Services\WorldService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** E03 从E02实际章节版本取证，所有正文和响应均为明确离线夹具。 */
final class ExtractionFixture
{
    public static function create(string $prefix = '许砚检查封记。'): array
    {
        $f = DraftingFixture::create(prepare: function ($f) {
            foreach (['character' => ['同事'], 'item' => ['唯一钥匙'], 'location' => ['档案馆前厅', '山城后门'], 'ability' => ['辨认封记'], 'rule' => ['旅行能力规则'], 'unapproved' => ['未批守门人']] as $kind => $names) {
                foreach ($names as $name) {
                    $type = $kind === 'unapproved' ? 'character' : $kind;
                    $d = ['type' => $type, 'name' => $name, 'description' => '作者明确配置的离线设定', 'criticality' => 'core', 'source_version_id' => $f['book']['sources'][0]['id'], 'source_quote' => '许砚', 'introduced_chapter' => 1, 'affects_main_plot' => true, 'expected_version' => Novel::find($f['novel'])->row_version, 'reason' => '提取验证前配置设定'];
                    if ($type === 'character') {
                        $d['profile'] = ['role' => 'supporting', 'identity' => '同事', 'voice' => '克制', 'limitations' => '不能复活'];
                    }
                    if ($type === 'rule') {
                        $d += ['rule' => '能力上限3，两个地点旅行至少600秒；不能无代价复活', 'scope' => '当前小说'];
                    }
                    $v = app(WorldService::class)->execute($f['actor'], $f['novel'], 'definition', $d, (string) Str::uuid())['definition'];
                    $f[$kind][] = $v;
                    if ($kind !== 'unapproved') {
                        app(WorldService::class)->execute($f['actor'], $f['novel'], 'approve', ['definition_version_id' => $v['id'], 'expected_version' => Novel::find($f['novel'])->row_version, 'reason' => '作者批准设定'], (string) Str::uuid());
                    }
                }
            }
            $payload = ['name' => '铜钥匙缺口', 'surface_clue' => '齿口有不规则缺损', 'hidden_meaning' => '旧案封记吻合', 'misdirection' => '旧物磨损', 'no_early_reveal' => '回收窗口前不得揭露来源', 'causal_role' => '公开证据', 'prerequisite_description' => '已有埋设与证据', 'blueprint_version_id' => $f['blueprint']['id'], 'thread_id' => $f['thread']['id'], 'plant_start' => 1, 'plant_end' => 2, 'payoff_target' => 9, 'payoff_start' => 8, 'payoff_end' => 10, 'required' => true, 'importance' => 'major', 'secrecy' => 'author_only', 'reinforcements' => [['start' => 4, 'end' => 5, 'new_information' => '关联旧案', 'choice_impact' => '保留证据']], 'entity_version_ids' => [$f['person']['definition']['id']], 'knower_version_ids' => [$f['person']['definition']['id']], 'prerequisite_event_ids' => []];
            $command = fn ($action, $d) => app(ForeshadowLedgerService::class)->execute($f['actor'], $f['novel'], $action, $d + ['expected_version' => Novel::find($f['novel'])->row_version, 'reason' => '作者伏笔承诺'], (string) Str::uuid());
            $f['foreshadow'] = $command('create', ['plan' => $payload])['plan'];
            $command('freeze', ['plan_id' => $f['foreshadow']['id']]);

            return $f;
        });
        $scene = DraftingFixture::generate($f, $prefix."\n".DraftingFixture::body());
        $f['body'] = app(DraftingService::class)->assemble($f['actor'], $f['novel'], $f['chapter']->id, ['plan_id' => $f['plan']['id'], 'scene_version_ids' => [$scene['id']], 'expected_version' => 0, 'title' => '缺口后的证据', 'reason' => '实际章节正文'], 'extraction-chapter');

        return $f;
    }

    public static function event(array $f, string $key = 'observe', array $changes = [], string $quote = '许砚'): array
    {
        $at = mb_strpos($f['body']['body'], $quote);

        return array_replace(['key' => $key, 'definition_version_id' => $f['person']['definition']['id'], 'fact_kind' => 'fact', 'action' => 'set', 'attribute' => 'action', 'value' => '检查封记', 'confidence' => 'confirmed', 'time' => ['kind' => 'exact', 'clock' => 'relative_seconds', 'start' => 1, 'end' => 1], 'reason' => '离线提取实际原文', 'evidence' => ['source_hash' => $f['body']['body_hash'], 'start_offset' => $at, 'end_offset' => $at + mb_strlen($quote), 'quote' => $quote], 'uses' => []], $changes);
    }

    public static function output(array $events): array
    {
        return ['events' => $events, 'dependencies' => [], 'knowledge' => [], 'relationships' => [], 'foreshadows' => []];
    }

    public static function payload(array $f, array $output, array $extra = []): array
    {
        $book = Novel::find($f['novel']);

        return array_replace(['chapter_version_id' => $f['body']['id'], 'body_hash' => $f['body']['body_hash'], 'expected_version' => $book->row_version, 'expected_state_version' => $book->state_version, 'offline_output' => $output, 'reason' => '显式离线提取验证'], $extra);
    }

    public static function extract(array $f, array $output, array $extra = [], ?string $key = null): array
    {
        return app(ExtractionService::class)->extract($f['actor'], $f['novel'], self::payload($f, $output, $extra), $key ?? (string) Str::uuid());
    }

    public static function rules(array $f): string
    {
        $source = app(StoryStateService::class)->registerChapter($f['actor'], $f['novel'], $f['body']['id']);
        $ev = self::event($f)['evidence'];
        $id = app(StoryStateService::class)->evidence($f['actor'], $f['novel'], $source, $ev['source_hash'], $ev['start_offset'], $ev['end_offset'], $ev['quote']);
        $event = app(StoryStateService::class)->propose($f['actor'], $f['novel'], ['definition_version_id' => $f['person']['definition']['id'], 'evidence_id' => $id, 'fact_kind' => 'fact', 'action' => 'set', 'attribute' => 'action', 'value' => '作者规则上下文锚点', 'confidence' => 'confirmed', 'story_time' => [], 'chapter_sequence' => 1, 'narrative_order' => 9999, 'reason' => '作者明确规则']);
        $rule = $f['rule'][0]['id'];
        $entity = $f['person']['entity']['id'];

        return app(StoryConsistencyService::class)->save($f['actor'], $f['novel'], ['expected_version' => (int) DB::table('story_context_versions')->where('novel_id', $f['novel'])->max('version'), 'reason' => '作者明确批准规则数值，不来自模型响应', 'entries' => [['event_id' => $event, 'time' => ['kind' => 'exact', 'clock' => 'relative_seconds', 'start' => 0, 'end' => 0]]], 'rules' => [['rule_version_id' => $rule, 'kind' => 'ability', 'subject_id' => $entity, 'target_id' => $f['ability'][0]['entity_id'], 'limit_value' => 3], ['rule_version_id' => $rule, 'kind' => 'travel', 'subject_id' => $f['location'][0]['entity_id'], 'target_id' => $f['location'][1]['entity_id'], 'limit_value' => 600]]], 'author-rules')['id'];
    }
}
