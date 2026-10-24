<?php

namespace Tests\Feature;

use App\Filament\Pages\AuditCenter;
use App\Filament\Pages\RunCenter;
use App\Generation\StageResult;
use App\Models\User;
use App\ProvidersGateway\FakeProvider;
use App\Services\GenerationService;
use App\Services\ProviderGateway;
use App\Services\RecoveryService;
use App\Services\RunCenterService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\FakeStageExecutor;
use Tests\Support\ProviderFixture;
use Tests\Support\QueuedRunFixture;
use Tests\TestCase;

/** C07 验证持久事实、跨页关联及受控证据，全部离线。 */
class RunCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_trace_links_run_step_request_artifact_cost_and_control(): void
    {
        Http::preventStrayRequests();
        $f = ProviderFixture::create();
        app(ProviderGateway::class)->execute($f['actor'], $f['novelId'], $f['claim']['id'], $f['model']['version_id'], $f['input'], 'c07', new FakeProvider);
        app(GenerationService::class)->complete($f['actor'], $f['novelId'], $f['runId'], $f['claim'], new StageResult('succeeded', ['text' => '离线文本']));
        $trace = DB::table('run_traces')->where('run_id', $f['runId'])->value('trace_id');
        $s = app(RunCenterService::class);
        $detail = $s->detail($f['actor'], $f['novelId'], $f['runId']);
        $this->assertSame($trace, $detail['steps'][0]['trace_id']);
        $this->assertSame($trace, $detail['requests'][0]['trace_id']);
        $this->assertSame($trace, $detail['artifacts'][0]['trace_id']);
        foreach (['generation_runs', 'generation_steps', 'generation_artifacts', 'ai_requests', 'cost_ledger'] as $table) {
            $this->assertTrue(DB::table('audit_events')->where('trace_id', $trace)->where('object_type', $table)->exists(), $table);
        }
        $p = app(RecoveryService::class)->preview($f['actor'], $f['novelId'], $f['runId']);
        $response = $this->actingAs($f['actor'])->postJson('/api/v1/novels/'.$f['novelId'].'/runs/'.$f['runId'].'/recovery/pause', ['reason' => '人工核查', 'preview_hash' => $p['preview_hash']], ['Idempotency-Key' => 'c07-pause'])->assertOk();
        $op = $response->headers->get('X-Trace-Id');
        $this->assertNotEmpty($op);
        $audit = DB::table('audit_events')->where('object_type', 'recovery_decisions')->where('operation_trace_id', $op)->first();
        $this->assertSame($trace, $audit->trace_id);
        $this->assertSame('人工核查', $audit->reason);
        $this->assertSame('', DB::selectOne("SELECT current_setting('novelforge.trace_id',true) AS value")->value);
        $this->assertStringNotContainsString('fixture-secret', json_encode($s->audit($f['actor'], ['trace_id' => $trace])));
        Http::assertNothingSent();
    }

    public function test_page_return_reads_real_progress_without_triggering_execution(): void
    {
        $f = QueuedRunFixture::create();
        $page = Livewire::actingAs($f['actor'])->test(RunCenter::class)->set('novel', $f['novelId'])->set('run', $f['runId']);
        $this->assertDatabaseCount('generation_steps', 0);
        // 模拟页面离开后独立服务实例执行；既有C02进程测试验证实际Worker持续运行。
        (new GenerationService)->advance($f['actor'], $f['novelId'], $f['runId'], 'first', new FakeStageExecutor);
        $page->call('$refresh')->assertSee('waiting_dependency');
        $rows = app(RunCenterService::class)->listing($f['actor'])['rows'];
        $this->assertSame(1, $rows[0]['steps_succeeded']);
        $this->assertSame(2, $rows[0]['steps_total']);
        $this->assertNull($rows[0]['canonical_chapters']);
        $this->assertDatabaseCount('generation_steps', 1);
    }

    public function test_truncated_preview_has_full_private_download_and_escaped_view(): void
    {
        $f = QueuedRunFixture::create();
        $g = app(GenerationService::class);
        $claim = $g->claim($f['actor'], $f['novelId'], $f['runId'], 'first');
        $text = '<script>alert(1)</script>'.str_repeat('中文证据', 400);
        $g->complete($f['actor'], $f['novelId'], $f['runId'], $claim, new StageResult('succeeded', ['text' => $text]));
        $a = app(RunCenterService::class)->detail($f['actor'], $f['novelId'], $f['runId'])['artifacts'][0];
        $this->assertTrue($a['truncated']);
        $this->assertSame(500, mb_strlen($a['preview']));
        $this->get($a['download_url'])->assertUnauthorized();
        $response = $this->actingAs($f['actor'])->get($a['download_url'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($text, json_decode($response->getContent(), true)['text']);
        Livewire::actingAs($f['actor'])->test(RunCenter::class)->set('novel', $f['novelId'])->set('run', $f['runId'])->assertSee('摘要已截断')->assertDontSeeHtml('<script>alert(1)</script>');
        $this->assertTrue(DB::table('audit_events')->where('action', 'download')->exists());
        $other = User::factory()->create();
        $other->is_admin = true;
        $this->actingAs($other)->get($a['download_url'])->assertNotFound();
    }

    public function test_unknown_cost_and_expired_lease_cannot_be_hidden_by_success_counts(): void
    {
        $f = ProviderFixture::create();
        app(ProviderGateway::class)->execute($f['actor'], $f['novelId'], $f['claim']['id'], $f['model']['version_id'], $f['input'], 'unknown', new FakeProvider(timeout: true));
        DB::table('generation_steps')->where('id', $f['claim']['id'])->update(['lease_until' => now()->subMinute()]);
        $s = app(RunCenterService::class);
        $health = $s->health($f['actor']);
        $this->assertSame('incident', $health['state']);
        $this->assertSame(1, $health['unknown_cost_requests']);
        $this->assertSame('unknown', $health['worker_processes']);
        $d = $s->detail($f['actor'], $f['novelId'], $f['runId']);
        $this->assertNull($d['requests'][0]['billing']['actual_cost']);
        $this->assertTrue($d['steps'][0]['lease_expired']);
        Livewire::actingAs($f['actor'])->test(AuditCenter::class)->assertSee('incident');
    }

    public function test_private_provider_evidence_and_same_run_scope(): void
    {
        $f = ProviderFixture::create();
        $r = app(ProviderGateway::class)->execute($f['actor'], $f['novelId'], $f['claim']['id'], $f['model']['version_id'], $f['input'], 'evidence', new FakeProvider);
        $url = app(RunCenterService::class)->detail($f['actor'], $f['novelId'], $f['runId'])['requests'][0]['evidence'][0]['download_url'];
        $this->actingAs($f['actor'])->get($url)->assertOk()->assertDontSee('fixture-secret-key-123');
        $otherRun = DB::table('generation_runs')->where('novel_id', $f['novelId'])->where('id', '!=', $f['runId'])->value('id');
        $this->get(str_replace('/runs/'.$f['runId'].'/', '/runs/'.$otherRun.'/', $url))->assertNotFound();
        $this->assertStringNotContainsString('fixture-secret-key-123', DB::table('audit_events')->get()->toJson());
    }

    public function test_rejected_command_records_safe_failure_trace_and_no_false_success(): void
    {
        $f = QueuedRunFixture::create();
        $response = $this->actingAs($f['actor'])->postJson('/api/v1/novels/'.$f['novelId'].'/runs/'.$f['runId'].'/recovery/cancel', ['reason' => '拒绝陈旧操作', 'preview_hash' => str_repeat('0', 64)], ['Idempotency-Key' => 'reject'])->assertConflict();
        $trace = $response->json('trace_id');
        $this->assertNotEmpty($trace);
        $this->assertTrue(DB::table('audit_events')->where('operation_trace_id', $trace)->where('run_id', $f['runId'])->where('action', 'rejected')->exists());
        $this->assertTrue(DB::table('audit_events')->where('operation_trace_id', $trace)->where('action', 'rejected')->exists());
        $this->assertSame('queued', DB::table('generation_runs')->where('id', $f['runId'])->value('status'));
        $this->assertSame('', DB::selectOne("SELECT current_setting('novelforge.reason',true) AS value")->value);
        $other = User::factory()->create();
        $this->actingAs($other)->getJson('/api/v1/runs')->assertForbidden();
        $this->getJson('/api/v1/audit')->assertForbidden();
    }

    public function test_failed_command_preserves_trace_for_full_bigint_run_identifier(): void
    {
        $f = QueuedRunFixture::create();
        // 直接构造有效的19位标识，验证审计链不会因PHP或浏览器精度限制丢失关联。
        $run = (array) DB::table('generation_runs')->where('id', $f['runId'])->first();
        $run['id'] = '9000000000000000000';
        $run['command_key'] = 'large-id';
        DB::table('generation_runs')->insert($run);
        $response = $this->actingAs($f['actor'])->postJson('/api/v1/novels/'.$f['novelId'].'/runs/'.$run['id'].'/recovery/cancel', ['reason' => '大标识验收', 'preview_hash' => str_repeat('0', 64)], ['Idempotency-Key' => 'large-reject'])->assertConflict();
        $this->assertTrue(DB::table('audit_events')->where('operation_trace_id', $response->json('trace_id'))->where('run_id', $run['id'])->where('action', 'rejected')->exists());
    }

    public function test_audit_append_only_transaction_rollback_filters_and_export(): void
    {
        $f = QueuedRunFixture::create();
        $count = DB::table('audit_events')->count();
        try {
            DB::transaction(function () use ($f) {
                DB::table('generation_runs')->where('id', $f['runId'])->update(['status' => 'paused']);
                throw new \RuntimeException;
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame($count, DB::table('audit_events')->count());
        foreach (['update', 'delete'] as $action) {
            try {
                DB::transaction(fn () => $action === 'update' ? DB::table('audit_events')->update(['outcome' => '伪造']) : DB::table('audit_events')->delete());
                $this->fail();
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        $trace = DB::table('run_traces')->where('run_id', $f['runId'])->value('trace_id');
        $this->actingAs($f['actor'])->getJson('/api/v1/audit?trace_id='.$trace)->assertOk()->assertJsonPath('data.total', 1);
        $this->get('/api/v1/audit/export?trace_id='.$trace)->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=novelforge-audit-page.json');
        $this->getJson('/api/v1/runs?novel_id=invalid')->assertUnprocessable();
        $this->getJson('/api/v1/runs?status=failed')->assertJsonPath('data.total', 0);
        foreach (['run_traces', 'audit_events'] as $table) {
            $this->assertNotNull(DB::selectOne('SELECT obj_description(?::regclass) AS c', [$table])->c);
            foreach (DB::select('SELECT col_description(attrelid,attnum) AS c FROM pg_attribute WHERE attrelid=?::regclass AND attnum>0 AND NOT attisdropped', [$table]) as $column) {
                $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $column->c ?? '');
            }
        }
    }
}
