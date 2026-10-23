<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class NovelConcurrencyTest extends TestCase
{
    public function test_two_real_processes_share_one_idempotent_creation(): void
    {
        $this->assertSame('novel_forge_test', DB::selectOne('SELECT current_database() AS name')->name);
        Artisan::call('migrate:fresh', ['--force' => true]);
        $actor = User::factory()->create();
        $actor->is_admin = true;
        $actor->save();
        $key = 'concurrent-'.Str::uuid();
        $children = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/novel-command-worker.php'), (string) $actor->id, $key], base_path(), ['APP_ENV' => 'testing']);
                $process->start();
                $children[] = $process;
            }
            foreach ($children as $child) {
                $child->wait();
                $this->assertSame(0, $child->getExitCode(), $child->getErrorOutput());
            }
            $a = json_decode($children[0]->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $b = json_decode($children[1]->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertEquals($a, $b);
            $this->assertDatabaseCount('novels', 1);
            $this->assertDatabaseCount('novel_commands', 1);
        } finally {
            foreach ($children as $child) {
                if ($child->isRunning()) {
                    $child->stop();
                }
            }
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
    }

    public function test_empty_b01_schema_can_roll_back_and_reapply(): void
    {
        $this->assertSame('novel_forge_test', DB::selectOne('SELECT current_database() AS name')->name);
        Artisan::call('migrate:fresh', ['--force' => true]);
        try {
            $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]));
            $this->assertFalse(Schema::hasTable('novels'));
            $this->assertTrue(Schema::hasTable('users'));
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
            $this->assertTrue(Schema::hasTable('novels'));
        } finally {
            Artisan::call('migrate',['--force' => true]);
        }
    }
}
