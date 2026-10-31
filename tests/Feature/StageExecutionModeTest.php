<?php

namespace Tests\Feature;

use App\Exceptions\NovelProblem;
use App\Filament\Resources\NovelResource\Pages\PlanningCandidates;
use App\Generation\StageExecutionMode;
use App\Models\Novel;
use App\Models\User;
use App\ProvidersGateway\FrozenResponsesAdapterFactory;
use App\ProvidersGateway\OpenAiResponsesFixtureAdapter;
use App\ProvidersGateway\ProviderReply;
use App\Services\ExtractionService;
use App\Services\PlanningPipelineService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\PlanningFixture;
use Tests\TestCase;

/** 验证真实/离线输入边界；所有响应均为内存夹具。 */
class StageExecutionModeTest extends TestCase
{
    use RefreshDatabase;

    private function problem(string $code, callable $call): void
    {
        try {
            $call();
            $this->fail('应阻止模式混用');
        } catch (NovelProblem $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    public function test_production_requires_no_replay_and_invalid_modes_fail_closed(): void
    {
        StageExecutionMode::validate(['execution_mode' => 'production']);
        $this->assertSame([], StageExecutionMode::node(['execution_mode' => 'production']));
        $this->problem('OFFLINE_OUTPUT_FORBIDDEN', fn () => StageExecutionMode::validate(['execution_mode' => 'production', 'offline_output' => []]));
        $this->problem('INVALID_EXECUTION_MODE', fn () => StageExecutionMode::mode(['execution_mode' => 'automatic']));
        $this->assertSame('offline_fake', StageExecutionMode::mode([]));
    }

    public function test_all_registered_stages_select_responses_from_frozen_mode_without_replay_output(): void
    {
        $adapter = new OpenAiResponsesFixtureAdapter(new ProviderReply(200, '{}'));
        $this->mock(FrozenResponsesAdapterFactory::class, function ($mock) use ($adapter) {
            $mock->shouldReceive('make')->times(9)->withArgs(fn ($frozen, $model) => $model === '123' && ! isset($frozen['nodes'][0]['input']['offline_output']))->andReturn($adapter);
        });
        $frozen = ['execution_mode' => 'production', 'configuration' => ['route' => ['content' => ['mode' => 'production']]], 'nodes' => [['input' => []]]];
        foreach (['planning.intake', 'planning.blueprint', 'planning.outline', 'planning.chapter_plan', 'planning.scene_plan', 'drafting.scene', 'extraction.events', 'review.chapter', 'revision.propose'] as $stage) {
            $this->assertSame($adapter, StageExecutionMode::adapter($frozen, $stage, '123'));
        }
        $frozen['execution_mode'] = 'offline_fake';
        $this->problem('EXECUTION_MODE_MISMATCH', fn () => StageExecutionMode::adapter($frozen, 'review.chapter', '123'));
    }

    public function test_source_preparation_validates_reason_without_requiring_fake_output(): void
    {
        try {
            app(ExtractionService::class)->prepareInput(User::factory()->make(), '1', [
                'chapter_version_id' => '1', 'body_hash' => str_repeat('0', 64),
                'expected_version' => 1, 'expected_state_version' => 0,
            ]);
            $this->fail('真实来源准备仍须校验操作原因');
        } catch (ValidationException $e) {
            $this->assertSame(['reason'], array_keys($e->errors()));
        }
    }

    public function test_backend_real_mode_does_not_parse_or_send_offline_editor_contents(): void
    {
        $f = PlanningFixture::create();
        $this->actingAs($f['actor']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $route = PlanningFixture::route($f, 'intake', PlanningFixture::intake($f));
        $before = DB::table('generation_runs')->count();
        $page = Livewire::test(PlanningCandidates::class, ['record' => $f['novel']])
            ->set('executionMode', 'production')->set('route', $route)
            ->set('policy', $f['billing']['policy_id'])->set('prices', implode(',', $f['billing']['price_version_ids']))
            ->set('offlineOutput', '{无效离线JSON')->set('reason', '后台模式边界测试')
            ->call('startCandidate')->assertSet('notice', '所选执行模式与冻结路由不一致。');
        $this->assertSame('{无效离线JSON', $page->get('offlineOutput'));
        $this->assertSame($before, DB::table('generation_runs')->count());
        $this->assertDatabaseCount('ai_requests', 0);
    }

    public function test_real_planning_rejects_offline_route_without_request_or_fake_response_validation(): void
    {
        $f = PlanningFixture::create();
        $output = PlanningFixture::intake($f);
        $route = PlanningFixture::route($f, 'intake', $output);
        $runs = DB::table('generation_runs')->count();
        $this->problem('EXECUTION_MODE_MISMATCH', fn () => app(PlanningPipelineService::class)->start($f['actor'], $f['novel'], [
            'execution_mode' => 'production', 'phase' => 'intake', 'route_version_id' => $route, 'billing' => $f['billing'],
            'expected_version' => Novel::find($f['novel'])->row_version, 'reason' => '真实模式门禁验证',
        ], 'production-mode'));
        $this->assertDatabaseCount('generation_runs', $runs);
        $this->assertDatabaseCount('ai_requests', 0);
    }
}
