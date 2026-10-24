<?php

namespace Tests\Feature;

use App\Exceptions\NovelProblem;
use App\Filament\Resources\NovelResource\Pages\PlanningCandidates;
use App\Models\Novel;
use App\Models\Scene;
use App\Models\User;
use App\Services\BillingConfigurationService;
use App\Services\BlueprintService;
use App\Services\NovelIntentService;
use App\Services\PlanningPipelineService;
use App\Services\RecoveryService;
use App\Services\RollingPlanService;
use App\Services\StructureService;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\PlanningFixture as F;
use Tests\TestCase;

/** E01 的请求、预算、来源与回执全部落在 PostgreSQL；不调用真实模型。 */
class PlanningPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_intake_goes_through_gateway_and_requires_author_decision(): void
    {
        $f = F::create();
        $original = Novel::find($f['novel'])->row_version;
        $stage = F::start($f, 'intake', F::intake($f));
        $stage = F::execute($f, $stage);
        $this->assertSame('succeeded', $stage['data']['run']['status']);
        $this->assertSame($original, Novel::find($f['novel'])->row_version);
        $this->assertDatabaseCount('planning_adoptions', 0);
        $request = DB::table('ai_requests')->where('run_id', $stage['data']['run']['id'])->first();
        $this->assertSame('succeeded', $request->status);
        $this->assertSame('not_billable_fake', $request->cost_status);
        $this->assertSame('settled', DB::table('budget_reservations')->where('request_id', $request->id)->value('status'));
        $adopted = F::adopt($f, $stage);
        $this->assertNotNull($adopted['adoption']);
        $this->assertSame(['unresolved'], array_values(array_unique(array_column(app(NovelIntentService::class)->requirements(Novel::find($f['novel'])), 'decision_status'))));
        $this->assertSame(0, Novel::find($f['novel'])->state_version);
        Http::assertNothingSent();
    }

    public function test_missing_special_detail_blocks_without_importing_and_resume_reuses_valid_artifact(): void
    {
        $f = F::create();
        $good = F::intake($f);
        $route = F::route($f, 'intake', $good);
        $bad = $good;
        $bad['requirements'][1]['content'] = '普通悬疑故事';
        $stage = F::execute($f, F::start($f, 'intake', $bad, route: $route));
        $this->assertSame('blocked', $stage['data']['run']['status']);
        $this->assertSame('PLANNING_COVERAGE_MISSING', $stage['data']['steps'][0]['error_code']);
        $this->assertDatabaseCount('planning_adoptions', 0);
        $blockedPreview = app(RecoveryService::class)->preview($f['actor'], $f['novel'], $stage['data']['run']['id']);
        app(RecoveryService::class)->execute($f['actor'], $f['novel'], $stage['data']['run']['id'], 'cancel', ['preview_hash' => $blockedPreview['preview_hash'], 'reason' => '保留失败，另开正确候选'], 'cancel-bad');
        $valid = F::execute($f, F::start($f, 'intake', $good, route: $route));
        $calls = DB::table('ai_requests')->count();
        F::execute($f, $valid);
        $this->assertSame($calls, DB::table('ai_requests')->count());
        $preview = app(RecoveryService::class)->preview($f['actor'], $f['novel'], $valid['data']['run']['id']);
        $this->assertSame('reuse', $preview['rows'][0]['disposition']);
        $this->assertSame('planning.intake-v1', $valid['data']['artifacts'][0]['schema_version']);
    }

    public function test_api_authentication_and_comment_coverage(): void
    {
        $f = F::create();
        $url = '/api/v1/novels/'.$f['novel'].'/planning-runs';
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['is_admin' => false]))->getJson($url)->assertForbidden();
        $this->actingAs($f['actor'])->getJson($url)->assertOk();
        foreach (['planning_stages', 'planning_adoptions'] as $table) {
            $this->assertNotEmpty(DB::selectOne('SELECT obj_description(?::regclass) AS c', [$table])->c);
            $missing = DB::selectOne('SELECT count(*) AS n FROM pg_attribute WHERE attrelid=?::regclass AND attnum>0 AND NOT attisdropped AND col_description(attrelid,attnum) IS NULL', [$table]);
            $this->assertSame(0, (int) $missing->n);
        }
    }

    public function test_stale_source_blocks_and_database_preserves_history(): void
    {
        $f = F::create();
        $stage = F::start($f, 'intake', F::intake($f));
        DB::table('novels')->where('id', $f['novel'])->increment('row_version');
        try {
            F::execute($f, $stage);
            $this->fail('过期输入不能执行');
        } catch (NovelProblem $e) {
            $this->assertSame('DEPENDENCY_MISMATCH', $e->errorCode);
        }
        $this->assertDatabaseCount('ai_requests', 0);
        $this->expectException(QueryException::class);
        DB::table('planning_stages')->where('id', $stage['id'])->delete();
    }

    public function test_five_phase_candidates_keep_detail_and_existing_author_freeze_contract(): void
    {
        $f = F::create(10);
        $service = app(PlanningPipelineService::class);
        $intake = F::adopt($f, F::execute($f, F::start($f, 'intake', F::intake($f))));
        F::confirmRequirements($f);
        $requirements = app(NovelIntentService::class)->requirements(Novel::find($f['novel']));
        $text = implode("\n", array_column($requirements, 'source_quote'));
        $bpOutput = $f['payload'];
        unset($bpOutput['outline_version_id'], $bpOutput['author_checks']);
        $bpOutput['foundation_nodes'] = [];
        $bpOutput['coverages'] = array_map(fn ($r) => ['requirement_version_id' => $r['id'], 'field' => 'body.synopsis', 'quote' => $r['source_quote']], $requirements);
        $blueprint = F::adopt($f, F::execute($f, F::start($f, 'blueprint', $bpOutput, $intake['id'])));
        $this->assertFalse(DB::table('blueprint_approvals')->where('blueprint_version_id', $blueprint['adoption']['result']['blueprint_id'])->exists());
        $approve = function (string $blueprintId) use ($f) {
            $service = app(BlueprintService::class);
            $old = $service->read($f['actor'], $f['novel'], $blueprintId);
            $payload = array_intersect_key($old['content'], array_flip(['body', 'entity_version_ids', 'threads', 'ending', 'coverages']));
            foreach ($payload['threads'] as &$thread) {
                unset($thread['identity_snapshot']);
            } unset($thread);
            $snapshot = app(StructureService::class)->execute($f['actor'], $f['novel'], 'snapshot', ['expected_version' => Novel::find($f['novel'])->row_version, 'reason' => '作者复核当前结构'], (string) Str::uuid())['version'];
            $call = fn ($action, $data) => $service->execute($f['actor'], $f['novel'], $action, $data + ['expected_version' => Novel::find($f['novel'])->row_version, 'reason' => '作者独立复核并批准'], (string) Str::uuid());
            $bp = $call('save', $payload + ['outline_version_id' => $snapshot['id'], 'author_checks' => array_fill_keys(BlueprintService::CHECKS, true)])['blueprint'];
            $check = $call('validate', ['blueprint_version_id' => $bp['id']])['validation'];
            $this->assertTrue($check['report']['passed'], json_encode($check['report']));
            $call('approve', ['blueprint_version_id' => $bp['id'], 'validation_id' => $check['id'], 'report_hash' => $check['report_hash'], 'acknowledge_impact' => true]);

            return $bp['id'];
        };
        $approved = $approve($blueprint['adoption']['result']['blueprint_id']);
        $last = $blueprint;
        $coverage = [];
        foreach (['outline' => 'story_arc', 'chapter_plan' => 'chapter', 'scene_plan' => 'scene'] as $phase => $type) {
            $rows = StructureService::MODELS[$type]::where('novel_id', $f['novel'])->orderBy('id')->get();
            $nodes = [];
            foreach ($rows as $i => $row) {
                $node = F::node($row->toArray(), 'n'.($i + 1), $type, $text);
                if ($type === 'story_arc') {
                    $node['thread_ids'] = [$f['thread']['id']];
                }
                if ($type === 'chapter') {
                    $node['details'] = ['min_words' => 2000, 'target_words' => 2500, 'max_words' => 3000];
                }
                if ($type === 'scene') {
                    $node['details'] = array_fill_keys(RollingPlanService::SCENE_FIELDS, '作者明确记录的场景信息');
                    $node['details'] += ['min_words' => 2000, 'target_words' => 2500, 'max_words' => 3000, 'pov_version_id' => $f['person']['definition']['id']];
                    $node['details']['pov'] = '许砚';
                }
                $nodes[] = $node;
            }
            $output = ['nodes' => $nodes, 'coverages' => array_map(fn ($r) => ['requirement_version_id' => $r['id'], 'node_key' => 'n1', 'field' => 'summary', 'quote' => $r['source_quote']], $requirements)];
            $stage = F::execute($f, F::start($f, $phase, $output, $last['id']));
            $this->assertSame('succeeded', $stage['data']['run']['status'], json_encode($stage['data']['steps']));
            $this->assertStringContainsString('铜钥匙缺口', json_encode($stage['data']['artifacts'], JSON_UNESCAPED_UNICODE));
            $last = F::adopt($f, $stage);
            $coverage = array_merge($coverage, $last['adoption']['result']['suggested_coverages']);
        }
        $approved = $approve($approved);
        $plans = app(RollingPlanService::class);
        $call = fn ($action, $data) => $plans->execute($f['actor'], $f['novel'], $action, $data + ['expected_version' => Novel::find($f['novel'])->row_version, 'reason' => '作者检查覆盖后冻结'], (string) Str::uuid());
        $plan = $call('save', ['blueprint_version_id' => $approved, 'start_chapter' => 1, 'end_chapter' => 10, 'coverages' => $coverage])['plan'];
        $check = $call('validate', ['rolling_plan_id' => $plan['id']])['validation'];
        $this->assertTrue($check['report']['passed'], json_encode($check['report']));
        $call('freeze', ['rolling_plan_id' => $plan['id'], 'validation_id' => $check['id'], 'report_hash' => $check['report_hash']]);
        $this->assertDatabaseCount('planning_stages', 5);
        $this->assertDatabaseCount('planning_adoptions', 5);
        $this->assertDatabaseCount('plan_freezes', 1);
        $this->assertSame(0, Novel::find($f['novel'])->state_version);
        $this->assertSame(5, DB::table('ai_requests')->count());
        Http::assertNothingSent();
    }

    public function test_fresh_intake_has_no_outline_and_only_registered_offline_execution_works_outside_testing(): void
    {
        $f = F::create();
        $book = app(NovelIntentService::class)->execute($f['actor'], 'create', null, ['title' => '新创意', 'genre' => '悬疑', 'background' => '档案馆不允许复制钥匙', 'premise_raw' => '断齿铜钥匙只能开档案馆第三道锁', 'target_chapters' => 10, 'length_policy' => ['min' => 2000, 'max' => 3000]], (string) Str::uuid());
        $f['novel'] = $book['novel']['id'];
        $this->assertSame(0, DB::table('outline_versions')->where('novel_id', $f['novel'])->count());
        $stage = F::start($f, 'intake', F::intake($f));
        $this->assertNull($stage['data']['run']['outline_version_id']);
        // 仅模拟应用环境标识；数据库仍由测试引导严格限定为 novel_forge_test。
        app()->detectEnvironment(fn () => 'local');
        try {
            $stage = F::execute($f, $stage);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
        $this->assertSame('succeeded', $stage['data']['run']['status']);
        $this->assertSame('novel_forge_test', DB::selectOne('select current_database() as n')->n);
        Http::assertNothingSent();
    }

    public function test_malformed_response_and_budget_defer_have_no_candidate_side_effects(): void
    {
        $f = F::create();
        $route = F::route($f, 'intake', F::intake($f));
        $bad = F::execute($f, F::start($f, 'intake', ['unexpected' => '越权自动批准'], route: $route));
        $this->assertSame('INVALID_STRUCTURED_OUTPUT', $bad['data']['steps'][0]['error_code']);
        $this->assertSame('blocked', $bad['data']['run']['status']);
        $this->assertDatabaseCount('planning_adoptions', 0);
        $this->assertSame(2, DB::table('ai_request_evidence')->count());
        $preview = app(RecoveryService::class)->preview($f['actor'], $f['novel'], $bad['data']['run']['id']);
        app(RecoveryService::class)->execute($f['actor'], $f['novel'], $bad['data']['run']['id'], 'cancel', ['preview_hash' => $preview['preview_hash'], 'reason' => '保留非法响应证据'], 'cancel-invalid');
        $policy = app(BillingConfigurationService::class)->execute($f['actor'], 'policy', ['key' => 'no-budget', 'expected_version' => 0, 'currency' => 'USD', 'limits' => array_fill_keys(['request', 'chapter', 'run', 'book', 'day'], '0'), 'warning_percent' => 80, 'stop_percent' => 100, 'timezone' => 'UTC', 'reason' => '预算阻塞验证'], 'zero-policy');
        $f['billing']['policy_id'] = $policy['id'];
        $deferred = F::execute($f, F::start($f, 'intake', F::intake($f), route: $route));
        $this->assertSame('deferred', $deferred['data']['run']['status']);
        $this->assertSame('DEFERRED_BUDGET', $deferred['data']['steps'][0]['error_code']);
        $this->assertSame(1, DB::table('ai_requests')->count());
    }

    public function test_http_idempotency_and_ui_compare_use_persisted_candidates(): void
    {
        $f = F::create();
        $route = F::route($f, 'intake', F::intake($f));
        $d = ['phase' => 'intake', 'route_version_id' => $route, 'billing' => $f['billing'], 'offline_output' => F::intake($f), 'expected_version' => Novel::find($f['novel'])->row_version, 'reason' => '接口离线候选'];
        $url = '/api/v1/novels/'.$f['novel'].'/planning-runs';
        $this->actingAs($f['actor'])->postJson($url, $d)->assertUnprocessable();
        $result = $this->withHeader('Idempotency-Key', 'e01-start')->postJson($url, $d)->assertStatus(202)->json('data');
        $done = F::execute($f, $result);
        $this->withHeader('Idempotency-Key', 'e01-start')->postJson($url, $d)->assertStatus(202)->assertJsonPath('data.data.run.status', 'queued')->assertJsonPath('data.id', $result['id']);
        $this->withHeader('Idempotency-Key', 'e01-start')->postJson($url, array_replace($d, ['reason' => '不同输入']))->assertConflict();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(PlanningCandidates::class, ['record' => $f['novel']])->set('selected', $done['id'])->set('comparison', $done['id'])->call('inspect')->assertSet('detail.id', $done['id'])->assertSet('previous.id', $done['id'])->assertSee('铜钥匙缺口')->set('reason', '作者从比较页面采纳')->call('adopt')->assertSet('detail.adoption.reason', '作者从比较页面采纳');
        $this->assertDatabaseCount('planning_adoptions', 1);
        $this->assertSame(0, Novel::find($f['novel'])->state_version);
    }

    public function test_illegal_hierarchy_and_missing_layer_coverage_are_blocked(): void
    {
        $f = F::create();
        $intake = F::adopt($f, F::execute($f, F::start($f, 'intake', F::intake($f))));
        F::confirmRequirements($f);
        $r = app(NovelIntentService::class)->requirements(Novel::find($f['novel']));
        $output = $f['payload'];
        unset($output['outline_version_id'], $output['author_checks']);
        $output['foundation_nodes'] = [];
        $output['coverages'] = array_map(fn ($req) => ['requirement_version_id' => $req['id'], 'field' => 'body.synopsis', 'quote' => $req['source_quote']], $r);
        $route = F::route($f, 'blueprint', $output);
        $bad = $output;
        $bad['coverages'] = [];
        $stage = F::execute($f, F::start($f, 'blueprint', $bad, $intake['id'], $route));
        $this->assertSame('PLANNING_COVERAGE_MISSING', $stage['data']['steps'][0]['error_code']);
        $preview = app(RecoveryService::class)->preview($f['actor'], $f['novel'], $stage['data']['run']['id']);
        app(RecoveryService::class)->execute($f['actor'], $f['novel'], $stage['data']['run']['id'], 'cancel', ['preview_hash' => $preview['preview_hash'], 'reason' => '保留缺失覆盖候选'], 'cancel-coverage');
        $node = F::node(Scene::where('novel_id', $f['novel'])->first()->toArray(), 'wrong', 'scene', '不能在蓝图中写场景');
        $bad = $output;
        $bad['foundation_nodes'] = [$node];
        $stage = F::execute($f, F::start($f, 'blueprint', $bad, $intake['id'], $route));
        $this->assertSame('INVALID_PLANNING_HIERARCHY', $stage['data']['steps'][0]['error_code']);
        $this->assertSame(1, DB::table('planning_adoptions')->count());
        $this->assertDatabaseCount('blueprint_versions', 0);
    }
}
