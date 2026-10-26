<?php

namespace App\Http\Controllers;

use App\Services\NovelIntentService;
use App\Services\NovelNavigationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** 只读切书跳转：白名单目标，不接收任意URL，也不保留上一书的对象标识。 */
class NovelNavigationController
{
    public function __invoke(Request $request): RedirectResponse
    {
        // 此独立GET入口不经过Panel路由组，访客明确跳转现有Filament登录页。
        if (! $request->user()) {
            return redirect()->guest(route('filament.admin.auth.login'));
        }
        app(NovelIntentService::class)->authorize($request->user());
        $d = $request->validate(['page' => ['required', 'string', Rule::in(array_keys(NovelNavigationService::RESOURCE_PAGES + NovelNavigationService::PAGES))], 'novel' => 'nullable|string']);
        $id = $d['novel'] ?? '';
        if ($id !== '') {
            app(NovelIntentService::class)->novel($request->user(), $id);
        }

        return redirect(app(NovelNavigationService::class)->destination($d['page'], $id));
    }
}
