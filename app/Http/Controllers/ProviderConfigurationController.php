<?php

namespace App\Http\Controllers;

use App\Exceptions\NovelProblem;
use App\Services\ProviderConfigurationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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
                unset($p['ui_form']);
                // UI04普通POST逐字段映射模型声明，旧JSON接口保持原契约。
                if ($request->route('providerCommand') === 'model' && is_string($request->input('ui_form')) && str_starts_with($request->input('ui_form'), 'model-')) {
                    $capabilities = [];
                    foreach (['context_tokens', 'max_output_tokens', 'structured_output', 'streaming', 'reasoning_metering', 'source_note'] as $field) {
                        $value = $p[$field] ?? null;
                        unset($p[$field]);
                        if (in_array($field, ['context_tokens', 'max_output_tokens'], true)) {
                            $value = $value === '' ? null : $value;
                            if (is_string($value) && preg_match('/^[0-9]{1,8}$/D', $value)) {
                                $value = (int) $value;
                            }
                        }
                        $capabilities[$field] = $value;
                    }
                    $p['declared_capabilities'] = $capabilities;
                }
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

                // 只回填白名单非敏感字段；密钥、CSRF和幂等键不进入旧输入或错误内容。
                $safe = $request->only(['name', 'interface_type', 'endpoint', 'allowed_hosts', 'timeout_seconds', 'connect_timeout_seconds', 'requests_per_minute', 'max_concurrency', 'enabled', 'reason', 'expected_version', 'provider_id', 'provider_model_id', 'context_tokens', 'max_output_tokens', 'structured_output', 'streaming', 'reasoning_metering', 'source_note']);
                $form = $request->input('ui_form', '');
                $form = is_string($form) && preg_match('/^(provider|model)-(new|[1-9][0-9]*)$/D', $form) ? $form : '';
                $errors = $e instanceof ValidationException ? collect($e->errors())->mapWithKeys(fn ($messages, $field) => [str_replace('declared_capabilities.', '', $field) => $messages])->all() : [];

                return redirect($this->returnToProviders($request, $form))->with('provider_error', $e instanceof NovelProblem ? $e->getMessage() : '配置校验未通过，请核对字段旁的错误。')->with('provider_form', $form)->withErrors($errors)->withInput($safe);
            }
            if (! $request->expectsJson()) {
                return redirect($this->returnToProviders($request))->with('provider_notice', '已保存不可变配置版本，能力仍为未验证。');
            }
        }

        return response()->json(['data' => $data, 'request_id' => (string) Str::uuid(), 'version' => 'v1']);
    }

    /** 只保留同源供应商页的合法筛选，不把Referer或客户端任意URL当跳转目标。 */
    private function returnToProviders(Request $request, string $form = ''): string
    {
        $url = parse_url($request->headers->get('referer', ''));
        $filters = [];
        if (is_array($url) && ($url['path'] ?? '') === '/admin/providers' && ($url['host'] ?? '') === $request->getHost() && ($url['scheme'] ?? '') === $request->getScheme() && ($url['port'] ?? $request->getPort()) === $request->getPort()) {
            parse_str($url['query'] ?? '', $query);
            $query = array_intersect_key($query, array_flip(['q', 'interface', 'enabled', 'structured', 'page']));
            $validator = Validator::make($query, ['q' => 'nullable|string|max:100', 'interface' => 'nullable|in:fake,json_http', 'enabled' => 'nullable|in:0,1', 'structured' => 'nullable|in:unknown,supported,unsupported', 'page' => 'nullable|integer|min:1|max:100000']);
            if (! $validator->fails()) {
                $filters = $validator->validated();
            }
        }

        return '/admin/providers'.($filters ? '?'.http_build_query($filters) : '').($form !== '' ? '#'.$form : '');
    }
}
