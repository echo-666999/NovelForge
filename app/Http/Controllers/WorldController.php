<?php

namespace App\Http\Controllers;

use App\Exceptions\NovelProblem;
use App\Services\WorldService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WorldController extends Controller
{
    public function __invoke(Request $request, WorldService $service)
    {
        if (! $request->user()) {
            throw new NovelProblem('AUTH_REQUIRED', '请先登录。', 401);
        }
        $actor = $request->user();
        $id = $request->route('novel');
        $command = $request->route('worldCommand');
        $result = match ($command) {
            'list' => $service->listing($actor, $id, $request->query()),'detail' => $service->detail($actor, $id, $request->route('entity')),'version' => $service->readVersion($actor, $id, $request->route('definition')),'reference' => $service->assertReference($actor, $id, $request->route('definition')),default => $service->execute($actor, $id, $command, $request->except('_token'), $request->header('Idempotency-Key', ''))
        };

        return response()->json(['data' => $result, 'request_id' => (string) Str::uuid(), 'version' => 'v1']);
    }
}
