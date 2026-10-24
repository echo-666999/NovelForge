<?php

namespace App\Http\Controllers;

use App\Exceptions\NovelProblem;
use App\Services\StoryConsistencyService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** 同源时间线接口只操作候选版本，不提供正式状态提交。 */
final class StoryConsistencyController extends Controller
{
    public function __invoke(Request $request, StoryConsistencyService $service)
    {
        $actor = $request->user() ?? throw new NovelProblem('AUTH_REQUIRED', '请先登录。', 401);
        $novel = $request->route('novel');
        $id = $request->route('context');
        if ($request->isMethod('post')) {
            $data = $service->save($actor, $novel, $request->except('_token'), $request->header('Idempotency-Key', ''));
        } elseif ($request->route('consistencyAction') === 'pov') {
            $v = $request->validate(['knower_id' => 'required|string', 'chapter' => 'required|integer|min:1']);
            $data = $service->pov($actor, $novel, $id, $v['knower_id'], (int) $v['chapter']);
        } else {
            $data = $id ? $service->read($actor, $novel, $id) : $service->listing($actor, $novel);
        }

        return response()->json(['data' => $data, 'request_id' => (string) Str::uuid(), 'version' => 'v1'])->header('Cache-Control', 'private, no-store');
    }
}
