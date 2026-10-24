<form method="post" action="/api/v1/providers/provider" class="space-y-3">
    @csrf <input type="hidden" name="command_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">
    @if($provider)<input type="hidden" name="provider_id" value="{{ $provider['id'] }}">@endif
    <input type="hidden" name="expected_version" value="{{ $provider['version'] ?? 0 }}">
    <label>名称 <input name="name" value="{{ $provider['name'] ?? '' }}" @readonly($provider) maxlength="100" required></label>
    <label>接口 <select name="interface_type"><option value="fake" @selected(($provider['interface_type'] ?? 'fake') === 'fake')>离线模拟</option><option value="json_http" @selected(($provider['interface_type'] ?? '') === 'json_http')>通用 JSON 协议（真实调用关闭）</option></select></label>
    <label class="block">HTTPS 端点 <input class="w-full" type="url" name="endpoint" value="{{ $provider['endpoint'] ?? '' }}" required></label>
    <label class="block">允许的精确主机（逗号分隔） <input class="w-full" name="allowed_hosts_text" value="{{ implode(',', $provider['allowed_hosts'] ?? []) }}" required></label>
    <label>新密钥（留空保留原密钥） <input type="password" name="api_key" autocomplete="new-password" minlength="8" maxlength="4096"></label>
    <label>总超时秒数 <input type="number" name="timeout_seconds" value="{{ $provider['timeout_seconds'] ?? 20 }}" min="1" max="30" required></label>
    <label>连接超时秒数 <input type="number" name="connect_timeout_seconds" value="{{ $provider['connect_timeout_seconds'] ?? 5 }}" min="1" max="30" required></label>
    <label>每分钟请求上限 <input type="number" name="requests_per_minute" value="{{ $provider['requests_per_minute'] ?? 10 }}" min="1" max="600" required></label>
    <label>并发上限 <input type="number" name="max_concurrency" value="{{ $provider['max_concurrency'] ?? 1 }}" min="1" max="20" required></label>
    <label>配置状态 <select name="enabled"><option value="0" @selected(! ($provider['enabled'] ?? false))>停用</option><option value="1" @selected($provider['enabled'] ?? false)>启用配置</option></select></label>
    <label>变更原因 <input name="reason" required maxlength="2000"></label>
    <x-filament::button type="submit">保存配置新版本</x-filament::button>
</form>
