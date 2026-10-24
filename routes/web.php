<?php

use App\Http\Controllers\AuthoringConfigurationController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BlueprintController;
use App\Http\Controllers\ForeshadowLedgerController;
use App\Http\Controllers\NovelIntentController;
use App\Http\Controllers\ProviderConfigurationController;
use App\Http\Controllers\RecoveryController;
use App\Http\Controllers\RollingPlanController;
use App\Http\Controllers\RunCenterController;
use App\Http\Controllers\StoryConsistencyController;
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

// C03 只开放管理配置；没有真实模型调用、探测或密钥读取路由。
Route::get('api/v1/providers', ProviderConfigurationController::class);
foreach (['provider', 'model'] as $command) {
    Route::post('api/v1/providers/'.$command, ProviderConfigurationController::class)->defaults('providerCommand', $command);
}

// C04 同源配置接口：无生成、真实模型测试或跨环境调用入口。
foreach (['prompt', 'route', 'style'] as $kind) {
    Route::get('api/v1/configurations/'.$kind, AuthoringConfigurationController::class)->defaults('configurationKind', $kind);
    foreach (['save', 'test', 'publish', 'retire'] as $action) {
        Route::post('api/v1/configurations/'.$kind.'/'.$action, AuthoringConfigurationController::class)->defaults('configurationKind', $kind)->defaults('configurationAction', $action);
    }
}

// C05 预算配置与凭证对账共用服务，沿用同源会话及CSRF保护。
Route::get('api/v1/billing', BillingController::class);
foreach (['price', 'policy', 'reconcile'] as $action) {
    Route::post('api/v1/billing/'.$action, BillingController::class)->defaults('billingAction', $action);
}

// 费用摘要导出为只读鉴权操作，不包含密钥或请求正文。
Route::get('api/v1/billing/export', BillingController::class)->defaults('billingAction', 'export');

// C06 仅恢复控制和证据决策；不开放真实生成入口。
Route::prefix('api/v1/novels/{novel}/runs/{run}/recovery')->whereNumber(['novel', 'run'])->group(function (): void {
    Route::get('/', RecoveryController::class);
    foreach (['pause', 'resume', 'cancel', 'manual', 'retry', 'reextract', 'change_plan'] as $action) {
        Route::post('/'.$action, RecoveryController::class)->defaults('recoveryAction', $action);
    }
});

// C07 只读运行中心、审计和受控证据下载；不提供真实生成启动入口。
foreach (['runs' => 'list', 'audit' => 'audit', 'audit/export' => 'audit-export', 'operations/health' => 'health'] as $path => $action) {
    Route::get('api/v1/'.$path, RunCenterController::class)->defaults('centerAction', $action);
}
Route::get('api/v1/novels/{novel}/runs/{run}', RunCenterController::class)->whereNumber(['novel', 'run'])->defaults('centerAction', 'detail');
Route::get('api/v1/novels/{novel}/runs/{run}/artifacts/{object}', RunCenterController::class)->whereNumber(['novel', 'run', 'object'])->defaults('centerAction', 'artifact');
Route::get('api/v1/novels/{novel}/runs/{run}/requests/{object}/evidence/{kind}', RunCenterController::class)->whereNumber(['novel', 'run', 'object'])->whereIn('kind', ['request', 'response'])->defaults('centerAction', 'evidence');

// D02 候选时间线、知识与一致性接口，继续使用会话、CSRF和管理员归属检查。
Route::prefix('api/v1/novels/{novel}/story-contexts')->whereNumber('novel')->group(function (): void {
    Route::get('/', StoryConsistencyController::class);
    Route::post('/', StoryConsistencyController::class);
    Route::get('/{context}', StoryConsistencyController::class)->whereNumber('context');
    Route::get('/{context}/pov', StoryConsistencyController::class)->whereNumber('context')->defaults('consistencyAction', 'pov');
});

// D03 伏笔版本与作者变更决策；正式 occurrence 不开放 HTTP 写入。
Route::prefix('api/v1/novels/{novel}/foreshadows')->whereNumber('novel')->group(function (): void {
    Route::get('/', ForeshadowLedgerController::class);
    Route::get('/{plan}', ForeshadowLedgerController::class)->whereNumber('plan');
    foreach (['create', 'freeze', 'propose', 'decide'] as $command) {
        Route::post('/'.$command, ForeshadowLedgerController::class)->defaults('ledgerCommand', $command);
    }
});
