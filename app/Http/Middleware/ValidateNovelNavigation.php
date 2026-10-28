<?php

namespace App\Http\Middleware;

use App\Exceptions\NovelProblem;
use App\Services\NovelIntentService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** 在Livewire类型化属性挂载前拒绝数组形态的上下文ID，避免错误页或隐式选书。 */
class ValidateNovelNavigation
{
    public function handle(Request $request, Closure $next): Response
    {
        // E08在类型化阅读URL挂载前拒绝数组参数，避免异常形态变成500。
        $keys = ['novel', 'run', 'step', 'version', 'review'];
        if ($request->is('admin/formal-reader')) {
            $keys = array_merge($keys, ['chapter', 'revision', 'scope']);
        }
        foreach ($keys as $key) {
            if ($request->query->has($key) && ! is_string($request->query($key))) {
                throw new NovelProblem('NOT_FOUND', '导航来源不存在。', 404);
            }
        }
        $id = $request->query('novel', '');
        if ($id !== '') {
            app(NovelIntentService::class)->novel($request->user(), $id);
        }

        return $next($request);
    }
}
