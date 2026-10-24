<?php

namespace App\Http\Controllers;

use App\Exceptions\NovelProblem;
use App\Services\ForeshadowLedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** 同源会话与 CSRF 保护的作者账本接口，不提供正式状态写入口。 */
final class ForeshadowLedgerController extends Controller
{
    public function __invoke(Request $request, ForeshadowLedgerService $service)
    {
        $actor = $request->user() ?? throw new NovelProblem('AUTH_REQUIRED', '请先登录。', 401);
        $novel = $request->route('novel');
        $data = $request->isMethod('post')
            ? $service->execute($actor, $novel, $request->route('ledgerCommand'), $request->except('_token'), $request->header('Idempotency-Key', ''))
            : ($request->route('plan') ? $service->read($actor, $novel, $request->route('plan')) : $service->ledger($actor, $novel, $request->query()));

        return response()->json(['data' => $data, 'request_id' => (string) Str::uuid(), 'version' => 'v1'])->header('Cache-Control', 'private, no-store');
    }
}
