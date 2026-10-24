<?php

namespace App\Http\Controllers;

use App\Exceptions\NovelProblem;
use App\Services\BillingConfigurationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** 同源管理员费用接口；没有真实模型调用或自动批准预算的入口。 */
final class BillingController extends Controller
{
    public function __invoke(Request $request, BillingConfigurationService $service)
    {
        if (! $request->user()) {
            throw new NovelProblem('AUTH_REQUIRED', '请先登录。', 401);
        }
        try {
            if ($request->route('billingAction') === 'export') {
                return response($service->export($request->user(), $request->query()), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename=novelforge-costs.csv', 'Cache-Control' => 'no-store']);
            }
            if ($request->isMethod('get')) {
                $data = $service->listing($request->user(), $request->query());
            } else {
                $p = $request->except('_token', 'command_key', 'payload_json');
                if (! $request->expectsJson() && $request->has('payload_json')) {
                    try {
                        $p = json_decode($request->input('payload_json'), true, 32, JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        throw new NovelProblem('INVALID_JSON', '请输入有效JSON。', 422);
                    }
                    if (! is_array($p) || array_is_list($p)) {
                        throw new NovelProblem('INVALID_JSON', '计费配置必须为对象。', 422);
                    }
                }
                $data = $service->execute($request->user(), $request->route('billingAction'), $p, $request->header('Idempotency-Key', $request->input('command_key', '')));
            }
        } catch (NovelProblem|ValidationException $e) {
            if ($request->expectsJson()) {
                throw $e;
            }

            return redirect('/admin/billing')->with('billing_error', $e instanceof NovelProblem ? $e->getMessage() : '计费字段或范围校验失败。');
        }
        if (! $request->isMethod('get') && ! $request->expectsJson()) {
            return redirect('/admin/billing')->with('billing_notice', '已保存计费配置或凭证对账记录。');
        }

        return response()->json(['data' => $data, 'request_id' => (string) Str::uuid(), 'version' => 'v1']);
    }
}
