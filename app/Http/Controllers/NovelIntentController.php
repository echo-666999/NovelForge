<?php

namespace App\Http\Controllers;

use App\Exceptions\NovelProblem;
use App\Services\NovelIntentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class NovelIntentController extends Controller
{
    public function __invoke(Request $request, NovelIntentService $service): JsonResponse
    {
        if (! $request->user()) {
            throw new NovelProblem('AUTH_REQUIRED', '请先登录。', 401);
        }
        $service->authorize($request->user());
        $novel = $request->route('novel');
        $command = $request->route('command');
        if ($command === 'requirements') {
            $data = $service->paginatedRequirements($request->user(), $novel, $request->query());
        } elseif ($command === 'intent') {
            $data = $service->snapshot($request->user(), $service->novel($request->user(), $novel));
        } else {
            $data = $service->execute($request->user(), $command, $novel, $request->except('_token'), $request->header('Idempotency-Key', ''));
        }

        return response()->json(['data' => $data, 'request_id' => (string) Str::uuid(), 'version' => 'v1'], $command === 'create' || $command === 'copy' ? 201 : 200);
    }
}
