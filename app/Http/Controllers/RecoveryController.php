<?php

namespace App\Http\Controllers;

use App\Exceptions\NovelProblem;
use App\Services\RecoveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** 恢复操作沿用同源会话、CSRF及小说归属鉴权。 */
final class RecoveryController extends Controller
{
    public function __invoke(Request $request, RecoveryService $service)
    {
        if (! $request->user()) {
            throw new NovelProblem('AUTH_REQUIRED', '请先登录。', 401);
        }
        $novel = $request->route('novel');
        $run = $request->route('run');
        $data = $request->isMethod('get')
            ? $service->preview($request->user(), $novel, $run, $request->validate(['changed_scopes' => 'sometimes|array', 'changed_scopes.*' => 'string'])['changed_scopes'] ?? [])
            : $service->execute($request->user(), $novel, $run, $request->route('recoveryAction'), $request->except('_token'), $request->header('Idempotency-Key', ''));

        return response()->json(['data' => $data, 'request_id' => (string) Str::uuid(), 'version' => 'v1']);
    }
}
