<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// 仅在测试库与私有Redis缺失时复现原finally顺序，不改变业务代码。
// 启动失败也在本入口捕获，禁止原始异常参数、数据库URL或完整栈流向终端。
try {
    $root = dirname(__DIR__, 4);
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    if (! $app->environment('testing') || DB::selectOne('select current_database() as n')->n !== 'novel_forge_test' || file_exists($app->basePath('.local/redis-testing.sock'))) {
        // 已知拒绝直接返回非零，不交给框架全局异常处理器决定退出状态。
        fwrite(STDERR, "NF_REPRO_REFUSED：仅允许测试环境、批准测试库且私有Redis缺失时执行。\n");
        exit(1);
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
} catch (Throwable) {
    // 仅输出固定诊断，复现内部的历史异常及finally清理仍按原逻辑运行。
    fwrite(STDERR, "NF_REPRO_FAILED：文档复现启动或执行失败，请核对测试环境。\n");
    exit(1);
}
