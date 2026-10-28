<?php

namespace Tests\Feature;

use App\Filament\Resources\NovelResource\Pages\Workspace;
use App\Models\Novel;
use App\Models\User;
use App\ProvidersGateway\FakeProvider;
use App\ProvidersGateway\ProviderReply;
use App\Services\BillingConfigurationService;
use App\Services\BudgetService;
use App\Services\DraftingService;
use App\Services\GenerationService;
use App\Services\NovelIntentService;
use App\Services\ProviderGateway;
use App\Services\WorkspaceProductionService;
use App\Services\WorkspaceService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\CanonicalFixture as F;
use Tests\Support\ProviderFixture;
use Tests\TestCase;

/** UI14使用真实正式提交和预算链，页面读取不得写库或发送模型请求。 */
class WorkspaceProductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_root_then_commit_reads_exact_formal_version_and_retains_it_after_candidate_edit(): void
    {
        $f = F::create();
        $this->actingAs($f['actor']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $service = app(WorkspaceService::class);
        $empty = $service->summary($f['actor'], $f['novel']);
        $this->assertSame(0, $empty['production']['committed_chapters']);
        $this->assertSame(1, $empty['production']['next_chapter']['sequence']);
        $this->assertNull($empty['chapters']['items'][0]['review']);
        F::commit($f);
        $before = DB::table('canonical_commits')->count();
        $requests = DB::table('ai_requests')->count();
        $s = $service->summary($f['actor'], $f['novel']);
        $this->assertSame(1, $s['production']['committed_chapters']);
        $this->assertSame(2, $s['production']['next_chapter']['sequence']);
        $this->assertSame(1, $s['production']['index_lag']);
        $this->assertSame($f['body']['id'], $s['chapters']['items'][0]['formal_version']['id']);
        $this->assertSame($f['review']['id'], $s['chapters']['items'][0]['review']['id']);
        $this->assertFalse($s['generation_eligible']);
        app(DraftingService::class)->save($f['actor'], $f['novel'], 'chapter', $f['body']['node_id'], ['plan_id' => $f['plan']['id'], 'source_version_id' => $f['body']['id'], 'expected_version' => $f['body']['version'], 'body' => $f['body']['body'].'候选新增内容', 'title' => '候选新标题', 'reason' => '验证候选不覆盖工作台正式身份'], 'ui14-candidate');
        $again = $service->summary($f['actor'], $f['novel']);
        $this->assertSame($s['chapters']['items'][0]['formal_version'], $again['chapters']['items'][0]['formal_version']);
        Livewire::test(Workspace::class, ['record' => $f['novel']])->assertSee('已正式提交 1 章')->assertSee('正式 v1')->assertSee('提交时评审')->assertSee('真实生产启动尚未接入')->assertSee('formal-reader?novel='.$f['novel'], false);
        $filtered = $service->summary($f['actor'], $f['novel'], ['from' => 2]);
        $this->assertSame(1, $filtered['production']['committed_chapters']);
        $this->assertNull($filtered['chapters']['items'][0]['formal_version']);
        $this->assertDatabaseCount('canonical_commits', $before);
        $this->assertDatabaseCount('ai_requests', $requests);
        Http::assertNothingSent();
    }

    public function test_cross_book_reads_and_archived_navigation_do_not_reuse_formal_progress(): void
    {
        $f = F::create();
        F::commit($f);
        $other = app(NovelIntentService::class)->execute($f['actor'], 'create', null, ['title' => '另一本书', 'genre' => '悬疑', 'background' => '隔离', 'premise_raw' => '隔离本书证据', 'target_chapters' => 10, 'length_policy' => ['min' => 2000, 'max' => 3000]], 'ui14-other')['novel']['id'];
        $s = app(WorkspaceProductionService::class)->read($f['actor'], $other, [$f['body']['node_id']]);
        $this->assertSame('uninitialized', $s['production_state']);
        $this->assertNull($s['production']['committed_chapters']);
        $this->assertNull($s['production']['recent_run']);
        $this->assertNull($s['production']['budget_remaining']);
        $this->assertSame([], $s['formal_versions']);
        foreach ($s['capabilities'] as $entry) {
            if ($entry['available']) {
                $this->assertStringContainsString('novel='.$other, $entry['url']);
            }
        }
        app(NovelIntentService::class)->execute($f['actor'], 'archive', $f['novel'], ['expected_version' => Novel::find($f['novel'])->row_version], 'ui14-archive');
        $s = app(WorkspaceService::class)->summary($f['actor'], $f['novel']);
        $this->assertSame(1, $s['production']['committed_chapters']);
        $this->assertFalse($s['generation_eligible']);
        $this->assertStringContainsString('恢复', $s['unavailable_reason']);
        $this->actingAs($f['actor'])->get('/admin/formal-reader?novel='.$f['novel'])->assertOk();
        $stranger = User::factory()->create();
        Novel::whereKey($other)->update(['owner_id' => $stranger->id]);
        $this->getJson('/api/v1/novels/'.$other)->assertNotFound();
        $this->actingAs($stranger)->getJson('/api/v1/novels/'.$f['novel'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_unknown_cost_and_reconciled_zero_are_distinct_with_precise_budget_reference(): void
    {
        $f = ProviderFixture::create();
        $reply = new ProviderReply(200, '{"output":{"text":"ok"},"request_id":"ui14-no-usage"}');
        $request = app(ProviderGateway::class)->execute($f['actor'], $f['novelId'], $f['claim']['id'], $f['model']['version_id'], $f['input'], 'ui14-unknown', new FakeProvider($reply));
        $service = app(WorkspaceProductionService::class);
        $s = $service->read($f['actor'], $f['novelId'])['production'];
        $this->assertSame('unknown', $s['today_cost']['state']);
        $this->assertNull($s['today_cost']['rows'][0]['settled_cost']);
        $this->assertSame(1, $s['today_cost']['rows'][0]['unknown_count']);
        $this->assertSame('0.0083920000', $s['budget_remaining']['exposure']);
        $this->assertSame('99.9916080000', $s['budget_remaining']['remaining']);
        app(BudgetService::class)->reconcile($f['actor'], ['request_id' => $request['id'], 'usage' => ['input_tokens' => 0, 'output_tokens' => 0], 'external_charge_id' => 'ui14-confirmed-zero', 'evidence_ref' => '明确离线零金额凭证，非实际供应商']);
        $s = $service->read($f['actor'], $f['novelId'])['production'];
        $this->assertSame('known', $s['today_cost']['state']);
        $this->assertSame('0.0000000000', $s['today_cost']['rows'][0]['settled_cost']);
        $this->assertSame('100.0000000000', $s['budget_remaining']['remaining']);
        // 日期移动只改变今日范围，不抹去全书预算和历史运行。
        $this->travel(2)->days();
        $s = $service->read($f['actor'], $f['novelId'])['production'];
        $this->assertSame('empty', $s['today_cost']['state']);
        $this->assertNotNull($s['budget_remaining']);
        $this->assertNotNull($s['recent_run']);
        $this->travelBack();
        Http::assertNothingSent();
    }

    public function test_currencies_are_not_added_and_reference_policy_is_explicit(): void
    {
        $f = ProviderFixture::create();
        $gateway = app(ProviderGateway::class);
        $send = fn ($key) => $gateway->execute($f['actor'], $f['novelId'], $f['claim']['id'], $f['model']['version_id'], $f['input'], $key, new FakeProvider);
        $send('ui14-usd');
        $config = app(BillingConfigurationService::class);
        $price = $config->row('price_versions', $f['payload']['billing']['price_version_ids'][0]);
        $newPrice = $config->execute($f['actor'], 'price', ['model_version_id' => $f['model']['version_id'], 'expected_version' => 1, 'currency' => 'CNY', 'unit_prices' => $price['unit_prices'], 'cache_mode' => 'none', 'reasoning_mode' => 'none', 'effective_at' => '2020-01-01T00:00:00+00:00', 'evidence_ref' => 'UI14虚构人民币报价，仅用于币种隔离'], 'ui14-cny-price');
        $policy = $config->execute($f['actor'], 'policy', ['key' => 'ui14.cny', 'expected_version' => 0, 'currency' => 'CNY', 'limits' => array_fill_keys(['request', 'chapter', 'run', 'book', 'day'], '100'), 'warning_percent' => 80, 'stop_percent' => 100, 'timezone' => 'UTC', 'reason' => '虚构人民币测试政策'], 'ui14-cny-policy');
        $f['payload']['billing']['price_version_ids'] = [$newPrice['id']];
        $f['payload']['billing']['policy_id'] = $policy['id'];
        $g = app(GenerationService::class);
        $run = $g->start($f['actor'], $f['novelId'], $f['payload'], 'ui14-cny-run')['run']['id'];
        $claim = $g->claim($f['actor'], $f['novelId'], $run, 'first');
        $gateway->execute($f['actor'], $f['novelId'], $claim['id'], $f['model']['version_id'], $f['input'], 'ui14-cny-request', new FakeProvider);
        $s = app(WorkspaceProductionService::class)->read($f['actor'], $f['novelId'])['production'];
        $this->assertSame(['CNY', 'USD'], array_column($s['today_cost']['rows'], 'currency'));
        $this->assertSame(['0.0000110000', '0.0000110000'], array_column($s['today_cost']['rows'], 'settled_cost'));
        $this->assertSame('CNY', $s['budget_remaining']['currency']);
        $this->assertSame('99.9999890000', $s['budget_remaining']['remaining']);
        $this->assertSame((string) $run, $s['recent_run']['id']);
        Http::assertNothingSent();
    }

    public function test_projection_excludes_old_revision_and_fails_closed_on_incomplete_active_prefix(): void
    {
        $f = F::create();
        F::commit($f);
        // G01尚未实现：只在事务临时表中模拟切换后的读取形态，不修改正式历史或声称重基线已验收。
        DB::statement('CREATE TEMP TABLE novels ON COMMIT DROP AS SELECT * FROM public.novels');
        DB::statement('CREATE TEMP TABLE canonical_revisions ON COMMIT DROP AS SELECT * FROM public.canonical_revisions');
        $root = (array) DB::table('canonical_revisions')->where('id', $f['revision'])->first();
        $root['id'] = (int) $root['id'] + 1000000;
        $root['version'] = 2;
        $root['cursor'] = 0;
        $root['local_state_version'] = 0;
        DB::table('canonical_revisions')->where('id', $f['revision'])->update(['status' => 'superseded']);
        DB::table('canonical_revisions')->insert($root);
        DB::table('novels')->where('id', $f['novel'])->update(['active_canonical_revision_id' => $root['id']]);
        $service = app(WorkspaceProductionService::class);
        $s = $service->read($f['actor'], $f['novel'], [$f['body']['node_id']]);
        $this->assertSame('ready', $s['production_state']);
        $this->assertSame(0, $s['production']['committed_chapters']);
        $this->assertSame(0, $s['production']['review_issues']);
        $this->assertSame(0, $s['production']['index_lag']);
        $this->assertSame([], $s['formal_versions']);
        $this->assertNotNull($s['production']['recent_run']);
        DB::table('canonical_revisions')->where('id', $root['id'])->update(['cursor' => 1]);
        $s = $service->read($f['actor'], $f['novel'], [$f['body']['node_id']]);
        $this->assertSame('unavailable', $s['production_state']);
        $this->assertNull($s['production']['committed_chapters']);
        $this->assertNull($s['production']['review_issues']);
        $this->assertSame([], $s['formal_versions']);
        Http::assertNothingSent();
    }

    public function test_production_read_failure_is_retryable_and_never_rendered_as_zero_or_empty(): void
    {
        $f = ProviderFixture::create();
        $this->actingAs($f['actor']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->mock(WorkspaceProductionService::class, fn ($mock) => $mock->shouldReceive('read')->andThrow(new \RuntimeException('private projection diagnostics')));
        Livewire::test(Workspace::class, ['record' => $f['novelId']])->assertSee('规划状态暂时读取失败')->assertSee('重试')->assertDontSee('private projection diagnostics')->assertDontSee('已正式提交 0 章')->assertDontSee('尚未建立正式基线');
    }
}
