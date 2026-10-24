<?php

namespace App\Http\Controllers;

use App\Exceptions\NovelProblem;
use App\Services\ProviderConfigurationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** 密钥采用普通POST写入，不进入Livewire公开属性和响应快照。 */
final class ProviderConfigurationController extends Controller
{
    public function __invoke(Request $request, ProviderConfigurationService $service)
    {
        if (! $request->user()) {
            throw new NovelProblem('AUTH_REQUIRED', '请先登录。', 401);
        }
        if ($request->isMethod('get')) {
            $data = $service->listing($request->user(), $request->query());
        } else {
            $p = $request->except('_token', 'command_key');
            if (! $request->expectsJson()) {
                if (isset($p['allowed_hosts_text'])) {
                    $p['allowed_hosts'] = array_values(array_filter(array_map('trim', explode(',', $p['allowed_hosts_text']))));
                    unset($p['allowed_hosts_text']);
                }
                if (isset($p['declared_capabilities_json'])) {
                    $p['declared_capabilities'] = json_decode($p['declared_capabilities_json'], true);
                    unset($p['declared_capabilities_json']);
                }
                if (array_key_exists('enabled', $p)) {
                    $p['enabled'] = $p['enabled'] === '1';
                }
            }
            try {
                $data = $service->execute($request->user(), $request->route('providerCommand'), $p, $request->header('Idempotency-Key', $request->input('command_key', '')));
            } catch (ValidationException|NovelProblem $e) {
                if ($request->expectsJson()) {
                    throw $e;
                }

                // 不带withInput，也不闪存密钥；使用安全错误文案。
                return redirect('/admin/providers')->with('provider_error', $e instanceof NovelProblem ? $e->getMessage() : '配置校验未通过，请核对必填字段和范围。');
            }
            if (! $request->expectsJson()) {
                return redirect('/admin/providers')->with('provider_notice', '已保存不可变配置版本，能力仍为未验证。');
            }
        }

        return response()->json(['data' => $data, 'request_id' => (string) Str::uuid(), 'version' => 'v1']);
    }
}
