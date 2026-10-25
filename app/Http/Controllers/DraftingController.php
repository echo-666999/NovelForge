<?php

namespace App\Http\Controllers;

use App\Exceptions\NovelProblem;
use App\Services\DraftingService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** 候选正文接口沿用同源会话、CSRF、管理员和小说归属检查。 */
final class DraftingController extends Controller
{
    public function __invoke(Request $request, DraftingService $service)
    {
        $actor = $request->user() ?? throw new NovelProblem('AUTH_REQUIRED', '请先登录。', 401);
        $novel = $request->route('novel');
        $kind = $request->route('kind');
        $id = $request->route('object');
        $key = $request->header('Idempotency-Key', '');
        $d = $request->except('_token');
        $action = $request->route('draftAction');
        $data = match ($action) {
            'start' => $service->start($actor, $novel, $d, $key),
            'assemble' => $service->assemble($actor, $novel, $id, $d, $key),
            'save' => $service->save($actor, $novel, $kind, $id, $d, $key),
            'read' => $service->read($actor, $novel, $kind, $id),
            default => $service->listing($actor, $novel, $request->query()),
        };

        return response()->json(['data' => $data, 'request_id' => (string) Str::uuid(), 'version' => 'v1'], $action === 'start' ? 202 : ($request->isMethod('post') ? 201 : 200))->header('Cache-Control', 'private, no-store');
    }
}
