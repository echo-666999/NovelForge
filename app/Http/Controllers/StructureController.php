<?php

namespace App\Http\Controllers;

use App\Exceptions\NovelProblem;
use App\Services\StructureService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StructureController extends Controller
{
    public function __invoke(Request $request, StructureService $service)
    {
        if (! $request->user()) {
            throw new NovelProblem('AUTH_REQUIRED', '请先登录。', 401);
        }
        $id = $request->route('novel');
        $command = $request->route('structureCommand');
        $data = match ($command) {
            'working' => $service->working($request->user(), $id),
            'versions' => $service->versions($request->user(), $id, $request->query()),
            'version' => $service->version($request->user(), $id, $request->route('outline')),
            default => $service->execute($request->user(), $id, $command, $request->except('_token'), $request->header('Idempotency-Key', '')),
        };

        return response()->json(['data' => $data, 'request_id' => (string) Str::uuid(), 'version' => 'v1'], $command === 'snapshot' ? 201 : 200);
    }
}
