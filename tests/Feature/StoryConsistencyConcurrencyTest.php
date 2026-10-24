<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\StoryConsistencyFixture as F;
use Tests\TestCase;

/** 真实独立进程验证时间线封存幂等和乐观版本冲突。 */
class StoryConsistencyConcurrencyTest extends TestCase
{
    public function test_two_processes_share_idempotence_and_competing_versions_do_not_overwrite(): void
    {
        $this->assertSame('novel_forge_test', DB::selectOne('SELECT current_database() AS name')->name);
        Artisan::call('migrate:fresh', ['--force' => true]);
        $children = [];
        try {
            $f = F::create();
            $event = F::event($f, 1);
            foreach ([0, 1] as $version) {
                $payload = ['entries' => [F::entry($event, 10)], 'expected_version' => $version, 'reason' => '进程竞争验证'];
                $children = [];
                foreach ([0, 1] as $i) {
                    $key = $version === 0 ? 'same' : 'competing-'.$i;
                    $child = new Process([PHP_BINARY, base_path('tests/Support/story-context-worker.php'), (string) $f['actor']->id, $f['novelId'], json_encode($payload), $key], base_path(), ['APP_ENV' => 'testing']);
                    $child->start();
                    $children[] = $child;
                }
                $results = [];
                foreach ($children as $child) {
                    $child->wait();
                    $this->assertSame(0, $child->getExitCode(), $child->getErrorOutput());
                    $results[] = json_decode($child->getOutput(), true);
                }
                if ($version === 0) {
                    $this->assertTrue($results[0]['ok']);
                    $this->assertSame($results[0], $results[1]);
                } else {
                    $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']));
                    $this->assertContains('VERSION_CONFLICT', array_column($results, 'code'));
                }
            }
            $this->assertDatabaseCount('story_context_versions', 2);
            $this->assertDatabaseCount('story_time_entries', 2);
            $this->assertDatabaseCount('story_events', 0);
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
