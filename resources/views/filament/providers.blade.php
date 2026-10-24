<x-filament-panels::page>
    {{-- 原生表单提交密钥，禁止将历史密钥或引用绑定到前端。 --}}
    @php($catalog = $this->catalog())
    @if(session('provider_notice')) <p role="status">{{ session('provider_notice') }}</p> @endif
    @if(session('provider_error')) <p role="alert">{{ session('provider_error') }}</p> @endif
    <x-filament::section heading="供应商与安全配置">
        <p>保存配置不会发起模型请求。真实调用、连通探测与能力验证尚未开放；声明能力不代表实测能力。</p>
        <div class="flex flex-wrap gap-3">
            <label>名称 <input wire:model.live.debounce.300ms="q" aria-label="供应商名称筛选"></label>
            <label>接口 <select wire:model.live="interface"><option value="">全部</option><option value="fake">离线模拟</option><option value="json_http">通用 JSON 协议</option></select></label>
            <label>状态 <select wire:model.live="enabled"><option value="">全部</option><option value="1">启用配置</option><option value="0">停用配置</option></select></label>
        </div>
        @forelse($catalog['providers'] as $provider)
            <div class="my-4 rounded-lg border p-4">
                <h3>{{ $provider['name'] }} · 版本 {{ $provider['version'] }} · {{ $provider['enabled'] ? '已启用配置' : '已停用' }}</h3>
                <p>{{ $provider['endpoint'] }} · 密钥{{ $provider['has_key'] ? '已配置' : '未配置' }} · 能力未验证 · 费率未知 · 未分配使用阶段</p>
                <p>超时 {{ $provider['timeout_seconds'] }} 秒 / 连接 {{ $provider['connect_timeout_seconds'] }} 秒 · 每分钟 {{ $provider['requests_per_minute'] }} 次 · 并发 {{ $provider['max_concurrency'] }}</p>
                <details><summary>修改配置、停用或轮换密钥</summary>@include('filament.provider-form', ['provider' => $provider])</details>
            </div>
        @empty
            <p>暂无符合条件的供应商。可先建立配置，真实请求仍保持关闭。</p>
        @endforelse
        <p>共 {{ $catalog['total'] }} 个供应商，第 {{ $catalog['page'] }} 页</p>
        <x-filament::button wire:click="$set('page', {{ max(1,$page-1) }})" :disabled="$page <= 1">上一页</x-filament::button>
        <x-filament::button wire:click="$set('page', {{ $page+1 }})" :disabled="$page*25 >= $catalog['total']">下一页</x-filament::button>
    </x-filament::section>
    <x-filament::section heading="新增供应商">@include('filament.provider-form', ['provider' => null])</x-filament::section>
    <x-filament::section heading="模型能力档案">
        <label>声明的结构化能力（尚未实测） <select wire:model.live="structured"><option value="">全部</option><option value="unknown">未知</option><option value="supported">声明支持</option><option value="unsupported">声明不支持</option></select></label>
        <div class="overflow-x-auto"><table class="w-full"><thead><tr><th>供应商标识</th><th>模型</th><th>版本</th><th>验证状态</th><th>声明与来源</th></tr></thead><tbody>
        @forelse($catalog['models'] as $model)
            <tr><td>{{ $model['provider_id'] }}</td><td>{{ $model['model'] }}</td><td>{{ $model['version'] }}</td><td>unverified · 未验证</td><td><pre class="whitespace-pre-wrap">{{ json_encode($model['declared_capabilities'], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) }}</pre></td></tr>
        @empty <tr><td colspan="5">暂无模型档案。</td></tr> @endforelse
        </tbody></table></div>
        <p>本页展示当前筛选供应商下最新的最多100个档案。模型路由与主备策略、价格版本将在后续任务开放。</p>
        <form method="post" action="/api/v1/providers/model" class="space-y-3">
            @csrf <input type="hidden" name="command_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">
            <label>供应商 <select name="provider_id" required>@foreach($catalog['providers'] as $provider)<option value="{{ $provider['id'] }}">{{ $provider['name'] }}</option>@endforeach</select></label>
            <label>模型标识 <input name="provider_model_id" required maxlength="200"></label>
            <label>当前版本（新增填0） <input type="number" name="expected_version" min="0" value="0" required></label>
            <label class="block">声明能力与来源<textarea class="block w-full" name="declared_capabilities_json" rows="6" required>{{ json_encode(['context_tokens'=>null,'max_output_tokens'=>null,'structured_output'=>'unknown','streaming'=>'unknown','reasoning_metering'=>'unknown','source_note'=>'请填写能力声明的来源说明，未实测保持未知'],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) }}</textarea></label>
            <label>变更原因 <input name="reason" required maxlength="2000"></label>
            <x-filament::button type="submit">保存模型新版本</x-filament::button>
        </form>
        <x-filament::button disabled>真实能力验证待部署条件与预算门禁</x-filament::button>
    </x-filament::section>
</x-filament-panels::page>
