<?php

namespace App\Http\Controllers;

use App\Exceptions\NovelProblem;
use App\Services\PlanningPipelineService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** 规划接口沿用管理员同书授权、同源会话及 CSRF；启动只保存离线回放任务。 */
final class PlanningPipelineController extends Controller
{
    public function __invoke(Request $request, PlanningPipelineService $service)
    {
        $actor = $request->user() ?? throw new NovelProblem('AUTH_REQUIRED', '请先登录。', 401);
        $novel = $request->route('novel');
        $id = $request->route('planning');
        $data = $request->isMethod('post')
            ? ($id ? $service->adopt($actor, $novel, $id, $request->except('_token'), $request->header('Idempotency-Key', '')) : $service->start($actor, $novel, $request->except('_token'), $request->header('Idempotency-Key', '')))
            : ($id ? $service->read($actor, $novel, $id) : $service->listing($actor, $novel));

        return response()->json(['data' => $data, 'request_id' => (string) Str::uuid(), 'version' => 'v1'], $request->isMethod('post') && ! $id ? 202 : 200)->header('Cache-Control', 'private, no-store');
    }
}
