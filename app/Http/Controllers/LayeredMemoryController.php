<?php

namespace App\Http\Controllers;

use App\Exceptions\NovelProblem;
use App\Services\LayeredMemoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** 会话鉴权记忆接口；正式范围不会自动回退至草稿。 */
final class LayeredMemoryController extends Controller
{
    public function __invoke(Request $request, LayeredMemoryService $service)
    {
        $actor = $request->user() ?? throw new NovelProblem('AUTH_REQUIRED', '请先登录。', 401);
        $novel = $request->route('novel');
        $data = $request->isMethod('post') ? $service->execute($actor, $novel, $request->route('memoryCommand'), $request->except('_token'), $request->header('Idempotency-Key', '')) : ($request->route('memory') ? $service->read($actor, $novel, $request->route('memory'), $request->query()) : $service->listing($actor, $novel, $request->query()));

        return response()->json(['data' => $data, 'request_id' => (string) Str::uuid(), 'version' => 'v1'])->header('Cache-Control', 'private, no-store');
    }
}
