<?php

use App\Http\Controllers\NovelIntentController;
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
