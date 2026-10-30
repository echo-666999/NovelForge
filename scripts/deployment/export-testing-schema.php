<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

// 只导出测试结构；沿用项目启动守卫，不读取开发库或绕过测试连接限制。
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::selectOne('SELECT current_database() AS name')->name !== 'novel_forge_test') {
    throw new RuntimeException('拒绝导出非测试库。');
}
$config = config('database.connections.pgsql');
$destination = base_path('.local/nf-a02-schema.dump');
if (is_link($destination) || is_link(dirname($destination))) {
    throw new RuntimeException('拒绝导出到软链接。');
}
// 口令通过子进程环境传递，命令行、报告和异常均不输出真实连接配置。
umask(0077);
$process = new Process([
    'pg_dump', '--format=custom', '--schema-only', '--no-owner', '--no-privileges',
    '--host='.$config['host'], '--port='.$config['port'], '--username='.$config['username'],
    '--dbname=novel_forge_test', '--file='.$destination,
], base_path(), ['PGPASSWORD' => $config['password'], 'PGSERVICE' => false, 'PGOPTIONS' => false]);
$process->setTimeout(90)->run();
if (! $process->isSuccessful()) {
    throw new RuntimeException('测试库结构导出失败；未输出可能含敏感数据的子进程错误。');
}
echo "测试库结构已导出至私有目录；不包含业务数据。\n";
