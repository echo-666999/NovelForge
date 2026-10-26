<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\CanonicalFixture as F;
use Tests\TestCase;

/** 两个真实进程竞争同一正式游标，不能用单事务模拟并发。 */
class CanonicalConcurrencyTest extends TestCase
{
    private function compete(bool $sameKey): void
    {
        $this->assertSame('novel_forge_test', DB::selectOne('SELECT current_database() AS db')->db);
        Artisan::call('migrate:fresh', ['--force' => true]);
        $children = [];
        try {
            $f = F::create();
            $p = F::proof($f);
            $this->assertSame('ready', $p['status']);
            $d = $f['payload'] + ['validation_artifact_id' => $p['id']];
            foreach ([0, 1] as $i) {
                $c = new Process([PHP_BINARY, base_path('tests/Support/canonical-worker.php'), (string) $f['actor']->id, $f['novel'], json_encode($d), $sameKey ? 'same' : 'different-'.$i], base_path(), ['APP_ENV' => 'testing']);
                $c->setTimeout(180);
                $c->start();
                $children[] = $c;
            }
            $results = [];
            foreach ($children as $c) {
                $c->wait();
                $this->assertSame(0, $c->getExitCode(), $c->getErrorOutput());
                $results[] = json_decode($c->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            if ($sameKey) {
                $this->assertTrue($results[0]['ok']);
                $this->assertTrue($results[1]['ok']);
                $this->assertSame($results[0]['result']['id'], $results[1]['result']['id']);
            } else {
                $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']));
                $this->assertContains('STALE_STATE', array_column($results, 'code'));
            }
            $this->assertDatabaseCount('canonical_commits', 1);
            $this->assertDatabaseCount('canonical_chapters', 1);
            $this->assertDatabaseCount('story_events', 1);
            $this->assertDatabaseCount('canonical_outbox', 1);
            $this->assertSame(1, DB::table('novels')->where('id', $f['novel'])->value('state_version'));
            $this->assertSame('committed', DB::table('canonical_commits')->value('status'));
        } finally {
            foreach ($children as $c) {
                if ($c->isRunning()) {
                    $c->stop();
                }
            }Artisan::call('migrate:fresh', ['--force' => true]);
        }
    }

    public function test_same_key_replays_one_atomic_result(): void
    {
        $this->compete(true);
    }

    public function test_same_expected_version_different_keys_only_one_commits(): void
    {
        $this->compete(false);
    }
}
