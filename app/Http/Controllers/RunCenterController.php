<?php

namespace App\Http\Controllers;

use App\Exceptions\NovelProblem;
use App\Services\RunCenterService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** 运行与审计读取仅开放同源管理员会话，下载不接受文件路径。 */
final class RunCenterController extends Controller
{
    public function __invoke(Request $request, RunCenterService $service)
    {
        if (! $request->user()) {
            throw new NovelProblem('AUTH_REQUIRED', '请先登录。', 401);
        }
        $actor = $request->user();
        $mode = $request->route('centerAction');
        if (in_array($mode, ['artifact', 'evidence'], true)) {
            $text = $service->download($actor, $request->route('novel'), $request->route('run'), $mode, $request->route('object'), $request->route('kind'));

            return response($text, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Content-Disposition' => 'attachment; filename=novelforge-evidence.txt', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        }
        $data = match ($mode) {
            'detail' => $service->detail($actor, $request->route('novel'), $request->route('run'), (int) ($request->validate(['request_page' => 'sometimes|integer|min:1|max:1000000'])['request_page'] ?? 1)),
            'audit','audit-export' => $service->audit($actor, $request->query()),'health' => $service->health($actor),
            default => $service->listing($actor, $request->query()),
        };
        if ($mode === 'audit-export') {
            // 导出当前筛选页，明确页码与总数，不把分页结果冒充完整历史。
            return response(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 200, ['Content-Type' => 'application/json', 'Content-Disposition' => 'attachment; filename=novelforge-audit-page.json', 'Cache-Control' => 'private, no-store']);
        }

        return response()->json(['data' => $data, 'trace_id' => $request->attributes->get('trace_id'), 'request_id' => (string) Str::uuid(), 'version' => 'v1'])->header('Cache-Control','private, no-store');
    }
}
