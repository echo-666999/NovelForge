<?php

namespace Tests\Feature;

use App\Exceptions\NovelProblem;
use App\Models\Novel;
use App\Models\User;
use App\Services\BlueprintService;
use App\Services\NovelIntentService;
use App\Services\RollingPlanService;
use App\Services\StructureService;
use App\Services\WorldService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\BlueprintFixture;
use Tests\Support\RollingPlanFixture;
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

    public function test_structure_concurrency_replays_once_and_allocates_versions_under_lock(): void
    {
        $this->assertSame('novel_forge_test', DB::selectOne('SELECT current_database() AS name')->name);
        Artisan::call('migrate:fresh', ['--force' => true]);
        $children = [];
        try {
            $actor = User::factory()->create(['is_admin' => true]);
            $book = app(NovelIntentService::class)->execute($actor, 'create', null, [
                'title' => '结构并发', 'genre' => '测试', 'background' => '测试', 'premise_raw' => '不可重复版本',
                'target_chapters' => 100, 'length_policy' => ['min' => 100, 'max' => 200],
            ], 'create');
            $run = function (string $command, array $payload, array $keys) use ($actor, $book, &$children) {
                $children = [];
                foreach ($keys as $key) {
                    $process = new Process([PHP_BINARY, base_path('tests/Support/structure-command-worker.php'), (string) $actor->id, $book['novel']['id'], $command, json_encode($payload, JSON_THROW_ON_ERROR), $key], base_path(), ['APP_ENV' => 'testing']);
                    $process->start();
                    $children[] = $process;
                }
                $results = [];
                foreach ($children as $child) {
                    $child->wait();
                    $this->assertSame(0, $child->getExitCode(), $child->getErrorOutput());
                    $results[] = json_decode($child->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                }

                return $results;
            };
            $results = $run('node', ['type' => 'volume', 'source_version_id' => $book['sources'][0]['id'], 'sequence' => 1, 'title' => '第一卷', 'objective' => '目标', 'status' => 'skeleton', 'start_chapter' => 1, 'end_chapter' => 100, 'expected_version' => 1, 'reason' => '并发'], ['same-key', 'same-key']);
            $this->assertTrue($results[0]['ok']);
            $this->assertEquals($results[0], $results[1]);
            $this->assertDatabaseCount('volumes', 1);
            $results = $run('snapshot', ['expected_version' => 2, 'reason' => '并发固化'], ['snapshot-a', 'snapshot-b']);
            $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']));
            $failure = array_values(array_filter($results, fn ($r) => ! $r['ok']))[0];
            $this->assertSame('STALE_STATE', $failure['code']);
            $this->assertDatabaseCount('outline_versions', 1);
            $this->assertDatabaseCount('plan_nodes', 1);
            $v = app(StructureService::class)->execute($actor, $book['novel']['id'], 'snapshot', ['expected_version' => 3, 'reason' => '下一版本'], 'next');
            $this->assertSame(2, $v['version']['version']);
        } finally {
            foreach ($children as $child) {
                if ($child->isRunning()) {
                    $child->stop();
                }
            }
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
    }

    public function test_world_duplicate_creation_and_policy_quota_are_serialized(): void
    {
        $this->assertSame('novel_forge_test', DB::selectOne('SELECT current_database() AS name')->name);
        Artisan::call('migrate:fresh', ['--force' => true]);
        $children = [];
        try {
            $actor = User::factory()->create(['is_admin' => true]);
            $book = app(NovelIntentService::class)->execute($actor, 'create', null, ['title' => '路人策略', 'genre' => '测试', 'background' => '守门人只开门', 'premise_raw' => '初始设定', 'target_chapters' => 10, 'length_policy' => ['min' => 100, 'max' => 200]], 'create');
            $run = function (array $calls) use ($actor, $book, &$children) {
                $children = [];
                foreach ($calls as [$command,$data,$key]) {
                    $child = new Process([PHP_BINARY, base_path('tests/Support/world-command-worker.php'), (string) $actor->id, $book['novel']['id'], $command, json_encode($data, JSON_THROW_ON_ERROR), $key], base_path(), ['APP_ENV' => 'testing']);
                    $child->start();
                    $children[] = $child;
                }
                $results = [];
                foreach ($children as $child) {
                    $child->wait();
                    $this->assertSame(0, $child->getExitCode(), $child->getErrorOutput());
                    $results[] = json_decode($child->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                }

                return $results;
            };
            $data = ['type' => 'character', 'name' => '守门人', 'description' => '普通路人', 'criticality' => 'minor', 'source_version_id' => $book['sources'][0]['id'], 'source_quote' => '守门人只开门', 'introduced_chapter' => 1, 'affects_main_plot' => false, 'profile' => ['role' => 'extra', 'identity' => '门卫', 'voice' => '简短回应', 'limitations' => '普通人'], 'expected_version' => 1, 'reason' => '测试'];
            $results = $run([['definition', $data, 'same'], ['definition', $data, 'same']]);
            $this->assertTrue($results[0]['ok']);
            $this->assertEquals($results[0], $results[1]);
            $this->assertDatabaseCount('entities', 1);
            $a = $results[0]['result']['definition']['id'];
            $service = app(WorldService::class);
            $b = $service->execute($actor, $book['novel']['id'], 'definition', array_replace($data, ['name' => '另一门卫', 'expected_version' => 2]), 'second')['definition']['id'];
            $policy = $service->execute($actor, $book['novel']['id'], 'policy', ['expected_version' => 3, 'reason' => '限额', 'enabled' => true, 'start_chapter' => 1, 'end_chapter' => 5, 'max_acceptances' => 1], 'policy')['policy'];
            $common = ['policy_version_id' => $policy['id'], 'expected_version' => 4, 'reason' => '接纳'];
            $results = $run([['accept-minor', $common + ['definition_version_id' => $a], 'accept-a'], ['accept-minor', $common + ['definition_version_id' => $b], 'accept-b']]);
            $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']));
            $this->assertDatabaseCount('entity_approvals', 1);
            $loser = $results[0]['ok'] ? $b : $a;
            try {
                $service->execute($actor, $book['novel']['id'], 'accept-minor', array_replace($common, ['expected_version' => 5, 'definition_version_id' => $loser]), 'refresh-retry');
                $this->fail('Quota must reject');
            } catch (NovelProblem $e) {
                $this->assertSame('POLICY_LIMIT', $e->errorCode);
            }
            $this->assertDatabaseCount('entity_approvals', 1);
        } finally {
            foreach ($children as $child) {
                if ($child->isRunning()) {
                    $child->stop();
                }
            }Artisan::call('migrate:fresh', ['--force' => true]);
        }
    }

    public function test_blueprint_concurrent_saves_and_approvals_are_unique(): void
    {
        $this->assertSame('novel_forge_test', DB::selectOne('SELECT current_database() AS name')->name);
        Artisan::call('migrate:fresh', ['--force' => true]);
        $children = [];
        try {
            $f = BlueprintFixture::create();
            $actor = $f['actor'];
            $id = $f['book']['novel']['id'];
            $service = app(BlueprintService::class);
            $run = function (string $command, array $data, array $keys) use ($actor, $id, &$children) {
                $children = [];
                foreach ($keys as $key) {
                    $child = new Process([PHP_BINARY, base_path('tests/Support/blueprint-command-worker.php'), (string) $actor->id, $id, $command, json_encode($data, JSON_THROW_ON_ERROR), $key], base_path(), ['APP_ENV' => 'testing']);
                    $child->start();
                    $children[] = $child;
                }
                $results = [];
                foreach ($children as $child) {
                    $child->wait();
                    $this->assertSame(0, $child->getExitCode(), $child->getErrorOutput());
                    $results[] = json_decode($child->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                }

                return $results;
            };
            $data = $f['payload'] + ['expected_version' => Novel::find($id)->row_version, 'reason' => '并发候选'];
            $results = $run('save', $data, ['same-save', 'same-save']);
            $this->assertTrue($results[0]['ok']);
            $this->assertEquals($results[0], $results[1]);
            $this->assertDatabaseCount('blueprint_versions', 1);
            $this->assertDatabaseCount('ending_contracts', 1);
            $v = $results[0]['result']['blueprint'];
            $check = $service->execute($actor, $id, 'validate', ['blueprint_version_id' => $v['id'], 'expected_version' => Novel::find($id)->row_version, 'reason' => '检查'], 'check')['validation'];
            $this->assertTrue($check['report']['passed']);
            $approve = ['blueprint_version_id' => $v['id'], 'validation_id' => $check['id'], 'report_hash' => $check['report_hash'], 'acknowledge_impact' => true, 'expected_version' => Novel::find($id)->row_version, 'reason' => '批准'];
            $results = $run('approve', $approve, ['approve-a', 'approve-b']);
            $this->assertCount(1, array_filter($results, fn ($v) => $v['ok']));
            $this->assertDatabaseCount('blueprint_approvals', 1);
        } finally {
            foreach ($children as $child) {
                if ($child->isRunning()) {
                    $child->stop();
                }
            }Artisan::call('migrate:fresh', ['--force' => true]);
        }
    }

    public function test_rolling_plan_concurrent_save_and_freeze_are_serialized(): void
    {
        $this->assertSame('novel_forge_test', DB::selectOne('SELECT current_database() AS name')->name);
        Artisan::call('migrate:fresh', ['--force' => true]);
        $children = [];
        try {
            $f = RollingPlanFixture::create();
            $actor = $f['actor'];
            $id = $f['book']['novel']['id'];
            $run = function ($command, $data, $keys) use ($actor, $id, &$children) {
                $children = [];
                foreach ($keys as $key) {
                    $process = new Process([PHP_BINARY, base_path('tests/Support/plan-command-worker.php'), (string) $actor->id, $id, $command, json_encode($data, JSON_THROW_ON_ERROR), $key], base_path(), ['APP_ENV' => 'testing']);
                    $process->start();
                    $children[] = $process;
                }
                $results = [];
                foreach ($children as $process) {
                    $process->wait();
                    $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                    $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                }

                return $results;
            };
            $data = $f['plan_payload'] + ['expected_version' => Novel::find($id)->row_version, 'reason' => '并发计划'];
            $results = $run('save', $data, ['same', 'same']);
            $this->assertTrue($results[0]['ok']);
            $this->assertEquals($results[0], $results[1]);
            $this->assertDatabaseCount('rolling_plans', 1);
            $p = $results[0]['result']['plan'];
            $c = app(RollingPlanService::class)->execute($actor, $id, 'validate', ['rolling_plan_id' => $p['id'], 'expected_version' => Novel::find($id)->row_version, 'reason' => '检查'], 'check')['validation'];
            $data = ['rolling_plan_id' => $p['id'], 'validation_id' => $c['id'], 'report_hash' => $c['report_hash'], 'expected_version' => Novel::find($id)->row_version, 'reason' => '并发冻结'];
            $results = $run('freeze', $data, ['freeze-a', 'freeze-b']);
            $this->assertCount(1, array_filter($results, fn ($r) => $r['ok']));
            $this->assertDatabaseCount('plan_freezes', 1);
        } finally {
            foreach ($children as $child) {
                if ($child->isRunning()) {
                    $child->stop();
                }
            } Artisan::call('migrate:fresh', ['--force' => true]);
        }
    }

    public function test_empty_b01_b02_b03_b04_b05_c01_c02_schema_can_roll_back_and_reapply(): void
    {
        $this->assertSame('novel_forge_test', DB::selectOne('SELECT current_database() AS name')->name);
        Artisan::call('migrate:fresh', ['--force' => true]);
        try {
            $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 14, '--force' => true]));
            $this->assertFalse(Schema::hasTable('novels'));
            $this->assertTrue(Schema::hasTable('users'));
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
            $this->assertTrue(Schema::hasTable('novels'));
        } finally {
            Artisan::call('migrate', ['--force' => true]);
        }
    }
}
