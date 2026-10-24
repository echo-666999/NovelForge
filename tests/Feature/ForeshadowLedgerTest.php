<?php

namespace Tests\Feature;

use App\Exceptions\NovelProblem;
use App\Filament\Pages\ForeshadowLedger;
use App\Generation\StageResult;
use App\Models\Novel;
use App\Models\User;
use App\Services\ForeshadowLedgerService;
use App\Services\GenerationService;
use App\Services\NovelIntentService;
use App\Services\OutboxService;
use App\Services\StoryStateService;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\ForeshadowFixture as F;
use Tests\TestCase;

/** 规划冻结与正式正文证据分离的正反例，全部使用隔离 PostgreSQL。 */
class ForeshadowLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function rejected(callable $fn, string $code): void
    {
        try {
            $fn();
            $this->fail('必须拒绝非法操作');
        } catch (NovelProblem $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    private function invalid(callable $fn): void
    {
        try {
            $fn();
            $this->fail('必须拒绝无效输入');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    private function sqlReject(callable $fn): void
    {
        // 用保存点隔离预期错误，避免污染测试事务。
        try {
            DB::transaction($fn);
            $this->fail('数据库必须拒绝');
        } catch (QueryException $e) {
            $this->assertNotEmpty($e->errorInfo);
        }
    }

    public function test_first_five_three_plans_and_exact_hundred_contract_can_be_frozen_without_formal_progress(): void
    {
        Http::preventStrayRequests();
        $f = F::create();
        $hashes = [];
        foreach (['铜钥匙缺口', '破损封记', '账册暗号'] as $name) {
            $p = F::command($f, 'create', ['plan' => array_replace($f['plan'], ['name' => $name])])['plan'];
            $freeze = F::command($f, 'freeze', ['plan_id' => $p['id']]);
            $this->assertSame('planned', $p['lifecycle']);
            $this->assertSame($p['content_hash'], $freeze['plan']['content_hash']);
            $hashes[] = $p['content_hash'];
        }
        $s = app(ForeshadowLedgerService::class);
        $r = $s->ledger($f['actor'], $f['novelId'], ['chapter' => 100]);
        $this->assertCount(3, $r['items']);
        foreach ($r['items'] as $p) {
            $this->assertTrue($p['frozen']);
            $this->assertTrue($p['due']);
            $this->assertFalse($p['overdue']);
            $this->assertSame(100, $p['payoff_start']);
            $this->assertSame(100, $p['payoff_end']);
            $this->assertNull($p['formal_status']);
            $this->assertNull($p['first_actual_plant']);
            $this->assertSame(0, $p['evidence_count']);
            $this->assertLessThanOrEqual(5, $p['plant_end']);
            $this->assertSame([], $s->read($f['actor'], $f['novelId'], $p['id'])['occurrences']);
        }
        $this->assertCount(1, $r['threads']);
        $this->assertCount(3, $r['threads'][0]['linked_plan_ids']);
        $this->assertSame('not_connected', $r['threads'][0]['formal_status']);
        $this->assertSame(100, $r['ending']['content']['target_chapters']);
        $this->assertSame(0, Novel::find($f['novelId'])->state_version);
        $this->assertDatabaseCount('story_events', 0);
        $this->assertDatabaseCount('foreshadow_occurrences', 0);
        Http::assertNothingSent();
    }

    public function test_reschedule_needs_decision_and_preserves_frozen_original_and_reason(): void
    {
        $f = F::create();
        $s = app(ForeshadowLedgerService::class);
        $p = F::command($f, 'create', ['plan' => $f['plan']])['plan'];
        F::command($f, 'freeze', ['plan_id' => $p['id']]);
        $next = array_replace($f['plan'], ['payoff_target' => 110, 'payoff_start' => 110, 'payoff_end' => 112]);
        $q = F::command($f, 'propose', ['plan_id' => $p['id'], 'kind' => 'reschedule', 'plan' => $next, 'reason' => '铺垫不足提出延期']);
        $r = $s->ledger($f['actor'], $f['novelId'], ['chapter' => 101]);
        $this->assertSame($p['id'], $r['items'][0]['id']);
        $this->assertTrue($r['items'][0]['overdue']);
        $approved = F::command($f, 'decide', ['proposal_id' => $q['proposal_id'], 'decision' => 'approve', 'reason' => '作者承担延期影响'])['plan'];
        $history = $s->read($f['actor'], $f['novelId'], $approved['id']);
        $this->assertCount(2, $history['history']);
        $this->assertSame(100, $history['history'][0]['payoff_target']);
        $this->assertSame($p['content_hash'], $history['freezes'][0]['content_hash']);
        $this->assertSame('铺垫不足提出延期', $history['proposals'][0]['reason']);
        $this->assertSame('作者承担延期影响', $history['proposals'][0]['decision']['reason']);
        $r = $s->ledger($f['actor'], $f['novelId'], ['chapter' => 101]);
        $this->assertFalse($r['items'][0]['frozen']);
        $this->assertFalse($r['items'][0]['overdue']);
        $this->rejected(fn () => F::command($f, 'freeze', ['plan_id' => $p['id']]), 'STALE_PLAN');
        $this->rejected(fn () => F::command($f, 'decide', ['proposal_id' => $q['proposal_id'], 'decision' => 'reject']), 'ALREADY_DECIDED');
    }

    public function test_abandon_requires_alternative_and_author_approval_rejection_does_not_change_plan(): void
    {
        $f = F::create();
        $p = F::command($f, 'create', ['plan' => $f['plan']])['plan'];
        $this->invalid(fn () => F::command($f, 'propose', ['plan_id' => $p['id'], 'kind' => 'abandon']));
        $q = F::command($f, 'propose', ['plan_id' => $p['id'], 'kind' => 'abandon', 'alternative' => '保留公开档案路线作为替代']);
        F::command($f, 'decide', ['proposal_id' => $q['proposal_id'], 'decision' => 'reject']);
        $this->assertDatabaseCount('foreshadow_plans', 1);
        $q = F::command($f, 'propose', ['plan_id' => $p['id'], 'kind' => 'abandon', 'alternative' => '使用既有封记证据完成终局']);
        $new = F::command($f, 'decide', ['proposal_id' => $q['proposal_id'], 'decision' => 'approve'])['plan'];
        $this->assertSame('abandoned', $new['lifecycle']);
        $this->assertSame('planned', DB::table('foreshadow_plans')->where('id', $p['id'])->value('lifecycle'));
        $this->rejected(fn () => F::command($f, 'freeze', ['plan_id' => $new['id']]), 'INVALID_TRANSITION');
    }

    public function test_schedule_is_explicit_preview_and_due_overdue_are_independent(): void
    {
        $f = F::create();
        $s = app(ForeshadowLedgerService::class);
        F::command($f, 'create', ['plan' => $f['plan']]);
        foreach ([79 => 'normal', 80 => 'groundwork_check', 94 => 'groundwork_check', 95 => 'high', 100 => 'high', 101 => 'high'] as $chapter => $priority) {
            $r = $s->ledger($f['actor'], $f['novelId'], ['chapter' => $chapter]);
            $this->assertSame('author_preview', $r['schedule_basis']);
            $this->assertSame($priority, $r['items'][0]['priority']);
            $this->assertSame('planned', $r['items'][0]['lifecycle']);
            $this->assertSame($chapter === 100, $r['items'][0]['due']);
            $this->assertSame($chapter > 100, $r['items'][0]['overdue']);
        }
        $r = $s->ledger($f['actor'], $f['novelId']);
        $this->assertSame('unspecified', $r['schedule_basis']);
        $this->assertNull($r['items'][0]['overdue']);
        $this->assertCount(0, $s->ledger($f['actor'], $f['novelId'], ['chapter' => 99, 'due' => true])['items']);
        $this->assertCount(1, $s->ledger($f['actor'], $f['novelId'], ['chapter' => 101, 'overdue' => true, 'required' => true, 'entity_id' => $f['person']['entity']['id']])['items']);
        $this->assertCount(0, $s->ledger($f['actor'], $f['novelId'], ['from' => 101])['items']);
    }

    public function test_idempotency_stale_input_and_formal_state_injection_are_rejected(): void
    {
        $f = F::create();
        $data = ['plan' => $f['plan'], 'expected_version' => Novel::find($f['novelId'])->row_version];
        $one = F::command($f, 'create', $data, 'same-key');
        $this->assertSame(NovelIntentService::canonical($one), NovelIntentService::canonical(F::command($f, 'create', $data, 'same-key')));
        $this->rejected(fn () => F::command($f, 'create', $data + ['reason' => '不同内容'], 'same-key'), 'IDEMPOTENCY_CONFLICT');
        $this->rejected(fn () => F::command($f, 'create', $data), 'STALE_STATE');
        foreach (['planted', 'paid_off', 'reinforced', 'due'] as $status) {
            $this->invalid(fn () => F::command($f, 'create', ['plan' => $f['plan'], 'lifecycle' => $status]));
            $this->invalid(fn () => F::command($f, 'create', ['plan' => $f['plan'] + ['status' => $status]]));
        }
        $this->invalid(fn () => F::command($f, 'create', ['plan' => '错误载荷']));
        $this->assertDatabaseCount('foreshadow_plans', 1);
    }

    public function test_bad_windows_cross_book_sources_and_unapproved_freeze_are_rejected(): void
    {
        $f = F::create(false);
        $p = F::command($f, 'create', ['plan' => $f['plan']])['plan'];
        $this->rejected(fn () => F::command($f, 'freeze', ['plan_id' => $p['id']]), 'BLUEPRINT_NOT_APPROVED');
        foreach ([['payoff_target' => 99], ['payoff_start' => 3], ['plant_end' => 0], ['reinforcements' => [['start' => 99, 'end' => 100, 'new_information' => '增加信息', 'choice_impact' => '改变选择']]]] as $change) {
            try {
                F::command($f, 'create', ['plan' => array_replace($f['plan'], $change)]);
                $this->fail('错误窗口应拒绝');
            } catch (NovelProblem|ValidationException $e) {
                $this->assertTrue(true);
            }
        }
        $other = F::create(actor: $f['actor']);
        $this->rejected(fn () => F::command($f, 'create', ['plan' => array_replace($f['plan'], ['blueprint_version_id' => $other['plan']['blueprint_version_id']])]), 'INVALID_REFERENCE');
        $this->rejected(fn () => F::command($f, 'create', ['plan' => array_replace($f['plan'], ['thread_id' => $other['plan']['thread_id']])]), 'INVALID_REFERENCE');
        $this->rejected(fn () => F::command($f, 'create', ['plan' => array_replace($f['plan'], ['prerequisite_event_ids' => ['9223372036854775807']])]), 'INVALID_REFERENCE');
    }

    public function test_prerequisite_candidate_has_real_source_but_does_not_satisfy_formal_groundwork(): void
    {
        $f = F::create();
        Http::preventStrayRequests();
        // 通过既有离线阶段建立产物和精确证据，禁止直接伪造正式事件。
        $run = app(OutboxService::class)->startQueued($f['actor'], $f['novelId'], ['outline_version_id' => $f['outline']['id'], 'expected_version' => Novel::find($f['novelId'])->row_version, 'nodes' => [['key' => 'source', 'stage' => 'test.echo', 'depends_on' => [], 'input' => ['text' => '许砚发现铜钥匙缺口。']]]], 'd03-source')['run']['id'];
        $g = app(GenerationService::class);
        $claim = $g->claim($f['actor'], $f['novelId'], $run, 'source');
        $body = '许砚发现铜钥匙缺口。';
        $g->complete($f['actor'], $f['novelId'], $run, $claim, new StageResult('succeeded', ['text' => $body]));
        $state = app(StoryStateService::class);
        $source = $state->registerArtifact($f['actor'], $f['novelId'], (string) DB::table('generation_artifacts')->where('step_id', $claim['id'])->value('id'));
        $evidence = $state->evidence($f['actor'], $f['novelId'], $source, hash('sha256', $body), 0, 2, '许砚');
        $event = $state->propose($f['actor'], $f['novelId'], ['evidence_id' => $evidence, 'definition_version_id' => $f['person']['definition']['id'], 'fact_kind' => 'fact', 'action' => 'set', 'chapter_sequence' => 1, 'narrative_order' => 1, 'attribute' => 'action', 'value' => '发现铜钥匙', 'confidence' => 'confirmed', 'story_time' => [], 'reason' => '验证候选来源']);
        $p = F::command($f, 'create', ['plan' => array_replace($f['plan'], ['prerequisite_event_ids' => [$event]])])['plan'];
        $r = app(ForeshadowLedgerService::class)->ledger($f['actor'], $f['novelId'], ['chapter' => 95]);
        $this->assertContains('PREREQUISITES_NOT_FORMALLY_COMMITTED', $r['items'][0]['blockers']);
        $this->assertSame(0, $r['items'][0]['evidence_count']);
        $this->assertDatabaseHas('foreshadow_prerequisites', ['plan_id' => $p['id'], 'event_id' => $event]);
        $other = F::create(actor: $f['actor']);
        $this->rejected(fn () => F::command($other, 'create', ['plan' => array_replace($other['plan'], ['prerequisite_event_ids' => [$event]])]), 'INVALID_REFERENCE');
        $this->rejected(fn () => F::command($f, 'propose', ['plan_id' => $p['id'], 'kind' => 'reschedule', 'plan' => array_replace($p['payload'], ['required' => false])]), 'INVALID_REVISION');
        $this->assertDatabaseCount('ai_requests', 0);
        Http::assertNothingSent();
    }

    public function test_database_guards_immutability_formal_entry_references_and_chinese_comments(): void
    {
        $f = F::create();
        $p = F::command($f, 'create', ['plan' => $f['plan']])['plan'];
        F::command($f, 'freeze', ['plan_id' => $p['id']]);
        $this->sqlReject(fn () => DB::table('foreshadow_plans')->where('id', $p['id'])->update(['name' => '改写历史']));
        $this->sqlReject(fn () => DB::table('foreshadow_freezes')->where('plan_id', $p['id'])->delete());
        $this->sqlReject(fn () => DB::table('foreshadow_prerequisites')->insert(['novel_id' => $f['novelId'], 'plan_id' => $p['id'], 'event_id' => 1]));
        $this->sqlReject(fn () => DB::table('foreshadow_occurrences')->insert(['novel_id' => $f['novelId'], 'plan_id' => $p['id'], 'event_id' => 1, 'evidence_id' => 1, 'commit_id' => 1, 'chapter_version_id' => 1, 'revision_id' => 1, 'kind' => 'payoff', 'causal_role' => '伪造', 'new_information' => '伪造']));
        $tables = DB::select("SELECT c.relname,obj_description(c.oid) AS remark FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind='r' AND c.relname LIKE 'foreshadow_%'");
        $this->assertCount(8, $tables);
        foreach ($tables as $table) {
            $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $table->remark);
            $cols = DB::select('SELECT col_description(?,a.attnum) AS remark FROM pg_attribute a WHERE a.attrelid=?::regclass AND a.attnum>0 AND NOT a.attisdropped', [DB::selectOne('SELECT ?::regclass::oid AS id', [$table->relname])->id, $table->relname]);
            foreach ($cols as $col) {
                $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $col->remark);
            }
        }
        $this->assertTrue(DB::table('audit_events')->where('object_type', 'foreshadow_freezes')->exists());
    }

    public function test_http_and_filament_share_services_with_auth_empty_state_and_decisions(): void
    {
        $f = F::create();
        $path = '/api/v1/novels/'.$f['novelId'].'/foreshadows';
        $this->getJson($path)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['is_admin' => false]))->getJson($path)->assertForbidden();
        $this->actingAs($f['actor'])->getJson('/api/v1/novels/9223372036854775807/foreshadows')->assertNotFound();
        $this->actingAs($f['actor']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ForeshadowLedger::class)->assertSee('选择小说后');
        $page = Livewire::test(ForeshadowLedger::class, ['novel' => $f['novelId']])->assertSee('暂无符合条件')->set('form', $f['plan'])->set('reason', '作者冻结验收计划')->call('createPlan')->assertSee('已保存作者规划');
        $p = $page->get('detail');
        $this->getJson($path.'/'.$p['id'])->assertOk()->assertJsonPath('data.formal_progress', 'not_connected')->assertHeader('Cache-Control', 'no-store, private');
        $page->call('freezePlan')->assertSee('冻结版本')->set('alternative', '保留既有终局证据')->call('propose', 'abandon')->assertSee('保留既有终局证据');
        $q = DB::table('foreshadow_proposals')->first();
        $page->call('decide', (string) $q->id, 'reject')->assertSee('拒绝');
        $this->postJson($path.'/create', ['plan' => $f['plan'], 'reason' => '测试', 'expected_version' => Novel::find($f['novelId'])->row_version])->assertUnprocessable();
    }
}
