@php
    // 仅本次失败表单回填非敏感字段；历史密钥和secret引用不进入HTML或Livewire状态。
    $formId='provider-'.($provider['id']??'new');
    $failed=session('provider_form')===$formId;
    $val=fn($key,$default='')=>$failed?old($key,$provider[$key]??$default):($provider[$key]??$default);
    $err=fn($key)=>$failed?$errors->first($key):null;
@endphp
<form id="{{ $formId }}" method="post" action="/api/v1/providers/provider" class="nf-config-editor">
    @if($failed)<x-nf.state state="error" title="此表单未保存">{{ session('provider_error') }}</x-nf.state>@endif
    @csrf<input type="hidden" name="command_key" value="{{ Str::uuid() }}"><input type="hidden" name="ui_form" value="{{ $formId }}">
    @if($provider)<input type="hidden" name="provider_id" value="{{ $provider['id'] }}">@endif
    <input type="hidden" name="expected_version" value="{{ $val('expected_version',$provider['version']??0) }}">
    <x-nf.form-grid>
        <x-nf.input :id="$formId.'-name'" label="供应商名称" name="name" :value="$val('name')" :readonly="(bool)$provider" :required="true" :error="$err('name')" />
        <x-nf.select :id="$formId.'-interface'" label="接口类型" name="interface_type" :value="$val('interface_type','fake')" :options="['fake'=>'离线模拟','json_http'=>'通用JSON协议（不会发起请求）']" />
        <x-nf.input :id="$formId.'-endpoint'" label="HTTPS端点" name="endpoint" type="url" :value="$val('endpoint')" :required="true" :error="$err('endpoint')" />
        <x-nf.input :id="$formId.'-key'" label="新密钥" type="password" name="api_key" autocomplete="new-password" minlength="8" maxlength="4096" help="留空保留已有密钥；不回显、不回填、不进入页面快照。" />
        <x-nf.input :id="$formId.'-timeout'" label="总超时（秒）" type="number" name="timeout_seconds" :value="$val('timeout_seconds',20)" min="1" max="30" :required="true" :error="$err('timeout_seconds')" />
        <x-nf.input :id="$formId.'-connect'" label="连接超时（秒）" type="number" name="connect_timeout_seconds" :value="$val('connect_timeout_seconds',5)" min="1" max="30" :required="true" :error="$err('connect_timeout_seconds')" />
        <x-nf.input :id="$formId.'-rpm'" label="每分钟请求上限" type="number" name="requests_per_minute" :value="$val('requests_per_minute',10)" min="1" max="600" :required="true" :error="$err('requests_per_minute')" />
        <x-nf.input :id="$formId.'-concurrency'" label="并发上限" type="number" name="max_concurrency" :value="$val('max_concurrency',1)" min="1" max="20" :required="true" :error="$err('max_concurrency')" />
        <x-nf.select :id="$formId.'-enabled'" label="配置状态" name="enabled" :value="(string)(int)$val('enabled',false)" :options="['0'=>'停用','1'=>'启用配置（不是授权真实请求）']" />
        <x-nf.input :id="$formId.'-reason'" label="变更原因" name="reason" :value="$failed?old('reason',''):''" :required="true" maxlength="2000" :error="$err('reason')" />
    </x-nf.form-grid>
    <fieldset x-data="{ hosts: @js($val('allowed_hosts',[''])) }" class="nf-json-node">
        <legend>允许的精确主机 · 每行一个</legend>
        <template x-for="(host,index) in hosts" :key="index"><div class="nf-config-actions">
            <label :for="@js($formId.'-host-')+index">主机 <span x-text="index+1"></span></label>
            <input :id="@js($formId.'-host-')+index" name="allowed_hosts[]" x-model="hosts[index]" required maxlength="253">
            <button type="button" x-on:click="hosts.splice(index,1)" :disabled="hosts.length===1">移除</button>
        </div></template>
        <x-filament::button color="gray" type="button" x-on:click="hosts.push('')" x-bind:disabled="hosts.length>=20">添加主机</x-filament::button>
        @if($failed)@foreach($errors->get('allowed_hosts.*') as $messages)@foreach($messages as $message)<p role="alert">{{ $message }}</p>@endforeach
@endforeach
@endif
    </fieldset>
    <x-filament::button type="submit">保存供应商新版本</x-filament::button>
</form>
