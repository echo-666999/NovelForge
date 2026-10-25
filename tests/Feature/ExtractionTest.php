<?php

namespace Tests\Feature;

use App\Exceptions\NovelProblem;
use App\Filament\Pages\ExtractionLedger;
use App\Models\Chapter;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\User;
use App\Services\DraftingService;
use App\Services\ExtractionService;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\DraftingFixture;
use Tests\Support\ExtractionFixture as F;
use Tests\TestCase;

/** E03 全部使用真实隔离PostgreSQL与E02正文，绝不访问模型或写正式状态。 */
class ExtractionTest extends TestCase
{
    use RefreshDatabase;

    public function test_actual_body_source_events_patches_and_idempotency_leave_formal_state_unchanged(): void
    {
        $f = F::create('😀甲许砚检查封记。');
        $output = F::output([F::event($f)]);
        $a = F::extract($f, $output, key: 'same-extraction');
        $this->assertTrue($a['current_eligible'], json_encode($a['report']));
        $this->assertSame($a['body_hash'], $f['body']['body_hash']);
        $this->assertCount(1, $a['events']);
        $this->assertCount(1, $a['patches']);
        $this->assertSame(2, $a['events'][0]['evidence']['start_offset']);
        $this->assertEquals($a, F::extract($f, $output, key: 'same-extraction'));
        $this->assertDatabaseCount('extraction_attempts', 1);
        $this->assertDatabaseCount('story_events', 0);
        $this->assertSame(0, Novel::find($f['novel'])->state_version);
        $this->assertSame(1, DB::table('ai_requests')->where('novel_id', $f['novel'])->count());
        $this->assertSame('chapter', DB::table('source_documents')->where('id', $a['source_id'])->value('kind'));
        Http::assertNothingSent();
    }

    public function test_forged_quote_offset_and_unapproved_entity_block_with_location_then_reextract_same_body(): void
    {
        $f = F::create();
        $bad = F::event($f);
        $bad['evidence']['quote'] = '捏造原文';
        $a = F::extract($f, F::output([$bad]));
        $this->assertFalse($a['current_eligible']);
        $this->assertSame('INVALID_EVIDENCE', $a['report']['issues'][0]['code']);
        $this->assertSame('observe', $a['report']['issues'][0]['diagnostic']['event_key']);
        $this->assertDatabaseCount('extraction_events', 0);
        $this->assertDatabaseCount('proposed_events', 0);
        $bad = F::event($f);
        $bad['evidence']['start_offset']++;
        $this->assertSame('INVALID_EVIDENCE', F::extract($f, F::output([$bad]))['report']['issues'][0]['code']);
        $bad = F::event($f, changes: ['definition_version_id' => $f['unapproved'][0]['id']]);
        $this->assertSame('UNAPPROVED_ENTITY', F::extract($f, F::output([$bad]))['report']['issues'][0]['code']);
        $fixed = F::extract($f, F::output([F::event($f)]), ['parent_id' => $a['id']]);
        $this->assertTrue($fixed['current_eligible']);
        $this->assertSame($a['chapter_version_id'], $fixed['chapter_version_id']);
        $this->assertDatabaseCount('chapter_versions', 1);
        $this->assertDatabaseCount('scene_versions', 1);
    }

    public function test_dead_action_unknown_time_and_legal_flashback_have_distinct_results(): void
    {
        $f = F::create('许砚死亡。此前他检查封记。');
        $death = F::event($f, 'death', ['attribute' => 'life_status', 'value' => 'dead', 'time' => ['kind' => 'exact', 'clock' => 'relative_seconds', 'start' => 0, 'end' => 0]]);
        $action = F::event($f, 'action');
        $a = F::extract($f, F::output([$death, $action]));
        $this->assertContains('ACTION_AFTER_DEATH', array_column($a['report']['issues'], 'code'));
        $this->assertCount(2, $a['report']['issues'][0]['evidence']);
        $unknown = F::extract($f, F::output([F::event($f, changes: ['time' => ['kind' => 'unknown']])]));
        $this->assertSame('ambiguous', $unknown['display_status']);
        $this->assertFalse($unknown['current_eligible']);
        $action['time'] = ['kind' => 'exact', 'clock' => 'relative_seconds', 'start' => -10, 'end' => -10];
        $flashback = F::extract($f, F::output([$death, $action]));
        $this->assertTrue($flashback['current_eligible'], json_encode($flashback['report']));
    }

    public function test_secret_use_requires_acquisition_before_this_sentence_even_with_later_same_chapter_knowledge(): void
    {
        $f = F::create('秘密是钥匙藏在旧钟里。许砚先获知秘密。许砚说钥匙藏在旧钟里。同事随后告知。');
        $secret = F::event($f, 'secret', ['fact_kind' => 'hidden_truth', 'attribute' => 'secret', 'value' => '钥匙藏在旧钟里', 'time' => ['kind' => 'exact', 'clock' => 'relative_seconds', 'start' => 0, 'end' => 0]], '钥匙藏在旧钟里');
        $speech = F::event($f, 'speech', ['attribute' => 'speech', 'value' => '钥匙藏在旧钟里', 'uses' => [['fact_ref' => 'local:secret', 'knower_id' => $f['person']['entity']['id']]]], '许砚说钥匙藏在旧钟里');
        $acquire = F::event($f, 'acquire', ['attribute' => 'learn', 'time' => ['kind' => 'exact', 'clock' => 'relative_seconds', 'start' => 2, 'end' => 2]], '同事随后告知');
        $p = F::output([$secret, $speech, $acquire]);
        $p['knowledge'][] = ['knower_id' => $f['person']['entity']['id'], 'fact_ref' => 'local:secret', 'acquired_ref' => 'local:acquire', 'method' => 'witness'];
        $bad = F::extract($f, $p);
        $this->assertContains('SECRET_KNOWLEDGE_VIOLATION', array_column($bad['report']['issues'], 'code'));
        $p['events'] = [$secret, $acquire, array_replace($speech, ['time' => ['kind' => 'exact', 'clock' => 'relative_seconds', 'start' => 3, 'end' => 3]])];
        $reordered = F::extract($f, $p);
        $this->assertContains('SECRET_KNOWLEDGE_VIOLATION', array_column($reordered['report']['issues'], 'code'));
        $p['events'][1] = F::event($f, 'acquire', ['attribute' => 'learn', 'time' => ['kind' => 'exact', 'clock' => 'relative_seconds', 'start' => 2, 'end' => 2]], '许砚先获知秘密');
        $good = F::extract($f, $p, ['parent_id' => $bad['id']]);
        $this->assertTrue($good['current_eligible'], json_encode($good['report']));
        $p['knowledge'][0]['method'] = 'inference';
        $inference = F::extract($f, $p);
        $this->assertContains('SECRET_INFERENCE_AS_FACT', array_column($inference['report']['issues'], 'code'));
        $p['knowledge'][0]['method'] = 'witness';
        $p['events'][2]['uses'] = [];
        $undeclared = F::extract($f, $p);
        $this->assertContains('UNDECLARED_SECRET_USE', array_column($undeclared['report']['issues'], 'code'));
    }

    public function test_item_ability_travel_and_model_supplied_rules_are_blocked(): void
    {
        $f = F::create();
        $owner = F::event($f, 'owner_a', ['definition_version_id' => $f['item'][0]['id'], 'attribute' => 'owner_id', 'value' => $f['person']['entity']['id']]);
        $other = array_replace($owner, ['key' => 'owner_b', 'value' => $f['character'][0]['entity_id']]);
        $a = F::extract($f, F::output([$owner, $other]));
        $this->assertContains('DOUBLE_OWNERSHIP', array_column($a['report']['issues'], 'code'));
        $context = F::rules($f);
        $use = F::event($f, changes: ['ability_id' => $f['ability'][0]['entity_id'], 'ability_units' => 9]);
        $a = F::extract($f, F::output([$use]), ['rule_context_id' => $context]);
        $this->assertContains('ABILITY_EXCEEDED', array_column($a['report']['issues'], 'code'));
        $locA = F::event($f, 'loc_a', ['attribute' => 'location_id', 'value' => $f['location'][0]['entity_id'], 'time' => ['kind' => 'exact', 'clock' => 'relative_seconds', 'start' => 0, 'end' => 0]]);
        $locB = F::event($f, 'loc_b', ['attribute' => 'location_id', 'value' => $f['location'][1]['entity_id']]);
        $a = F::extract($f, F::output([$locA, $locB]), ['rule_context_id' => $context]);
        $this->assertContains('TRAVEL_TOO_FAST', array_column($a['report']['issues'], 'code'));
        $p = F::output([$use]);
        $p['rules'] = [['limit_value' => 999]];
        $this->assertSame('INVALID_EXTRACTION', F::extract($f, $p)['report']['issues'][0]['code']);
    }

    public function test_foreshadow_candidate_plant_and_early_payoff_do_not_change_formal_ledger(): void
    {
        $f = F::create();
        $p = F::output([F::event($f)]);
        $p['foreshadows'] = [['plan_id' => $f['foreshadow']['id'], 'event_ref' => 'local:observe', 'kind' => 'payoff']];
        $bad = F::extract($f, $p);
        $this->assertContains('FORESHADOW_TRANSITION_INVALID', array_column($bad['report']['issues'], 'code'));
        $p['foreshadows'][0]['kind'] = 'plant';
        $good = F::extract($f, $p, ['parent_id' => $bad['id']]);
        $this->assertTrue($good['current_eligible'], json_encode($good['report']));
        $this->assertCount(1, $good['candidate_foreshadows']);
        $this->assertDatabaseCount('foreshadow_occurrences', 0);
    }

    public function test_body_edit_stales_old_extraction_and_reextract_cannot_bind_new_version(): void
    {
        $f = F::create();
        $a = F::extract($f, F::output([F::event($f)]));
        $old = $f['body'];
        $new = app(DraftingService::class)->save($f['actor'], $f['novel'], 'chapter', $old['node_id'], ['plan_id' => $old['plan_id'], 'source_version_id' => $old['id'], 'expected_version' => 1, 'title' => $old['title'], 'body' => $old['body'].'新证据被收起。', 'reason' => '作者正文编辑'], 'body-edit');
        $this->assertTrue(app(ExtractionService::class)->read($f['actor'], $f['novel'], $a['id'])['stale']);
        $again = F::extract($f, F::output([F::event($f)]));
        $this->assertSame('STALE_EXTRACTION', $again['report']['issues'][0]['code']);
        $f['body'] = $new;
        $this->expectException(NovelProblem::class);
        F::extract($f, F::output([F::event($f)]), ['parent_id' => $a['id']]);
    }

    public function test_prior_chapter_baseline_cannot_be_omitted_and_supersession_stales_descendants(): void
    {
        $f = F::create();
        $death = F::event($f, 'death', ['attribute' => 'life_status', 'value' => 'dead', 'time' => ['kind' => 'exact', 'clock' => 'relative_seconds', 'start' => 0, 'end' => 0]]);
        $first = F::extract($f, F::output([$death]));
        $this->assertTrue($first['current_eligible']);
        $next = $f;
        $next['chapter'] = Chapter::where('novel_id', $f['novel'])->where('sequence', 2)->first();
        $next['scene'] = Scene::where('parent_id', $next['chapter']->id)->first();
        $next['billing']['chapter_id'] = $next['chapter']->id;
        $scene = DraftingFixture::generate($next);
        $next['body'] = app(DraftingService::class)->assemble($f['actor'], $f['novel'], $next['chapter']->id, ['plan_id' => $f['plan']['id'], 'scene_version_ids' => [$scene['id']], 'expected_version' => 0, 'title' => '旧日期里的行动', 'reason' => '下一章候选'], 'chapter-two');
        $p = F::output([F::event($next)]);
        $missing = F::extract($next, $p);
        $this->assertSame('INVALID_CANDIDATE_BASELINE', $missing['report']['issues'][0]['code']);
        $conflict = F::extract($next, $p, ['baseline_id' => $first['id']]);
        $this->assertContains('ACTION_AFTER_DEATH', array_column($conflict['report']['issues'], 'code'));
        $p['events'][0]['time'] = ['kind' => 'exact', 'clock' => 'relative_seconds', 'start' => -1, 'end' => -1];
        $good = F::extract($next, $p, ['baseline_id' => $first['id']]);
        $this->assertTrue($good['current_eligible'], json_encode($good['report']));
        $this->assertCount(2, $good['candidate_event_ids']);
        F::extract($f, F::output([$death]), ['parent_id' => $first['id']]);
        $this->assertTrue(app(ExtractionService::class)->read($f['actor'], $f['novel'], $good['id'])['stale']);
        $this->assertDatabaseCount('story_events', 0);
    }

    public function test_literal_unapproved_mentions_and_hidden_foreshadow_cannot_be_omitted_from_output(): void
    {
        $f = F::create('未批守门人出现。旧案封记吻合。');
        $a = F::extract($f, F::output([F::event($f)]));
        $codes = array_column($a['report']['issues'], 'code');
        $this->assertContains('UNAPPROVED_ENTITY_MENTION', $codes);
        $this->assertContains('FORESHADOW_EARLY_DISCLOSURE', $codes);
        $this->assertFalse($a['current_eligible']);
    }

    public function test_database_source_hash_scope_and_invalid_characters_are_not_bypassable(): void
    {
        $f = F::create();
        $p = F::output([F::event($f)]);
        $p['events'][0]['value'] = "非法\0状态";
        try {
            F::extract($f, $p);
            $this->fail('零字节应在编码和写库前拒绝');
        } catch (NovelProblem $e) {
            $this->assertSame('INVALID_EXTRACTION_CHARACTER', $e->errorCode);
        }
        $this->assertDatabaseCount('extraction_attempts', 0);
        $a = F::extract($f, F::output([F::event($f)]));
        $other = (string) DB::table('novels')->where('id', '<>', $f['novel'])->value('id');
        $copy = (array) DB::table('source_documents')->find($a['source_id']);
        unset($copy['id']);
        // 唯一来源约束也应拒绝重复，不能靠换小说重用同一正文版本。
        try {
            DB::transaction(fn () => DB::table('source_documents')->insert(array_replace($copy, ['novel_id' => $other])));
            $this->fail('跨书来源必须拒绝');
        } catch (QueryException $e) {
            $this->assertNotEmpty($e->getCode());
        }
        $attempt = (array) DB::table('extraction_attempts')->find($a['id']);
        unset($attempt['id']);
        try {
            DB::transaction(fn () => DB::table('extraction_attempts')->insert(array_replace($attempt, ['body_hash' => str_repeat('0', 64)])));
            $this->fail('数据库应拒绝提取来源哈希伪造');
        } catch (QueryException $e) {
            $this->assertStringContainsString('E03 source mismatch', $e->getMessage());
        }
        $this->assertDatabaseCount('extraction_attempts', 1);
    }

    public function test_http_ui_authorization_compare_reextract_and_database_history_comments(): void
    {
        $f = F::create();
        $p = F::output([F::event($f)]);
        $url = '/api/v1/novels/'.$f['novel'].'/extractions';
        $this->getJson($url)->assertUnauthorized();
        $a = $this->actingAs($f['actor'])->withHeader('Idempotency-Key', 'http-extract')->postJson($url, F::payload($f, $p))->assertCreated()->json('data');
        $this->getJson($url.'/'.$a['id'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson($url, F::payload($f, $p))->assertCreated()->assertJsonPath('data.id', $a['id']);
        $this->postJson($url, F::payload($f, $p, ['reason' => '不同原因']))->assertConflict();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ExtractionLedger::class)->set('novel', $f['novel'])->set('selected', $a['id'])->call('inspect')->assertSet('detail.current_eligible', true)->call('replay')->assertSet('projection.canonical', false)->call('replay', true)->assertSet('projection.scope', 'candidate')->call('prepareReextract')->assertSet('parent', $a['id'])->set('reason', '只修正提取')->call('extract')->assertSee('没有改写正文或提交正式状态');
        $this->assertTrue(app(ExtractionService::class)->read($f['actor'], $f['novel'], $a['id'])['stale']);
        $foreign = (string) DB::table('novels')->where('id', '<>', $f['novel'])->value('id');
        $this->getJson('/api/v1/novels/'.$foreign.'/extractions/'.$a['id'])->assertNotFound();
        $this->actingAs(User::factory()->create(['is_admin' => false]))->getJson($url)->assertForbidden();
        foreach (['extraction_attempts', 'extraction_events', 'extraction_foreshadows'] as $table) {
            $this->assertNotEmpty(DB::selectOne('SELECT obj_description(?::regclass) AS c', [$table])->c);
            $this->assertSame(0, DB::selectOne('SELECT count(*) AS n FROM pg_attribute WHERE attrelid=?::regclass AND attnum>0 AND NOT attisdropped AND col_description(attrelid,attnum) IS NULL', [$table])->n);
        }
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('extraction_attempts')->where('id', $a['id'])->update(['reason' => '非法覆盖']));
    }
}
