<?php

use App\Models\User;
use App\Services\ExtractionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
// 子进程在读取夹具和任何写操作之前校验专用测试数据库。
if (! $app->environment('testing') || DB::selectOne('SELECT current_database() AS n')->n !== 'novel_forge_test') {
    exit(2);
}
$d = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$r = app(ExtractionService::class)->extract(User::findOrFail($d['actor']), $d['novel'], $d['payload'], $d['key']);
echo json_encode(['id' => $r['id'], 'eligible' => $r['current_eligible']], JSON_THROW_ON_ERROR);
