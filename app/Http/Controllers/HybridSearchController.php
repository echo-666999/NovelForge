<?php

namespace App\Http\Controllers;

use App\Exceptions\NovelProblem;
use App\Services\HybridSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** 检索沿用会话与CSRF，所有范围仍由服务重新核验。 */
final class HybridSearchController extends Controller
{
    public function __invoke(Request $request, HybridSearchService $service)
    {
        $actor = $request->user() ?? throw new NovelProblem('AUTH_REQUIRED', '请先登录。', 401);
        $novel = $request->route('novel');
        $command = $request->route('searchCommand');
        $data = $command === 'evaluation' ? $service->evaluation($actor, $novel, $request->route('evaluation')) : ($command === 'query' ? $service->search($actor, $novel, $request->except('_token')) : ($request->isMethod('post') ? $service->execute($actor, $novel, $command, $request->except('_token'), $request->header('Idempotency-Key', '')) : $service->listing($actor, $novel)));

        return response()->json(['data' => $data, 'request_id' => (string) Str::uuid(), 'version' => 'v1'])->header('Cache-Control', 'private, no-store');
    }
}
