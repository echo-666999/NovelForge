<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;

// 只在已隔离的测试环境渲染公共组件，输出只读 HTML，不挂载任何生产路由。
$root = dirname(__DIR__, 2);
if (getenv('APP_ENV') !== 'testing') {
    throw new RuntimeException('Set APP_ENV=testing before rendering the UI specimen.');
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::selectOne('SELECT current_database() AS name')->name !== 'novel_forge_test') {
    throw new RuntimeException('UI specimen requires isolated testing database.');
}
$panel = Filament\Facades\Filament::getPanel('admin');
Filament\Facades\Filament::setCurrentPanel($panel);
$panel->boot();
$manifest = json_decode(file_get_contents($root.'/public/build/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
$css = file_get_contents($root.'/public/build/'.$manifest['resources/css/filament/admin/theme.css']['file']);
$html = Blade::render(file_get_contents($root.'/tests/Fixtures/ui-foundation.blade.php'), ['css' => $css]);
$output = storage_path('ui02-review');
if (! is_dir($output)) {
    mkdir($output, 0700, true);
}
file_put_contents($output.'/index.html', $html);
echo "Read-only UI component specimen rendered.\n";
