<?php

namespace App\Http\Controllers;

use App\Exceptions\NovelProblem;
use App\Services\AuthoringConfigurationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** 管理接口与后台表单共用配置服务，保持会话、CSRF 和幂等语义。 */
final class AuthoringConfigurationController extends Controller
{
    public function __invoke(Request $request, AuthoringConfigurationService $service)
    {
        if (! $request->user()) {
            throw new NovelProblem('AUTH_REQUIRED', '请先登录。', 401);
        }
        $kind = $request->route('configurationKind');
        $target = match ($kind) {
            'prompt' => 'prompt-templates','route' => 'model-routes','style' => 'style-profiles'
        };
        try {
            if ($request->isMethod('get')) {
                $data = $service->listing($request->user(), $kind, $request->query());
            } else {
                $p = $request->except('_token', 'command_key', 'payload_json');
                if (! $request->expectsJson() && $request->has('payload_json')) {
                    try {
                        $p = json_decode($request->input('payload_json'), true, 32, JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        throw new NovelProblem('INVALID_JSON', '配置必须为有效 JSON 对象。', 422);
                    }
                    if (! is_array($p) || array_is_list($p)) {
                        throw new NovelProblem('INVALID_JSON', '配置必须为对象。', 422);
                    }
                }
                $data = $service->execute($request->user(), $kind, $request->route('configurationAction'), $p, $request->header('Idempotency-Key', $request->input('command_key', '')));
            }
        } catch (NovelProblem|ValidationException $e) {
            if ($request->expectsJson()) {
                throw $e;
            }

            return redirect('/admin/'.$target)->with('configuration_error', $e instanceof NovelProblem ? $e->getMessage() : '字段、引用或范围校验失败，请检查配置。');
        }
        if (! $request->isMethod('get') && ! $request->expectsJson()) {
            return redirect('/admin/'.$target)->with('configuration_notice', '操作已记录；回归详情见对应版本。离线通过不代表真实模型验证。');
        }

        return response()->json(['data' => $data, 'request_id' => (string) Str::uuid(), 'version' => 'v1']);
    }
}
