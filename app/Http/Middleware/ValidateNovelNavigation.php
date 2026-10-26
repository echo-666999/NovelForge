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
        foreach (['novel', 'run', 'step', 'version', 'review'] as $key) {
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
