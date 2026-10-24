<?php

namespace Tests\Feature;

use App\Exceptions\NovelProblem;
use App\Filament\Pages\RecoveryDesk;
use App\Generation\RecoveryPolicy;
use App\Generation\StageExecutor;
use App\Generation\StageResult;
use App\Models\User;
use App\ProvidersGateway\FakeProvider;
use App\ProvidersGateway\ProviderAdapter;
use App\ProvidersGateway\ProviderReply;
use App\Services\GenerationService;
use App\Services\OutboxService;
use App\Services\ProviderGateway;
use App\Services\RecoveryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\FakeStageExecutor;
use Tests\Support\ProviderFixture;
use Tests\Support\QueuedRunFixture;
use Tests\TestCase;

/** C06 全部采用确定性离线阶段，不调用真实供应商。 */
class RecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function preview(array $f, array $changes = []): array
    {
        return app(RecoveryService::class)->preview($f['actor'], $f['novelId'], $f['runId'], $changes);
    }

    private function decide(array $f, string $action, array $extra = [], ?string $key = null): array
    {
        return app(RecoveryService::class)->execute($f['actor'], $f['novelId'], $f['runId'], $action, $extra + ['reason' => '离线恢复验收', 'preview_hash' => $this->preview($f, $extra['changed_scopes'] ?? [])['preview_hash']], $key ?? (string) Str::uuid());
    }

    private function problem(string $code, callable $callback): void
    {
        try {
            $callback();
            $this->fail('必须拒绝操作');
        } catch (NovelProblem $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    public function test_pause_resume_fences_inflight_result_and_records_late_evidence_once(): void
    {
        $f = QueuedRunFixture::create();
        $g = app(GenerationService::class);
        $claim = $g->claim($f['actor'], $f['novelId'], $f['runId'], 'first');
        $this->decide($f, 'pause');
        $this->assertFalse($g->heartbeat($claim['id'], $claim['token'], $claim['owner']));
        $this->decide($f, 'resume');
        foreach ([1, 2] as $ignored) {
            $this->assertFalse($g->complete($f['actor'], $f['novelId'], $f['runId'], $claim, new StageResult('succeeded', ['text' => '暂停前发出的响应'])));
        }
        $this->assertDatabaseCount('generation_artifacts', 0);
        $this->assertDatabaseCount('generation_late_evidence', 1);
        $r = $this->decide($f, 'retry', ['scope' => 'first']);
        app(OutboxService::class)->consume($r['event_id']);
        $this->assertSame('succeeded', DB::table('generation_steps')->where('run_id', $f['runId'])->orderByDesc('id')->value('status'));
        $this->assertSame(2, DB::table('generation_steps')->count());
        $this->assertSame(0, (int) DB::table('novels')->where('id', $f['novelId'])->value('state_version'));
    }

    public function test_cancel_stops_new_publication_consumption_and_cannot_resume(): void
    {
        Queue::fake();
        $f = QueuedRunFixture::create();
        $this->decide($f, 'cancel');
        $this->assertSame(0, app(OutboxService::class)->publish()['published']);
        foreach (DB::table('outbox_events')->pluck('id') as $id) {
            app(OutboxService::class)->consume((string) $id);
        }
        $this->assertDatabaseCount('generation_steps', 0);
        $this->problem('RUN_NOT_EXECUTABLE', fn () => app(OutboxService::class)->enqueue($f['actor'], $f['novelId'], $f['runId'], 'first', 1));
        $this->problem('RUN_NOT_EXECUTABLE', fn () => $this->decide($f, 'resume'));
        $this->assertSame($f['runId'], app(OutboxService::class)->startQueued($f['actor'], $f['novelId'], $f['payload'], 'queued')['run']['id']);
        $this->assertSame('canceled', $this->preview($f)['status']);
        Queue::assertNothingPushed();
    }

    public function test_preview_idempotency_stale_conflict_and_immutable_decisions(): void
    {
        $f = QueuedRunFixture::create();
        $p = ['reason' => '暂停核查', 'preview_hash' => $this->preview($f)['preview_hash']];
        $s = app(RecoveryService::class);
        $one = $s->execute($f['actor'], $f['novelId'], $f['runId'], 'pause', $p, 'same');
        $this->assertEquals($one, $s->execute($f['actor'], $f['novelId'], $f['runId'], 'pause', $p, 'same'));
        $this->problem('IDEMPOTENCY_CONFLICT', fn () => $s->execute($f['actor'], $f['novelId'], $f['runId'], 'cancel', $p, 'same'));
        $this->problem('STALE_RECOVERY_PREVIEW', fn () => $s->execute($f['actor'], $f['novelId'], $f['runId'], 'cancel', $p, 'new'));
        try {
            DB::transaction(fn () => DB::table('recovery_decisions')->update(['reason' => '覆盖']));
            $this->fail();
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_checkpoint_and_success_preserved_and_changed_descendants_are_stale(): void
    {
        $f = QueuedRunFixture::create();
        $g = app(GenerationService::class);
        $g->advance($f['actor'], $f['novelId'], $f['runId'], 'first', new FakeStageExecutor);
        $g->advance($f['actor'], $f['novelId'], $f['runId'], 'second', new FakeStageExecutor('deferred'));
        $before = DB::table('generation_artifacts')->orderBy('id')->get()->toJson();
        $p = $this->preview($f);
        $this->assertSame(['reuse', 'checkpoint'], array_column($p['rows'], 'disposition'));
        $this->assertSame(['stale', 'stale'], array_column($this->preview($f, ['first'])['rows'], 'disposition'));
        $decision = $this->decide($f, 'reextract', ['scope' => 'first']);
        $this->assertSame('awaiting_stage_implementation', $decision['outcome']);
        $this->assertFalse($decision['source']['rewrite_source']);
        $extractor = new class implements StageExecutor
        {
            public int $calls = 0;

            public function execute(array $input): StageResult
            {
                $this->calls++;

                return new StageResult('succeeded', ['text' => '重提取：'.$input['source']['text']]);
            }
        };
        $decisionId = (string) DB::table('recovery_decisions')->where('action', 'reextract')->value('id');
        $extracted = app(RecoveryService::class)->replayEvidence($f['actor'], $f['novelId'], $f['runId'], $decisionId, $extractor);
        $this->assertEquals($extracted, app(RecoveryService::class)->replayEvidence($f['actor'], $f['novelId'], $f['runId'], $decisionId, $extractor));
        $this->assertSame(1, $extractor->calls);
        $this->assertSame($decision['source']['content_hash'], $extracted['source_hash']);
        $this->decide($f, 'change_plan', ['changed_scopes' => ['first']]);
        $r = $this->decide($f, 'retry', ['scope' => 'second']);
        $fake = new FakeStageExecutor;
        app(OutboxService::class)->consume($r['event_id'], $fake);
        $this->assertNotNull($fake->inputs[0]['checkpoint']);
        $this->assertSame($before, DB::table('generation_artifacts')->orderBy('id')->limit(2)->get()->toJson());
        $this->assertSame('succeeded', $this->preview($f)['status']);
        $this->assertSame([0, 0], array_column($this->preview($f)['rows'], 'automatic_revision_rounds'));
    }

    public function test_error_classification_auto_backoff_and_three_attempt_limit(): void
    {
        $f = QueuedRunFixture::create();
        $g = app(GenerationService::class);
        $fake = new class implements StageExecutor
        {
            public function execute(array $input): StageResult
            {
                throw new NovelProblem('NETWORK_NOT_SENT', '内部敏感信息');
            }
        };
        for ($i = 1; $i <= 3; $i++) {
            $g->advance($f['actor'], $f['novelId'], $f['runId'], 'first', $fake, $i === 1 ? null : $i - 1);
        }
        $this->assertSame(2, DB::table('outbox_events')->where('retry_of', '>', 0)->count());
        $this->assertTrue(DB::table('outbox_events')->where('retry_of', 1)->whereRaw('next_delivery_at > clock_timestamp()')->exists());
        $this->problem('RECOVERY_BLOCKED', fn () => $this->decide($f, 'retry', ['scope' => 'first']));
        $this->assertStringNotContainsString('内部敏感信息', DB::table('generation_artifacts')->get()->toJson());
        foreach (['DEFERRED_BUDGET' => 'deferred', 'CAPACITY_EXCEEDED' => 'deferred', 'PROVIDER_AUTH_FAILED' => 'blocked', 'EVIDENCE_NOT_FOUND' => 'blocked', 'PROVIDER_OUTCOME_UNKNOWN' => 'blocked'] as $code => $state) {
            $this->assertSame($state, RecoveryPolicy::classify($code)['status']);
        }
        $this->assertGreaterThanOrEqual(100, RecoveryPolicy::delay(1, 100));
    }

    public function test_unknown_request_blocks_expired_reclaim_and_manual_resume(): void
    {
        Http::preventStrayRequests();
        $f = ProviderFixture::create();
        app(ProviderGateway::class)->execute($f['actor'], $f['novelId'], $f['claim']['id'], $f['model']['version_id'], $f['input'], 'unknown', new FakeProvider(timeout: true));
        DB::table('generation_steps')->where('id', $f['claim']['id'])->update(['lease_until' => now()->subMinute()]);
        $this->problem('UNKNOWN_REQUEST_OUTCOME', fn () => app(GenerationService::class)->claim($f['actor'], $f['novelId'], $f['runId'], 'first'));
        app(OutboxService::class)->recover();
        $this->assertSame('needs_attention', $this->preview($f)['status']);
        $this->decide($f, 'pause');
        $this->problem('RECOVERY_BLOCKED', fn () => $this->decide($f, 'resume'));
        $p = $this->preview($f);
        $this->assertTrue($p['rows'][0]['unknown_request']);
        $this->assertNotSame('0.0000000000', $p['requests'][0]['billing']['risk_reserved']);
        Http::assertNothingSent();
    }

    public function test_api_ownership_ui_empty_state_and_livewire_control(): void
    {
        $f = QueuedRunFixture::create();
        $url = '/api/v1/novels/'.$f['novelId'].'/runs/'.$f['runId'].'/recovery';
        $this->getJson($url)->assertUnauthorized();
        $other = User::factory()->create();
        $other->is_admin = true; // 仅在内存模拟越权管理员，不违反单管理员数据库约束。
        $this->actingAs($other)->getJson($url)->assertNotFound();
        Livewire::actingAs($other)->test(RecoveryDesk::class)->assertSee('尚无运行');
        $this->actingAs($f['actor'])->getJson($url)->assertOk()->assertJsonPath('data.status', 'queued');
        Livewire::actingAs($f['actor'])->test(RecoveryDesk::class)->set('novel', $f['novelId'])->set('run', $f['runId'])->call('inspect')->assertSet('preview.status', 'queued')->set('reason', '界面验收暂停')->call('decide', 'pause')->assertSet('preview.status', 'paused');
        $this->actingAs($f['actor'])->postJson($url.'/cancel', ['reason' => '取消', 'preview_hash' => $this->preview($f)['preview_hash']], ['Idempotency-Key' => 'api-cancel'])->assertOk()->assertJsonPath('data.status', 'canceled');
    }

    public function test_provider_retry_hint_is_persisted_and_applied_to_outbox(): void
    {
        $f = ProviderFixture::create();
        $reply = new ProviderReply(503, json_encode(['request_id' => 'known-failure', 'usage' => ['input_tokens' => 0, 'output_tokens' => 0]]), 120);
        $r = app(ProviderGateway::class)->execute($f['actor'], $f['novelId'], $f['claim']['id'], $f['model']['version_id'], $f['input'], 'known-failure', new FakeProvider($reply));
        $this->assertSame('PROVIDER_HTTP_503', $r['error_code']);
        $this->assertSame(120, DB::table('ai_requests')->where('step_id', $f['claim']['id'])->value('retry_after_seconds'));
        app(GenerationService::class)->complete($f['actor'], $f['novelId'], $f['runId'], $f['claim'], new StageResult('failed', ['text' => '已知失败'], 'PROVIDER_HTTP_503'));
        $this->assertTrue(DB::table('outbox_events')->where('run_id', $f['runId'])->where('retry_of', 1)->whereRaw("next_delivery_at > clock_timestamp() + interval '115 seconds'")->exists());
        $this->problem('RETRY_ALREADY_SCHEDULED', fn () => $this->decide($f, 'retry', ['scope' => 'first']));
    }

    public function test_pause_during_provider_response_quarantines_but_accounts_usage(): void
    {
        $f = ProviderFixture::create();
        $adapter = new class($f) implements ProviderAdapter
        {
            public function __construct(private array $f) {}

            public function send(array $request): ProviderReply
            {
                $s = app(RecoveryService::class);
                $f = $this->f;
                $s->execute($f['actor'], $f['novelId'], $f['runId'], 'pause', ['reason' => '请求在途暂停', 'preview_hash' => $s->preview($f['actor'], $f['novelId'], $f['runId'])['preview_hash']], 'pause-in-flight');

                return (new FakeProvider)->send($request);
            }
        };
        $r = app(ProviderGateway::class)->execute($f['actor'], $f['novelId'], $f['claim']['id'], $f['model']['version_id'], $f['input'], 'late-response', $adapter);
        $this->assertSame('quarantined', $r['status']);
        $this->assertSame('settled', $r['billing']['status']);
        $this->assertNotSame('0.0000000000', $r['billing']['actual_cost']);
        $this->assertSame('paused', $this->preview($f)['status']);
        $this->assertDatabaseCount('generation_artifacts', 0);
    }

    public function test_finished_run_can_ack_missing_receipts_without_new_execution(): void
    {
        Queue::fake();
        $f = QueuedRunFixture::create();
        foreach (['first', 'second'] as $scope) {
            app(GenerationService::class)->advance($f['actor'], $f['novelId'], $f['runId'], $scope, new FakeStageExecutor);
        }
        $this->assertDatabaseCount('consumer_receipts', 0);
        $this->assertSame(2, app(OutboxService::class)->publish()['published']);
        $fake = new FakeStageExecutor;
        foreach (DB::table('outbox_events')->pluck('id') as $id) {
            app(OutboxService::class)->consume((string) $id, $fake);
        }
        $this->assertSame(0, $fake->calls);
        $this->assertDatabaseCount('consumer_receipts', 2);
    }

    public function test_new_tables_and_every_column_have_chinese_comments(): void
    {
        foreach (['recovery_decisions', 'generation_late_evidence', 'recovery_outputs'] as $table) {
            $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', DB::selectOne('SELECT obj_description(?::regclass) AS comment', [$table])->comment);
            foreach (DB::select('SELECT col_description(attrelid,attnum) AS comment FROM pg_attribute WHERE attrelid=?::regclass AND attnum>0 AND NOT attisdropped', [$table]) as $column) {
                $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $column->comment ?? '');
            }
        }
    }
}
