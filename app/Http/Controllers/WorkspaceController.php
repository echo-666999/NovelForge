<?php

namespace App\Http\Controllers;

use App\Exceptions\NovelProblem;
use App\Services\WorkspaceService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WorkspaceController extends Controller
{
    public function __invoke(Request $request, WorkspaceService $service)
    {
        // 摘要复用领域权限与规划门禁，不提供隐含的启动生产操作。
        if (! $request->user()) {
            throw new NovelProblem('AUTH_REQUIRED', '请先登录。', 401);
        }

        return response()->json(['data' => $service->summary($request->user(), $request->route('novel'), $request->query()), 'request_id' => (string) Str::uuid(), 'version' => 'v1']);
    }
}
