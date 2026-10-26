<?php

namespace Tests\Feature;

use App\Exceptions\NovelProblem;
use App\Filament\Pages\CanonicalCommitCenter;
use App\Models\User;
use App\Services\CanonicalCommitService;
use App\Services\DraftingService;
use App\Services\ExtractionService;
use App\Services\ForeshadowLedgerService;
use App\Services\OutboxService;
use App\Services\WorldService;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\CanonicalFixture as F;
use Tests\Support\DraftingFixture;
use Tests\Support\ExtractionFixture;
use Tests\Support\ReviewFixture;
use Tests\TestCase;

/** 正式图谱以真实PostgreSQL约束和完整离线候选链验证。 */
class CanonicalCommitTest extends TestCase
{
    use RefreshDatabase;

    public function test_commit_is_atomic_and_idempotent_with_formal_evidence_and_pending_outbox(): void
    {
        $f = F::create();
        $proof = F::proof($f);
        $this->assertSame('ready', $proof['status']);
        $d = $f['payload'] + ['validation_artifact_id' => $proof['id']];
        $s = app(CanonicalCommitService::class);
        $r = $s->commit($f['actor'], $f['novel'], $d, 'same');
        $again = $s->commit($f['actor'], $f['novel'], $d, 'same');
        $this->assertSame($r['id'], $again['id']);
        $this->assertTrue($again['replayed']);
        $this->assertDatabaseCount('canonical_commits', 1);
        $this->assertDatabaseCount('canonical_chapters', 1);
        $this->assertDatabaseCount('canonical_outbox', 1);
        $this->assertDatabaseCount('story_events', 1);
        $this->assertDatabaseCount('foreshadow_occurrences', 1);
        $this->assertSame(1, DB::table('novels')->where('id', $f['novel'])->value('state_version'));
        $this->assertSame('planted', DB::table('canonical_foreshadow_states')->value('status'));
        $this->assertSame($f['body']['id'], (string) DB::table('chapters')->where('id', $f['body']['node_id'])->value('current_canonical_version_id'));
        $state = $s->state($f['actor'], $f['novel']);
        $this->assertTrue($state['canonical']);
        $this->assertSame('formal-state-v1', $state['state']['schema']);
        DB::statement('SET CONSTRAINTS e06_commit_complete IMMEDIATE');
        Http::assertNothingSent();
    }

    /** 前章候选不能充当正式游标；到期伏笔必须有真正的正文证据。 */
    public function test_two_chapters_require_formal_prefix_and_due_foreshadow_and_replay_history(): void
    {
        $f = F::create(false, true);
        $first = F::commit($f, 'first');
        $this->assertDatabaseCount('canonical_knowledge_facts', 1);
        $this->assertDatabaseCount('canonical_relationships', 1);
        $this->assertFalse(app(ExtractionService::class)->read($f['actor'], $f['novel'], $f['extraction']['id'])['current_eligible']);
        $this->assertTrue(app(ExtractionService::class)->baseline($f['actor'], $f['novel'], $f['extraction']['id'])['baseline_eligible']);
        $next = F::nextChapter($f);
        $p = F::proof($next);
        $this->assertSame('blocked', $p['status']);
        $this->assertSame('REQUIRED_FORESHADOW_MISSING', $p['code']);
        $output = ExtractionFixture::output([ExtractionFixture::event($next, changes: ['value' => '继续核对封记', 'time' => ['kind' => 'exact', 'clock' => 'relative_seconds', 'start' => 10, 'end' => 10]])]);
        $output['foreshadows'] = [['plan_id' => $f['foreshadow']['id'], 'event_ref' => 'local:observe', 'kind' => 'plant']];
        $next['extraction'] = ExtractionFixture::extract($next, $output, ['baseline_id' => $f['extraction']['id']]);
        $next['review'] = ReviewFixture::review($next, extra: ['expected_state_version' => 1]);
        $next['payload'] = F::payload($next);
        $second = F::commit($next, 'second');
        $s = app(CanonicalCommitService::class);
        $this->assertSame(2, $second['new_state_version']);
        $this->assertSame(2, DB::table('canonical_revisions')->where('novel_id', $f['novel'])->value('cursor'));
        $this->assertSame($first['id'], $s->replay($f['actor'], $f['novel'], $f['revision'], 1)['id']);
        $this->assertCount(2, $s->replay($f['actor'], $f['novel'], $f['revision'], 2)['formal_event_ids']);
        $ledger = app(ForeshadowLedgerService::class)->ledger($f['actor'], $f['novel']);
        $plan = collect($ledger['items'])->firstWhere('id', $f['foreshadow']['id']);
        $this->assertSame('planted', $plan['formal_status']);
        $this->assertSame(2, $plan['first_actual_plant']);
        $this->assertSame($first['chapter_version_id'], (string) DB::table('canonical_chapters')->where('commit_id', $first['id'])->value('chapter_version_id'));
        DB::statement('SET CONSTRAINTS e06_commit_complete IMMEDIATE');
        Http::assertNothingSent();
    }

    public function test_faults_at_every_material_write_roll_back_all_formal_data_and_recover(): void
    {
        $f = F::create(true, true);
        $proof = F::proof($f);
        $this->assertSame('ready', $proof['status']);
        $d = $f['payload'] + ['validation_artifact_id' => $proof['id']];
        $s = app(CanonicalCommitService::class);
        $audit = DB::table('audit_events')->count();
        DB::unprepared("CREATE OR REPLACE FUNCTION pg_temp.e06_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'E06 injected transaction fault'; END $$;");
        foreach (['story_events', 'canonical_chapters', 'state_snapshots', 'entity_state_versions', 'canonical_knowledge_facts', 'canonical_relationships', 'foreshadow_occurrences', 'canonical_foreshadow_states', 'chapters', 'canonical_revisions', 'novels', 'canonical_outbox', 'canonical_commits'] as $table) {
            $operation = in_array($table, ['canonical_commits', 'chapters', 'canonical_revisions', 'novels'], true) ? 'UPDATE' : 'INSERT';
            DB::statement("CREATE TRIGGER zz_e06_fault BEFORE $operation ON $table FOR EACH ROW EXECUTE FUNCTION pg_temp.e06_fault()");
            try {
                $s->commit($f['actor'], $f['novel'], $d, 'fault');
                $this->fail('故障必须回滚');
            } catch (QueryException $e) {
                $this->assertStringContainsString('E06 injected transaction fault', $e->getMessage());
            } finally {
                DB::statement("DROP TRIGGER zz_e06_fault ON $table");
            }
            foreach (['canonical_commits', 'canonical_chapters', 'story_events', 'canonical_knowledge_facts', 'canonical_relationships', 'foreshadow_occurrences', 'canonical_foreshadow_states', 'canonical_outbox'] as $target) {
                $this->assertDatabaseCount($target, 0);
            }
            $this->assertSame(0, DB::table('state_snapshots')->where('scope', 'formal')->count());
            $this->assertSame(0, DB::table('novels')->where('id', $f['novel'])->value('state_version'));
            $this->assertNull(DB::table('chapters')->where('id', $f['body']['node_id'])->value('current_canonical_version_id'));
            $this->assertSame(0, DB::table('canonical_revisions')->value('cursor'));
            $this->assertSame($audit, DB::table('audit_events')->count());
        }
        $r = $s->commit($f['actor'], $f['novel'], $d, 'fault');
        $this->assertSame(1, $r['new_state_version']);
        DB::statement('SET CONSTRAINTS e06_commit_complete IMMEDIATE');
        Http::assertNothingSent();
    }

    public function test_modified_body_and_manual_pass_cannot_bypass_current_source_gates(): void
    {
        $f = F::create();
        $proof = F::proof($f);
        $s = app(CanonicalCommitService::class);
        $edited = app(DraftingService::class)->save($f['actor'], $f['novel'], 'chapter', $f['body']['node_id'], ['source_version_id' => $f['body']['id'], 'expected_version' => 1, 'plan_id' => $f['plan']['id'], 'title' => $f['body']['title'], 'body' => $f['body']['body'].'他合上册页。', 'reason' => '修改一处正文'], 'edit-after-review');
        $p = F::proof($f);
        $this->assertSame('REVIEW_STALE', $p['code']);
        $changed = F::proof($f, array_replace($f['payload'], ['chapter_version_id' => $edited['id']]));
        $this->assertSame('REVIEW_STALE', $changed['code']);
        try {
            $s->commit($f['actor'], $f['novel'], $f['payload'] + ['validation_artifact_id' => $proof['id']], 'old-review');
            $this->fail('旧评审不能提交');
        } catch (NovelProblem $e) {
            $this->assertSame('REVIEW_STALE', $e->errorCode);
        }
        $this->assertDatabaseCount('canonical_commits', 0);
    }

    public function test_manual_high_scores_do_not_waive_blocked_extraction_or_unapproved_entities(): void
    {
        $f = F::create();
        $bad = ExtractionFixture::output([ExtractionFixture::event($f, changes: ['confidence' => 'uncertain'])]);
        $f['extraction'] = ExtractionFixture::extract($f, $bad);
        $f['review'] = ReviewFixture::review($f, ReviewFixture::output($f, 5), ['reviewer_type' => 'human']);
        $f['payload'] = F::payload($f);
        $p = F::proof($f);
        $this->assertSame('blocked', $p['status']);
        $this->assertSame('EXTRACTION_NOT_ELIGIBLE', $p['code']);
        app(WorldService::class)->execute($f['actor'], $f['novel'], 'retire', ['entity_id' => $f['person']['entity']['id'], 'expected_version' => DB::table('novels')->where('id', $f['novel'])->value('row_version'), 'reason' => '批准资格失效测试'], 'retire-core');
        $p = F::proof($f);
        $this->assertSame('UNAPPROVED_ENTITY', $p['code']);
        $this->assertStringContainsString('许砚', $p['message']);
        $this->assertStringContainsString('码点', $p['message']);
        $this->assertDatabaseCount('canonical_commits', 0);
    }

    /** 关键人物须先明确批准，再以新来源重取证复评；旧阻塞证据永久保留。 */
    public function test_unapproved_new_character_blocks_then_approval_and_fresh_sources_allow_commit(): void
    {
        $f = F::create();
        $drafts = app(DraftingService::class);
        $f['body'] = $drafts->save($f['actor'], $f['novel'], 'chapter', $f['body']['node_id'], ['source_version_id' => $f['body']['id'], 'expected_version' => 1, 'plan_id' => $f['plan']['id'], 'title' => $f['body']['title'], 'body' => $f['body']['body'].'未批守门人出现。', 'reason' => '新增关键人物的负例正文'], (string) Str::uuid());
        $f['extraction'] = ExtractionFixture::extract($f, ExtractionFixture::output([ExtractionFixture::event($f)]));
        $f['review'] = ReviewFixture::review($f, ReviewFixture::output($f, 5), ['reviewer_type' => 'human']);
        $f['payload'] = F::payload($f);
        $blocked = F::proof($f);
        $this->assertSame('UNAPPROVED_ENTITY', $blocked['code']);
        $this->assertStringContainsString('未批守门人', $blocked['message']);
        $this->assertDatabaseCount('canonical_commits', 0);
        app(WorldService::class)->execute($f['actor'], $f['novel'], 'approve', ['definition_version_id' => $f['unapproved'][0]['id'], 'expected_version' => DB::table('novels')->where('id', $f['novel'])->value('row_version'), 'reason' => '作者明确批准新关键人物'], (string) Str::uuid());
        // 新运行以批准后的当前世界取源，不重绑旧场景、失败提取或旧高分。
        $d = DraftingFixture::payload($f, $f['body']['body']);
        $d['expected_candidate_version'] = DB::table('scene_versions')->where('node_id', $f['scene']->id)->max('version');
        $run = $drafts->start($f['actor'], $f['novel'], $d, (string) Str::uuid());
        app(OutboxService::class)->consume((string) DB::table('outbox_events')->where('run_id', $run['run_id'])->value('id'));
        $scene = (string) DB::table('scene_versions')->where('run_id', $run['run_id'])->value('id');
        $f['body'] = $drafts->assemble($f['actor'], $f['novel'], $f['body']['node_id'], ['plan_id' => $f['plan']['id'], 'scene_version_ids' => [$scene], 'expected_version' => $f['body']['version'], 'title' => $f['body']['title'], 'reason' => '批准后新来源完整组章'], (string) Str::uuid());
        $events = [ExtractionFixture::event($f), ExtractionFixture::event($f, 'gatekeeper', ['definition_version_id' => $f['unapproved'][0]['id'], 'attribute' => 'action', 'value' => '出现'], '未批守门人出现')];
        $f['extraction'] = ExtractionFixture::extract($f, ExtractionFixture::output($events));
        $f['review'] = ReviewFixture::review($f, extra: ['expected_version' => DB::table('novels')->where('id', $f['novel'])->value('row_version')]);
        $f['payload'] = F::payload($f);
        $r = F::commit($f);
        $this->assertSame(1, $r['new_state_version']);
        $this->assertSame('UNAPPROVED_ENTITY', json_decode(DB::table('canonical_preflights')->find($blocked['id'])->report, true)['code']);
        $this->assertDatabaseCount('story_events', 2);
        DB::statement('SET CONSTRAINTS e06_commit_complete IMMEDIATE');
        Http::assertNothingSent();
    }

    public function test_preflight_identity_and_database_scope_guards_and_chinese_comments(): void
    {
        $f = F::create();
        $s = app(CanonicalCommitService::class);
        $proof = F::proof($f);
        $d = $f['payload'] + ['validation_artifact_id' => $proof['id']];
        try {
            $s->commit($f['actor'], $f['novel'], array_replace($d, ['reason' => '不同载荷']), 'different-proof');
            $this->fail('不能换预检载荷');
        } catch (NovelProblem $e) {
            $this->assertSame('INVALID_PREFLIGHT', $e->errorCode);
        }
        $r = $s->commit($f['actor'], $f['novel'], $d, 'same');
        try {
            $s->commit($f['actor'], $f['novel'], array_replace($d, ['reason' => '同键异内容']), 'same');
            $this->fail('同键异内容必须拒绝');
        } catch (NovelProblem $e) {
            $this->assertSame('IDEMPOTENCY_CONFLICT', $e->errorCode);
        }
        try {
            $s->commit($f['actor'], $f['novel'], $d, 'competing');
            $this->fail('过期状态必须拒绝');
        } catch (NovelProblem $e) {
            $this->assertSame('STALE_STATE', $e->errorCode);
        }
        foreach (['canonical_commits', 'canonical_preflights', 'canonical_chapters', 'story_events', 'state_snapshots', 'canonical_foreshadow_states', 'canonical_outbox'] as $table) {
            try {
                DB::transaction(fn () => DB::table($table)->where('id', $table === 'state_snapshots' ? $r['snapshot_id'] : DB::table($table)->value('id'))->delete());
                $this->fail('正式历史不可删除');
            } catch (QueryException $e) {
                $this->assertNotEmpty($e->errorInfo);
            }
        }
        foreach (['canonical_revisions', 'canonical_preflights', 'canonical_commits', 'canonical_chapters', 'canonical_knowledge_facts', 'canonical_relationships', 'canonical_foreshadow_states', 'canonical_outbox'] as $table) {
            $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', DB::selectOne('SELECT obj_description(?::regclass) AS c', [$table])->c);
            foreach (DB::select('SELECT col_description(attrelid,attnum) AS c FROM pg_attribute WHERE attrelid=?::regclass AND attnum>0 AND NOT attisdropped', [$table]) as $col) {
                $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $col->c);
            }
        }
        $this->assertTrue(DB::table('audit_events')->where('object_type', 'canonical_commits')->where('outcome', 'committed')->exists());
        $this->assertTrue($s->replay($f['actor'], $f['novel'])['replayed_from_events']);
        $this->assertDatabaseCount('canonical_outbox', 1);
    }

    /** 从真实候选载入到预检和重复提交，验证Livewire动作而不仅是页面渲染。 */
    public function test_filament_actions_load_preflight_submit_and_filter_real_commit_history(): void
    {
        $f = F::create();
        $this->actingAs($f['actor']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::test(CanonicalCommitCenter::class, ['novel' => $f['novel']])
            ->set('candidate', $f['body']['id'])->call('loadCandidate')
            ->assertSet('form.review_id', $f['review']['id'])
            ->call('submit')->assertSet('notice', '请先完成本次预检。')
            ->set('form.reason', '作者验证完整提交动作')->call('check')
            ->assertSet('preflight.status', 'ready')->call('submit')
            ->assertSet('receipt.canonical', true)->call('submit')->assertSet('receipt.replayed', true)
            ->call('initialize')->assertSet('notice', '当前正式基线已存在，保留既有正式历史。')
            ->set('statusFilter', 'committed')->assertSee('正式提交历史')
            ->call('older')->assertSet('page', 1)->set('page', 2)
            ->set('statusFilter', 'ready')->assertSet('page', 1);
        $s = app(CanonicalCommitService::class);
        $ready = $s->listing($f['actor'], $f['novel'], ['status' => 'ready']);
        $this->assertSame(0, $ready['commits_total']);
        $this->assertSame(1, $ready['preflights_total']);
        $this->assertNull($ready['next_page']);
        $this->assertDatabaseCount('canonical_commits', 1);
        $this->assertDatabaseCount('canonical_outbox', 1);
        Http::assertNothingSent();
    }

    public function test_api_and_filament_preflight_commit_and_formal_state_are_same_service(): void
    {
        $f = F::create();
        $url = '/api/v1/chapters/'.$f['body']['node_id'].'/commits';
        $this->postJson($url.'/preflight', $f['payload'])->assertUnauthorized();
        $this->actingAs(User::factory()->create(['is_admin' => false]))->getJson('/api/v1/novels/'.$f['novel'].'/canonical')->assertForbidden();
        $this->actingAs($f['actor']);
        $this->withHeader('Idempotency-Key', 'unknown-field')->postJson($url.'/preflight', $f['payload'] + ['force' => true])->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'overflow-id')->postJson($url.'/preflight', array_replace($f['payload'], ['review_id' => '9999999999999999999']))->assertUnprocessable();
        $p = $this->withHeader('Idempotency-Key', 'api-preflight')->postJson($url.'/preflight', $f['payload'])->assertCreated()->assertJsonPath('data.status', 'ready')->json('data');
        $d = $f['payload'] + ['validation_artifact_id' => $p['id']];
        $r = $this->withHeader('Idempotency-Key', 'api-commit')->postJson($url, $d)->assertCreated()->assertJsonPath('data.canonical', true)->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $this->postJson($url, $d)->assertOk()->assertJsonPath('data.id', $r['id'])->assertJsonPath('data.replayed', true);
        $this->getJson('/api/v1/novels/'.$f['novel'].'/state/replay?chapter=1')->assertOk()->assertJsonPath('data.replayed_from_events', true);
        $this->getJson('/api/v1/novels/'.$f['novel'].'/state?chapter=2')->assertNotFound();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(CanonicalCommitCenter::class)->assertSee('请选择小说');
        Livewire::test(CanonicalCommitCenter::class, ['novel' => $f['novel']])->assertSee('正式提交历史')->set('errorFilter', 'REVIEW_STALE')->call('inspect', $r['id'])->assertSet('receipt.id', $r['id']);
        $this->assertTrue(app(DraftingService::class)->read($f['actor'], $f['novel'], 'chapter', $f['body']['id'])['formal'] !== null);
        Http::assertNothingSent();
    }
}
