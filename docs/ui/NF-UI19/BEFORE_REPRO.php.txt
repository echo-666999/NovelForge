<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// 仅在测试库与私有Redis缺失时复现原finally顺序，不改变业务代码。
$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing') || DB::selectOne('select current_database() as n')->n !== 'novel_forge_test' || file_exists($app->basePath('.local/redis-testing.sock'))) {
    throw new RuntimeException('拒绝在未确认的环境执行复现。');
}
$report = [];
Artisan::call('migrate:fresh', ['--force' => true]);
try {
    $primary = new RuntimeException('NF_UI16_ORIGINAL_FAILURE');
    try {
        try {
            User::factory()->create(['is_admin' => true]);
            throw $primary;
        } finally {
            Queue::connection('redis')->clear('generation');
            Artisan::call('migrate:fresh', ['--force' => true]);
        }
    } catch (Throwable $caught) {
        $report = ['original_exception_preserved' => $caught === $primary, 'caught_class' => get_class($caught), 'users_left_after_legacy_finally' => DB::table('users')->count(), 'private_redis_absent' => true];
    }
} finally {
    Artisan::call('migrate:fresh', ['--force' => true]);
    $report['users_after_probe_cleanup'] = DB::table('users')->count();
}
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
