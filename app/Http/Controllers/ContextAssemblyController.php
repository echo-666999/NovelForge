<?php

namespace App\Http\Controllers;

use App\Exceptions\NovelProblem;
use App\Services\ContextAssemblyService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** 上下文检查与组装沿用同源会话、CSRF及管理员同书授权。 */
final class ContextAssemblyController extends Controller
{
    public function __invoke(Request $request, ContextAssemblyService $service)
    {
        $actor = $request->user() ?? throw new NovelProblem('AUTH_REQUIRED', '请先登录。', 401);
        $step = $request->route('step');
        $data = $request->isMethod('post')
            ? $service->assemble($actor, $step, $request->except('_token'), $request->header('Idempotency-Key', ''))
            : ($request->filled('compare') && $request->filled('manifest')
                ? $service->compare($actor, $step, $request->string('manifest')->toString(), $request->string('compare')->toString())
                : $service->read($actor, $step, $request->query('manifest')));

        return response()->json(['data' => $data, 'request_id' => (string) Str::uuid(), 'version' => 'v1'])->header('Cache-Control', 'private, no-store');
    }
}
