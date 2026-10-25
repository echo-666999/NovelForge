<?php

namespace App\Http\Controllers;

use App\Exceptions\NovelProblem;
use App\Services\ExtractionPipelineService;
use App\Services\ExtractionService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** E03 同源会话/CSRF沿用项目中间件；同步离线回放不冒充异步模型运行。 */
final class ExtractionController extends Controller
{
    public function __invoke(Request $request, ExtractionService $service)
    {
        $actor = $request->user() ?? throw new NovelProblem('AUTH_REQUIRED', '请先登录。', 401);
        $novel = $request->route('novel');
        $id = $request->route('extraction');
        if ($request->route('extractionAction') === 'start') {
            $data = app(ExtractionPipelineService::class)->start($actor, $novel, $request->except('_token'), $request->header('Idempotency-Key', ''));

            return response()->json(['data' => $data, 'request_id' => (string) Str::uuid(), 'version' => 'v1'], 202)->header('Cache-Control', 'private, no-store');
        }
        $data = $request->isMethod('post') ? $service->extract($actor, $novel, $request->except('_token'), $request->header('Idempotency-Key', '')) : ($id ? $service->read($actor, $novel, $id) : $service->listing($actor, $novel));

        return response()->json(['data' => $data, 'request_id' => (string) Str::uuid(), 'version' => 'v1'], $request->isMethod('post') ? 201 : 200)->header('Cache-Control', 'private, no-store');
    }
}
