<?php

namespace Tests\Feature;

use App\Models\Novel;
use App\Services\GenerationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\BlueprintFixture;
use Tests\Support\FakeStageExecutor;
use Tests\TestCase;

/** 独立进程证明重启与重复调度，不以单进程模拟替代数据库并发。 */
class GenerationConcurrencyTest extends TestCase
{
    public function test_process_restart_duplicate_start_and_attempt_claim(): void
    {
        $this->assertSame('novel_forge_test', DB::selectOne('SELECT current_database() AS name')->name);
        Artisan::call('migrate:fresh', ['--force' => true]);
        $children = [];
        try {
            $f = BlueprintFixture::create(chapterCount: 1, targetChapters: 10);
            $id = $f['book']['novel']['id'];
            $payload = ['outline_version_id' => $f['outline']['id'], 'expected_version' => Novel::find($id)->row_version,
                'nodes' => [
                    ['key' => 'first', 'stage' => 'test.echo', 'depends_on' => [], 'input' => ['text' => '第一阶段']],
                    ['key' => 'second', 'stage' => 'test.echo', 'depends_on' => ['first'], 'input' => ['text' => '第二阶段']],
                ]];
            $parallel = function (array $args) use ($f, $id, &$children): array {
                $children = [];
                for ($i = 0; $i < 2; $i++) {
                    $p = new Process([PHP_BINARY, base_path('tests/Support/generation-worker.php'), (string) $f['actor']->id, $id, ...$args], base_path(), ['APP_ENV' => 'testing']);
                    $p->start();
                    $children[] = $p;
                }
                $results = [];
                foreach ($children as $p) {
                    $p->wait();
                    $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
                    $results[] = json_decode($p->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                }

                return $results;
            };
            $starts = $parallel(['start', json_encode($payload)]);
            $this->assertEquals($starts[0], $starts[1]);
            $run = $starts[0]['run']['id'];
            $this->assertDatabaseCount('generation_runs', 1);
            // 父进程先留下检查点，再退出作用域，由两个新进程争抢同一续跑尝试。
            app(GenerationService::class)->advance($f['actor'], $id, $run, 'first', new FakeStageExecutor('deferred'));
            $results = $parallel(['advance', $run, 'first', '1']);
            $this->assertSame(1, array_sum(array_column($results, 'calls')));
            $this->assertDatabaseCount('generation_steps', 2);
            $this->assertDatabaseCount('generation_artifacts', 2);
            $results = $parallel(['advance', $run, 'second']);
            $this->assertSame(1, array_sum(array_column($results, 'calls')));
            $this->assertDatabaseCount('generation_steps', 3);
            $this->assertSame('succeeded', DB::table('generation_runs')->where('id', $run)->value('status'));
        } finally {
            foreach ($children as $child) {
                if ($child->isRunning()) {
                    $child->stop();
                }
            }
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
    }
}
