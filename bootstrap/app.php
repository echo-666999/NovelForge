<?php

use App\Http\Middleware\OperationTrace;
use App\Support\NovelApiErrors;
use App\Support\TestIsolation;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Env;
use Symfony\Component\Console\Input\ArgvInput;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('web', OperationTrace::class);
        $middleware->trimStrings(except: [fn (Request $request) => $request->is('api/v1/*', 'livewire/*')]);
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request) => $request->is('api/v1/*', 'livewire/*')]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 供应商密钥不进入验证失败的会话旧输入。
        $exceptions->dontFlash(['api_key']);
        $exceptions->render(fn (Throwable $e, Request $request) => NovelApiErrors::render($e, $request));
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

$app->beforeBootstrapping(LoadConfiguration::class, function (Application $app): void {
    $testing = Env::get('APP_ENV') === 'testing'
        || (new ArgvInput)->getParameterOption('--env') === 'testing';
    $app->instance('testing.isolation.requested', $testing);
    if (! $testing) {
        return;
    }
    if ($app->configurationIsCached()) {
        throw new RuntimeException('Testing refuses cached configuration; clear it outside the test run.');
    }
    $root = $app->basePath('storage/testing');
    foreach (['', '/app', '/app/private', '/app/public', '/framework', '/framework/cache', '/framework/cache/data', '/framework/cache/locks', '/framework/sessions', '/framework/views', '/logs'] as $suffix) {
        $path = $root.$suffix;
        if (is_link($app->basePath('storage')) || is_link($path)) {
            throw new RuntimeException('Testing storage must not be a symbolic link.');
        }
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('Cannot prepare isolated testing storage.');
        }
    }
    $app->useStoragePath($root);
});
$app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
    $requested = $app->make('testing.isolation.requested');
    if ($requested !== $app->environment('testing') || ($requested && $app['config']->get('app.env') !== 'testing')) {
        throw new RuntimeException('Testing environment conflicts with the loaded configuration.');
    }
    if ($requested) {
        TestIsolation::configure($app);
    }
});

return $app;
