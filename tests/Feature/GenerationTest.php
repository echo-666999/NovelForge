<?php

namespace Tests\Feature;

use App\Exceptions\NovelProblem;
use App\Generation\StageExecutor;
use App\Generation\StageResult;
use App\Models\Novel;
use App\Models\User;
use App\Services\GenerationService;
use App\Services\NovelIntentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BlueprintFixture;
use Tests\Support\FakeStageExecutor;
use Tests\TestCase;

class GenerationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        $f = BlueprintFixture::create(chapterCount: 1, targetChapters: 10);
        $f['novelId'] = (string) $f['book']['novel']['id'];
        $f['payload'] = ['outline_version_id' => $f['outline']['id'], 'expected_version' => Novel::find($f['novelId'])->row_version,
            'nodes' => [
                ['key' => 'first', 'stage' => 'test.echo', 'depends_on' => [], 'input' => ['text' => '首段']],
                ['key' => 'second', 'stage' => 'test.echo', 'depends_on' => ['first'], 'input' => ['text' => '后段']],
            ]];

        return $f;
    }

    private function start(array $f, string $key = 'start'): array
    {
        return app(GenerationService::class)->start($f['actor'], $f['novelId'], $f['payload'], $key);
    }

    private function advance(array $f, array $run, string $key, StageExecutor $fake, ?int $retry = null): array
    {
        return (new GenerationService)->advance($f['actor'], $f['novelId'], $run['run']['id'], $key, $fake, $retry);
    }

    private function problem(string $code, callable $action): void
    {
        try {
            $action();
            $this->fail('应当拒绝该操作');
        } catch (NovelProblem $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    public function test_dag_restart_reuses_only_verified_success_and_requires_all_goals(): void
    {
        $f = $this->fixture();
        $r = $this->start($f);
        $fake = new FakeStageExecutor;
        $blocked = $this->advance($f, $r, 'second', $fake);
        $this->assertSame('waiting_dependency', $blocked['run']['status']);
        $this->assertCount(0, $blocked['steps']);
        $first = $this->advance($f, $r, 'first', $fake);
        $this->assertSame('waiting_dependency', $first['run']['status']);
        $again = $this->advance($f, $r, 'first', $fake);
        $this->assertSame($first['artifacts'], $again['artifacts']);
        $this->assertSame(1, $fake->calls);
        // 使用新的服务实例，从数据库恢复图与阶段状态。
        $restored = (new GenerationService)->read($f['actor'], $f['novelId'], $r['run']['id']);
        $done = $this->advance($f, $restored, 'second', $fake);
        $this->assertSame('succeeded', $done['run']['status']);
        $this->assertCount(2, $done['steps']);
        $this->assertSame($done['artifacts'][0]['id'], $done['steps'][1]['frozen_input']['dependencies'][0]['artifact_id']);
        $this->assertDatabaseCount('generation_dependencies', 1);
        $this->assertSame(0, Novel::find($f['novelId'])->state_version);
        Http::assertNothingSent();
    }

    public function test_idempotent_start_conflict_and_frozen_input(): void
    {
        $f = $this->fixture();
        $r = $this->start($f);
        Novel::whereKey($f['novelId'])->increment('row_version');
        $this->advance($f, $r, 'first', new FakeStageExecutor);
        $this->assertEquals($r, $this->start($f));
        $f['payload']['nodes'][0]['input']['text'] = '变化';
        $this->problem('IDEMPOTENCY_CONFLICT', fn () => $this->start($f));
        $this->assertDatabaseCount('generation_runs', 1);
        $this->assertSame('首段', $r['run']['frozen_config']['nodes'][0]['input']['text']);
    }

    public function test_cycle_unknown_duplicate_dependency_are_rejected(): void
    {
        $f = $this->fixture();
        foreach ([['second'], ['missing'], ['first', 'first']] as $deps) {
            $f['payload']['nodes'][0]['depends_on'] = $deps;
            $this->problem('INVALID_GRAPH', fn () => $this->start($f));
        }
        $this->assertDatabaseCount('generation_runs', 0);
    }

    public function test_deferred_checkpoint_retry_identity_and_limit(): void
    {
        $f = $this->fixture();
        $r = $this->start($f);
        $fake = new FakeStageExecutor('deferred');
        $one = $this->advance($f, $r, 'first', $fake);
        $this->assertSame('deferred', $one['run']['status']);
        $this->assertSame($one['artifacts'][0]['id'], $one['steps'][0]['checkpoint_artifact_id']);
        $this->advance($f, $r, 'first', $fake);
        $this->assertSame(1, $fake->calls);
        $two = $this->advance($f, $r, 'first', $fake, 1);
        $this->assertCount(2, $two['steps']);
        $this->assertSame($one['artifacts'][0]['id'], $two['steps'][1]['resume_artifact_id']);
        $this->assertSame($one['artifacts'][0]['id'], $two['steps'][1]['frozen_input']['resume_checkpoint']['artifact_id']);
        $this->assertSame($one['artifacts'][0]['content'], $fake->inputs[1]['checkpoint']['content']);
        $this->advance($f, $r, 'first', $fake, 1);
        $this->assertSame(2, $fake->calls);
        $this->advance($f, $r, 'first', $fake, 2);
        $this->problem('RETRY_LIMIT', fn () => $this->advance($f, $r, 'first', $fake, 3));
        $this->assertDatabaseCount('generation_steps', 3);
    }

    public function test_failed_blocked_canceled_are_distinct_and_history_preserved(): void
    {
        $f = $this->fixture();
        foreach (['failed', 'blocked', 'canceled'] as $status) {
            $r = $this->start($f, $status);
            $bad = $this->advance($f, $r, 'first', new FakeStageExecutor($status));
            $this->assertSame($status, $bad['run']['status']);
            $this->assertSame('TEST_'.strtoupper($status), $bad['steps'][0]['error_code']);
            if ($status !== 'canceled') {
                $repaired = $this->advance($f, $r, 'first', new FakeStageExecutor, 1);
                $this->assertSame($status, $repaired['steps'][0]['status']);
                $this->assertSame('succeeded', $repaired['steps'][1]['status']);
            } else {
                $this->problem('RETRY_LIMIT', fn () => $this->advance($f, $r, 'first', new FakeStageExecutor, 1));
            }
        }
    }

    public function test_state_change_blocks_reuse_and_quarantines_late_output(): void
    {
        $f = $this->fixture();
        $r = $this->start($f);
        $fake = new class($f['novelId']) implements StageExecutor
        {
            public function __construct(private string $id) {}

            public function execute(array $input): StageResult
            {
                // 仅模拟正式状态并发变化，不声称实现了 Canonical Commit。
                Novel::whereKey($this->id)->increment('state_version');

                return new StageResult('succeeded', ['text' => '迟到产物']);
            }
        };
        $late = $this->advance($f, $r, 'first', $fake);
        $this->assertSame('needs_attention', $late['run']['status']);
        $this->assertSame('quarantined', $late['steps'][0]['status']);
        $this->assertCount(1, $late['artifacts']);
        $this->problem('DEPENDENCY_MISMATCH', fn () => $this->advance($f, $r, 'first', new FakeStageExecutor));
    }

    public function test_invalid_output_and_exception_become_durable_failure_without_secret(): void
    {
        $f = $this->fixture();
        $r = $this->start($f);
        $fake = new class implements StageExecutor
        {
            public function execute(array $input): StageResult
            {
                throw new \RuntimeException('sensitive-secret');
            }
        };
        $failed = $this->advance($f, $r, 'first', $fake);
        $this->assertSame('failed', $failed['run']['status']);
        $this->assertSame('STAGE_EXECUTION_FAILED', $failed['steps'][0]['error_code']);
        $this->assertStringNotContainsString('sensitive-secret', json_encode($failed));
        $invalid = new class implements StageExecutor
        {
            public function execute(array $input): StageResult
            {
                return new StageResult('succeeded', ['wrong' => '字段不合法']);
            }
        };
        $again = $this->advance($f, $r, 'first', $invalid, 1);
        $this->assertSame('failed', $again['steps'][1]['status']);
    }

    public function test_nested_duplicate_dispatch_does_not_execute_twice(): void
    {
        $f = $this->fixture();
        $r = $this->start($f);
        $second = new FakeStageExecutor;
        $first = new class($f, $r, $second) implements StageExecutor
        {
            public function __construct(private array $f, private array $r, private FakeStageExecutor $second) {}

            public function execute(array $input): StageResult
            {
                app(GenerationService::class)->advance($this->f['actor'], $this->f['novelId'], $this->r['run']['id'], 'first', $this->second);

                return new StageResult('succeeded', ['text' => '唯一有效尝试']);
            }
        };
        $this->advance($f, $r, 'first', $first);
        $this->assertSame(0, $second->calls);
        $this->assertDatabaseCount('generation_steps', 1);
    }

    public function test_database_immutability_and_same_run_foreign_keys(): void
    {
        $f = $this->fixture();
        $r = $this->start($f);
        $done = $this->advance($f, $r, 'first', new FakeStageExecutor);
        $other = $this->start($f, 'other');
        $otherDone = $this->advance($f, $other, 'first', new FakeStageExecutor);
        // 保存点使每个预期数据库拒绝不会污染后续断言的事务。
        $bad = [
            fn () => DB::statement('INSERT INTO generation_steps (novel_id,run_id,stage,scope_key,attempt_no,frozen_input,dependency_hash,status,created_at,updated_at) SELECT novel_id,run_id,stage,scope_key,attempt_no,frozen_input,dependency_hash,status,created_at,updated_at FROM generation_steps WHERE id = ?', [$done['steps'][0]['id']]),
            fn () => DB::table('generation_artifacts')->where('id', $done['artifacts'][0]['id'])->update(['content_hash' => str_repeat('a', 64)]),
            fn () => DB::table('generation_artifacts')->where('id', $done['artifacts'][0]['id'])->delete(),
            fn () => DB::table('generation_runs')->where('id', $r['run']['id'])->update(['frozen_config' => '{}']),
            fn () => DB::table('generation_steps')->where('id', $done['steps'][0]['id'])->update(['status' => 'running']),
            fn () => DB::table('generation_dependencies')->insert(['novel_id' => $f['novelId'], 'run_id' => $r['run']['id'], 'step_id' => $done['steps'][0]['id'], 'source_step_id' => $otherDone['steps'][0]['id'], 'source_artifact_id' => $otherDone['artifacts'][0]['id']]),
        ];
        foreach ($bad as $action) {
            try {
                DB::transaction($action);
                $this->fail('数据库必须拒绝修改历史或跨运行引用');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_unverified_artifact_hash_and_changed_dependency_identity_prevent_reuse(): void
    {
        $f = $this->fixture();
        $r = $this->start($f);
        // 模拟导入的历史损坏，插入后依然受到不可变触发器保护。
        $input = ['run_input_hash' => $r['run']['input_hash'], 'node' => $f['payload']['nodes'][0], 'dependencies' => [], 'resume_checkpoint' => null];
        $hash = hash('sha256', NovelIntentService::canonical($input));
        $step = DB::table('generation_steps')->insertGetId(['novel_id' => $f['novelId'], 'run_id' => $r['run']['id'], 'stage' => 'test.echo', 'scope_key' => 'first', 'attempt_no' => 1, 'frozen_input' => json_encode($input), 'dependency_hash' => $hash, 'status' => 'succeeded', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('generation_artifacts')->insert(['novel_id' => $f['novelId'], 'run_id' => $r['run']['id'], 'step_id' => $step, 'type' => 'test_result', 'schema_version' => 'test.echo-v1', 'content' => '{"text":"损坏"}', 'content_hash' => str_repeat('0', 64), 'source_hash' => $hash, 'storage_ref' => 'db:corrupt', 'created_at' => now()]);
        $fake = new FakeStageExecutor;
        $this->problem('DEPENDENCY_MISMATCH', fn () => $this->advance($f, $r, 'first', $fake));
        $this->problem('DEPENDENCY_MISMATCH', fn () => $this->advance($f, $r, 'second', $fake));
        $this->assertSame(0, $fake->calls);
        $other = $this->start($f, 'changed-dependency');
        $input['run_input_hash'] = 'different-source';
        DB::table('generation_steps')->insert(['novel_id' => $f['novelId'], 'run_id' => $other['run']['id'], 'stage' => 'test.echo', 'scope_key' => 'first', 'attempt_no' => 1, 'frozen_input' => json_encode($input), 'dependency_hash' => hash('sha256', NovelIntentService::canonical($input)), 'status' => 'failed', 'created_at' => now(), 'updated_at' => now()]);
        $this->problem('DEPENDENCY_MISMATCH', fn () => $this->advance($f, $other, 'first', $fake, 1));
    }

    public function test_cross_book_source_and_archived_book_cannot_start(): void
    {
        $f = $this->fixture();
        $other = BlueprintFixture::create($f['actor'], 1, 10);
        $original = $f['payload']['outline_version_id'];
        $f['payload']['outline_version_id'] = $other['outline']['id'];
        $this->problem('INVALID_SOURCE', fn () => $this->start($f));
        $f['payload']['outline_version_id'] = $original;
        Novel::whereKey($f['novelId'])->update(['status' => 'archived']);
        $this->problem('STALE_STATE', fn () => $this->start($f));
        $this->assertDatabaseCount('generation_runs', 0);
    }

    public function test_ownership_and_production_harness_guard(): void
    {
        $f = $this->fixture();
        $r = $this->start($f);
        $other = User::factory()->create();
        $this->problem('FORBIDDEN', fn () => app(GenerationService::class)->read($other, $f['novelId'], $r['run']['id']));
        $other->is_admin = true;
        $this->problem('NOT_FOUND', fn () => app(GenerationService::class)->read($other, $f['novelId'], $r['run']['id']));
        $this->app->instance('env', 'production');
        try {
            $this->problem('STAGE_NOT_AVAILABLE', fn () => $this->start($f, 'prod'));
            $this->problem('STAGE_NOT_AVAILABLE', fn () => $this->advance($f, $r, 'first', new FakeStageExecutor));
        } finally {
            $this->app->instance('env', 'testing');
        }
    }
}
