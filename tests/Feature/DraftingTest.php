<?php

namespace Tests\Feature;

use App\Drafting\BodyContract;
use App\Drafting\DraftReplayAdapter;
use App\Exceptions\NovelProblem;
use App\Filament\Resources\NovelResource\Pages\DraftEditor;
use App\Models\User;
use App\Services\BillingConfigurationService;
use App\Services\DraftingService;
use App\Services\GenerationService;
use App\Services\OutboxService;
use App\Services\ProviderGateway;
use App\Services\RecoveryService;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\DraftingFixture;
use Tests\TestCase;

/** E02 使用隔离 PostgreSQL，禁止真实网络、正式状态或中文分词。 */
class DraftingTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalization_count_and_rule_findings_do_not_claim_semantic_review(): void
    {
        $raw = " 甲\r\n乙\r\tA\u{200d}😀e\u{0301}。  ";
        $body = BodyContract::normalize($raw);
        $this->assertSame(" 甲\n乙\n\tA\u{200d}😀e\u{0301}。  ", $body);
        $this->assertSame(7, BodyContract::count($body));
        $report = BodyContract::inspect('作为一个AI，此处省略……', '第1章', ['min_words' => 100, 'target_words' => 200, 'max_words' => 300]);
        $this->assertSame(['BELOW_MINIMUM', 'PLACEHOLDER_TITLE', 'PLACEHOLDER_OR_MODEL_NARRATION', 'POSSIBLY_TRUNCATED_ENDING'], $report['issues']);
        $this->assertSame('not_performed', $report['semantic_review']);
        $this->expectException(NovelProblem::class);
        BodyContract::normalize("\xff");
    }

    public function test_offline_scene_generation_and_assembly_are_idempotent_and_keep_formal_state_closed(): void
    {
        $f = DraftingFixture::create();
        $s = app(DraftingService::class);
        $payload = DraftingFixture::payload($f);
        $run = $s->start($f['actor'], $f['novel'], $payload, 'same-start');
        $event = (string) DB::table('outbox_events')->where('run_id', $run['run_id'])->value('id');
        // 非 testing 应用标记也只允许明确离线执行器；数据库仍保持专用测试库。
        app()->detectEnvironment(fn () => 'local');
        try {
            app(OutboxService::class)->consume($event);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
        app(OutboxService::class)->consume($event);
        $this->assertSame($run, $s->start($f['actor'], $f['novel'], $payload, 'same-start'));
        $v = $s->read($f['actor'], $f['novel'], 'scene', (string) DB::table('scene_versions')->value('id'));
        $this->assertTrue($v['report']['complete']);
        $this->assertSame(hash('sha256', DraftingFixture::body()), $v['body_hash']);
        $this->assertSame(1, DB::table('ai_requests')->where('novel_id', $f['novel'])->count());
        $this->assertSame(1, DB::table('scene_versions')->count());
        $frozen = app(GenerationService::class)->read($f['actor'], $f['novel'], $run['run_id'])['run']['frozen_config'];
        $this->assertSame($frozen['context']['final_prompt'], $frozen['configuration']['rendered_prompt']);
        $this->assertStringContainsString('铜钥匙缺口', $frozen['context']['final_prompt']);
        $d = ['plan_id' => $f['plan']['id'], 'scene_version_ids' => [$v['id']], 'expected_version' => 0, 'title' => '钥匙上的缺口', 'reason' => '完整组装'];
        $ch = $s->assemble($f['actor'], $f['novel'], $f['chapter']->id, $d, 'assemble');
        $this->assertSame($ch, $s->assemble($f['actor'], $f['novel'], $f['chapter']->id, $d, 'assemble'));
        $this->assertSame($v['body'], $ch['body']);
        $this->assertSame(1, DB::table('chapter_scene_versions')->count());
        $this->assertSame(0, DB::table('novels')->where('id', $f['novel'])->value('state_version'));
        $this->assertSame(0, DB::table('story_events')->count());
        $this->assertNull($ch['formal']);
        Http::assertNothingSent();
    }

    public function test_editing_creates_hash_lineage_stales_assembly_and_rejects_lost_updates(): void
    {
        $f = DraftingFixture::create();
        $v = DraftingFixture::generate($f);
        $s = app(DraftingService::class);
        $ch = $s->assemble($f['actor'], $f['novel'], $f['chapter']->id, ['plan_id' => $f['plan']['id'], 'scene_version_ids' => [$v['id']], 'expected_version' => 0, 'title' => '册页与钥匙', 'reason' => '组装'], 'chapter');
        $d = ['plan_id' => $f['plan']['id'], 'source_version_id' => $v['id'], 'expected_version' => 1, 'body' => $v['body']."\r\n他合上册页。 ", 'title' => $v['title'], 'reason' => '自动保存候选'];
        $edit = $s->save($f['actor'], $f['novel'], 'scene', $v['node_id'], $d, 'edit');
        $this->assertSame($v['id'], $edit['parent_version_id']);
        $this->assertNotSame($v['body_hash'], $edit['body_hash']);
        $this->assertSame($d['body'], $edit['raw_body']);
        $this->assertStringEndsWith("\n他合上册页。 ", $edit['body']);
        $this->assertSame($edit, $s->save($f['actor'], $f['novel'], 'scene', $v['node_id'], $d, 'edit'));
        $this->assertTrue($s->read($f['actor'], $f['novel'], 'chapter', $ch['id'])['stale']);
        $this->assertSame($v['body'], $s->read($f['actor'], $f['novel'], 'scene', $v['id'])['body']);
        $this->expectException(NovelProblem::class);
        $s->save($f['actor'], $f['novel'], 'scene', $v['node_id'], $d, 'racing-edit');
    }

    public function test_incomplete_body_is_preserved_but_cannot_be_assembled(): void
    {
        $f = DraftingFixture::create();
        $v = DraftingFixture::generate($f, '作为一个AI，正文待生成');
        $this->assertSame('revision_required', $v['display_status']);
        $this->assertFalse($v['report']['complete']);
        $this->assertNotEmpty($v['source_artifact_id']);
        $this->expectException(NovelProblem::class);
        app(DraftingService::class)->assemble($f['actor'], $f['novel'], $f['chapter']->id, ['plan_id' => $f['plan']['id'], 'scene_version_ids' => [$v['id']], 'expected_version' => 0, 'title' => '铜钥匙的回声', 'reason' => '不能拼接'], 'bad-assembly');
    }

    public function test_http_and_livewire_candidate_save_compare_and_authorization(): void
    {
        $f = DraftingFixture::create();
        $v = DraftingFixture::generate($f);
        $url = '/api/v1/novels/'.$f['novel'].'/drafts';
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($f['actor'])->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(DraftEditor::class, ['record' => $f['novel']])->set('kind', 'scene')->set('selected', $v['id'])->call('inspect')->set('body', $v['body']."\n许砚将钥匙收回。 ")->call('autoSave')->assertSee('已自动保存候选')->set('comparison', $v['id'])->call('inspect')->assertSet('detail.version', 2)->assertSet('previous.version', 1)->assertSee('正式正文只读区');
        $otherBook = (string) DB::table('novels')->where('id', '<>', $f['novel'])->value('id');
        $this->getJson('/api/v1/novels/'.$otherBook.'/drafts/scene/versions/'.$v['id'])->assertNotFound();
        $this->actingAs(User::factory()->create(['is_admin' => false]))->getJson($url)->assertForbidden();
        $this->assertSame(2, DB::table('scene_versions')->count());
    }

    public function test_two_scenes_freeze_transition_reject_skip_and_invalidate_after_prior_edit(): void
    {
        $f = DraftingFixture::create(true);
        $s = app(DraftingService::class);
        $body = implode("\n", array_slice(explode("\n", DraftingFixture::body()), 0, 25));
        $d = DraftingFixture::payload($f, $body);
        $second = array_replace($d, ['scene_id' => $f['second']['id']]);
        try {
            $s->start($f['actor'], $f['novel'], $second, 'cannot-skip');
            $this->fail('不得跳过前场景');
        } catch (NovelProblem $e) {
            $this->assertSame('PREVIOUS_SCENE_REQUIRED', $e->errorCode);
        }
        $first = DraftingFixture::generate($f, $body);
        $run = $s->start($f['actor'], $f['novel'], $second, 'second');
        $cfg = app(GenerationService::class)->read($f['actor'], $f['novel'], $run['run_id'])['run']['frozen_config'];
        $transition = collect($cfg['context']['items'])->firstWhere('category', 'previous_scene');
        $this->assertTrue($transition['required']);
        $this->assertTrue($transition['selected']);
        $this->assertSame($first['body'], $transition['text']);
        app(OutboxService::class)->consume((string) DB::table('outbox_events')->where('run_id', $run['run_id'])->value('id'));
        $last = (string) DB::table('scene_versions')->where('run_id', $run['run_id'])->value('id');
        $chapterData = ['plan_id' => $f['plan']['id'], 'scene_version_ids' => [$first['id'], $last], 'expected_version' => 0, 'title' => '缺口与旧日期', 'reason' => '两场景完整组装'];
        try {
            $s->assemble($f['actor'], $f['novel'], $f['chapter']->id, array_replace($chapterData, ['scene_version_ids' => [$last, $first['id']]]), 'wrong-order');
            $this->fail('不允许反序拼接');
        } catch (NovelProblem $e) {
            $this->assertSame('INVALID_SCENE_VERSION', $e->errorCode);
        }
        $chapter = $s->assemble($f['actor'], $f['novel'], $f['chapter']->id, $chapterData, 'two-scenes');
        $this->assertSame($body."\n\n".$body, $chapter['body']);
        $this->assertCount(2, DB::table('chapter_scene_versions')->get());
        $s->save($f['actor'], $f['novel'], 'scene', $first['node_id'], ['plan_id' => $f['plan']['id'], 'source_version_id' => $first['id'], 'expected_version' => 1, 'body' => $body.'他收起钥匙。', 'title' => $first['title'], 'reason' => '修改前文'], 'prior-edit');
        $this->assertTrue($s->read($f['actor'], $f['novel'], 'scene', $last)['stale']);
        $this->assertTrue($s->read($f['actor'], $f['novel'], 'chapter', $chapter['id'])['stale']);
        $preview = app(RecoveryService::class)->preview($f['actor'], $f['novel'], $run['run_id']);
        $this->assertSame('stale', $preview['rows'][0]['disposition']);
        $this->assertFalse($preview['rows'][0]['retry_allowed']);
    }

    public function test_zero_budget_and_replay_response_mismatch_never_send_another_request(): void
    {
        $f = DraftingFixture::create();
        $d = DraftingFixture::payload($f);
        $zero = app(BillingConfigurationService::class)->execute($f['actor'], 'policy', ['key' => 'draft-zero', 'expected_version' => 0, 'currency' => 'USD', 'limits' => array_fill_keys(['request', 'chapter', 'run', 'book', 'day'], '0'), 'warning_percent' => 80, 'stop_percent' => 100, 'timezone' => 'UTC', 'reason' => '验证零预算门禁'], 'draft-zero');
        $d['billing']['policy_id'] = $zero['id'];
        $run = app(DraftingService::class)->start($f['actor'], $f['novel'], $d, str_repeat('a', 128));
        $claim = app(GenerationService::class)->claim($f['actor'], $f['novel'], $run['run_id'], 'scene');
        $cfg = app(GenerationService::class)->read($f['actor'], $f['novel'], $run['run_id'])['run']['frozen_config']['configuration'];
        try {
            app(ProviderGateway::class)->execute($f['actor'], $f['novel'], $claim['id'], $cfg['models'][0]['model_version_id'], ['prompt' => $cfg['rendered_prompt'], 'output_schema' => BodyContract::schema()], 'draft-scene', new DraftReplayAdapter(['body' => '擅自替换响应。']));
            $this->fail('不能替换冻结离线响应');
        } catch (NovelProblem $e) {
            $this->assertSame('DRAFT_REPLAY_MISMATCH', $e->errorCode);
        }
        DB::table('generation_steps')->where('id', $claim['id'])->update(['lease_until' => DB::raw("clock_timestamp() - interval '1 second'")]);
        app(OutboxService::class)->consume((string) DB::table('outbox_events')->where('run_id', $run['run_id'])->value('id'));
        $this->assertSame('deferred', DB::table('generation_runs')->where('id', $run['run_id'])->value('status'));
        $this->assertSame('DEFERRED_BUDGET', DB::table('generation_runs')->where('id', $run['run_id'])->value('error_code'));
        $this->assertSame(0, DB::table('ai_requests')->where('novel_id', $f['novel'])->count());
        $this->assertSame(0, DB::table('scene_versions')->count());
        Http::assertNothingSent();
    }

    public function test_null_byte_is_rejected_before_start_and_http_edit_preserves_body_bytes(): void
    {
        $f = DraftingFixture::create();
        $s = app(DraftingService::class);
        $before = DB::table('generation_runs')->count();
        try {
            $s->start($f['actor'], $f['novel'], DraftingFixture::payload($f, "正文\0结束。"), 'reject-null');
            $this->fail('零字节不能进入正文运行');
        } catch (NovelProblem $e) {
            $this->assertSame('INVALID_BODY_CHARACTER', $e->errorCode);
        }
        $this->assertSame($before, DB::table('generation_runs')->count());
        $v = DraftingFixture::generate($f);
        $raw = '  '.$v['body']."\r\n他把册页留在桌上。  ";
        $url = '/api/v1/novels/'.$f['novel'].'/drafts/scene/'.$v['node_id'].'/versions';
        $d = ['plan_id' => $f['plan']['id'], 'source_version_id' => $v['id'], 'expected_version' => 1, 'title' => $v['title'], 'body' => $raw, 'reason' => '验证HTTP不裁正文'];
        $result = $this->actingAs($f['actor'])->withHeader('Idempotency-Key', 'http-body')->postJson($url, $d)->assertCreated()->json('data');
        $this->assertSame($raw, $result['raw_body']);
        $this->assertSame(BodyContract::normalize($raw), $result['body']);
        $this->assertSame(hash('sha256', $result['body']), $result['body_hash']);
        $this->assertStringEndsWith('Z', $result['created_at']);
        $this->postJson($url, $d)->assertCreated()->assertJsonPath('data.id', $result['id']);
        $this->postJson($url, array_replace($d, ['body' => $raw.'改变。']))->assertConflict();
    }

    public function test_postgresql_immutable_versions_comments_and_composite_scope(): void
    {
        $f = DraftingFixture::create();
        $v = DraftingFixture::generate($f);
        foreach (['scene_versions', 'chapter_versions', 'chapter_scene_versions'] as $table) {
            $this->assertNotEmpty(DB::selectOne('SELECT obj_description(?::regclass) AS c', [$table])->c);
            $this->assertSame(0, DB::selectOne('SELECT count(*) AS n FROM pg_attribute WHERE attrelid=?::regclass AND attnum>0 AND NOT attisdropped AND col_description(attrelid,attnum) IS NULL', [$table])->n);
        }
        // 直接写库也不能伪造正文哈希或跨节点编辑父版本。
        $copy = (array) DB::table('scene_versions')->find($v['id']);
        unset($copy['id']);
        $copy['version'] = 2;
        $copy['parent_version_id'] = $v['id'];
        $copy['source_artifact_id'] = $copy['run_id'] = null;
        foreach ([['body_hash' => str_repeat('0', 64), 'code' => '23514'], ['node_id' => DB::table('scenes')->where('novel_id', $f['novel'])->where('id', '<>', $v['node_id'])->value('id'), 'code' => '23503']] as $invalid) {
            $code = $invalid['code'];
            unset($invalid['code']);
            try {
                DB::transaction(fn () => DB::table('scene_versions')->insert(array_replace($copy, $invalid)));
                $this->fail('数据库应拒绝不合法正文身份');
            } catch (QueryException $e) {
                $this->assertSame($code, $e->getCode());
            }
        }
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('scene_versions')->where('id', $v['id'])->update(['body' => '不允许覆盖。']));
    }
}
