<?php

use App\Http\Controllers\BlueprintController;
use App\Http\Controllers\NovelIntentController;
use App\Http\Controllers\RollingPlanController;
use App\Http\Controllers\StructureController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorldController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::prefix('api/v1')->group(function (): void {
    Route::post('novels', NovelIntentController::class)->defaults('command', 'create');
    Route::patch('novels/{novel}', NovelIntentController::class)->whereNumber('novel')->defaults('command', 'update');
    Route::get('novels/{novel}/requirements', NovelIntentController::class)->whereNumber('novel')->defaults('command', 'requirements');
    Route::get('novels/{novel}/intent', NovelIntentController::class)->whereNumber('novel')->defaults('command', 'intent');
    foreach (['archive', 'restore', 'copy', 'source', 'requirement'] as $command) {
        Route::post('novels/{novel}/'.$command, NovelIntentController::class)->whereNumber('novel')->defaults('command', $command);
    }
});

Route::prefix('api/v1/novels/{novel}/structure')->whereNumber('novel')->group(function (): void {
    Route::get('/', StructureController::class)->defaults('structureCommand', 'working');
    Route::get('/versions', StructureController::class)->defaults('structureCommand', 'versions');
    Route::get('/versions/{outline}', StructureController::class)->whereNumber('outline')->defaults('structureCommand', 'version');
    foreach (['node', 'thread', 'snapshot'] as $command) {
        Route::post('/'.$command, StructureController::class)->defaults('structureCommand', $command);
    }
});

Route::prefix('api/v1/novels/{novel}/world')->whereNumber('novel')->group(function (): void {
    Route::get('/', WorldController::class)->defaults('worldCommand', 'list');
    Route::get('/entities/{entity}', WorldController::class)->whereNumber('entity')->defaults('worldCommand', 'detail');
    Route::get('/versions/{definition}', WorldController::class)->whereNumber('definition')->defaults('worldCommand', 'version');
    Route::get('/versions/{definition}/reference', WorldController::class)->whereNumber('definition')->defaults('worldCommand', 'reference');
    foreach (['definition', 'approve', 'accept-minor', 'policy', 'merge-aliases', 'retire'] as $command) {
        Route::post('/'.$command, WorldController::class)->defaults('worldCommand', $command);
    }
});

Route::prefix('api/v1/novels/{novel}/blueprints')->whereNumber('novel')->group(function (): void {
    Route::get('/', BlueprintController::class)->defaults('blueprintCommand', 'list');
    Route::get('/{blueprint}', BlueprintController::class)->whereNumber('blueprint')->defaults('blueprintCommand', 'read');
    foreach (['save', 'validate', 'approve'] as $command) {
        Route::post('/'.$command, BlueprintController::class)->defaults('blueprintCommand', $command);
    }
});

// 同源会话与 CSRF 沿用现有接口策略，后台和 HTTP 共用规划服务。
Route::prefix('api/v1/novels/{novel}/plans')->whereNumber('novel')->group(function (): void {
    Route::get('/', RollingPlanController::class)->defaults('planCommand', 'list');
    Route::get('/{plan}', RollingPlanController::class)->whereNumber('plan')->defaults('planCommand', 'read');
    foreach (['save', 'validate', 'freeze', 'split', 'merge', 'reorder'] as $command) {
        Route::post('/'.$command, RollingPlanController::class)->defaults('planCommand', $command);
    }
});

// B06 工作台摘要为只读接口，不触发生成或修改规划状态。
Route::get('api/v1/novels/{novel}', WorkspaceController::class)->whereNumber('novel');
