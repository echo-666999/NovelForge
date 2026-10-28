@php
    $formId='model-'.($model['version_id']??'new');$failed=session('provider_form')===$formId;
    $cap=$model['declared_capabilities']??[];
    $val=fn($key,$default='')=>$failed?old($key,$default):$default;
    $err=fn($key)=>$failed?$errors->first($key):null;
@endphp
{{-- 能力声明逐字段编辑，保存新版本不把声明提升为实测。 --}}
<form id="{{ $formId }}" method="post" action="/api/v1/providers/model" class="nf-config-editor">
    @if($failed)<x-nf.state state="error" title="此表单未保存">{{ session('provider_error') }}</x-nf.state>@endif
    @csrf<input type="hidden" name="command_key" value="{{ Str::uuid() }}"><input type="hidden" name="ui_form" value="{{ $formId }}">
    <input type="hidden" name="expected_version" value="{{ $val('expected_version',$model['version']??0) }}">
    <x-nf.form-grid>
        @if($model)<input type="hidden" name="provider_id" value="{{ $model['provider_id'] }}"><p>供应商：{{ $providerOptions[$model['provider_id']]??$model['provider_id'] }} · 来源版本 {{ $model['version'] }}（#{{ $model['version_id'] }}）</p>
        @else<x-nf.select :id="$formId.'-provider'" label="供应商" name="provider_id" :value="$val('provider_id','')" :options="[''=>'请选择实际供应商']+$providerOptions" />@endif
        <x-nf.input :id="$formId.'-name'" label="模型标识" name="provider_model_id" :value="$val('provider_model_id',$model['model']??'')" :readonly="(bool)$model" :required="true" maxlength="200" :error="$err('provider_model_id')" />
        <x-nf.input :id="$formId.'-context'" label="声明上下文 Token 上限" type="number" name="context_tokens" :value="$val('context_tokens',$cap['context_tokens']??'')" min="1" max="10000000" help="未知留空，不等于0。" :error="$err('context_tokens')" />
        <x-nf.input :id="$formId.'-output'" label="声明输出 Token 上限" type="number" name="max_output_tokens" :value="$val('max_output_tokens',$cap['max_output_tokens']??'')" min="1" max="10000000" help="未知留空；不得大于上下文容量。" :error="$err('max_output_tokens')" />
        @foreach(['structured_output'=>'结构化输出','streaming'=>'流式输出'] as $key=>$text)
            <x-nf.select :id="$formId.'-'.$key" :label="$text.'声明'" :name="$key" :value="$val($key,$cap[$key]??'unknown')" :options="['unknown'=>'未知','supported'=>'声明支持','unsupported'=>'声明不支持']" />
        @endforeach
        <x-nf.select :id="$formId.'-reasoning'" label="推理 Token 计量声明" name="reasoning_metering" :value="$val('reasoning_metering',$cap['reasoning_metering']??'unknown')" :options="['unknown'=>'未知','included'=>'包含在常规计量','separate'=>'单独计量']" />
        <x-nf.input :id="$formId.'-source'" label="能力声明来源说明" name="source_note" :value="$val('source_note',$cap['source_note']??'')" :required="true" maxlength="2000" :error="$err('source_note')" />
        <x-nf.input :id="$formId.'-reason'" label="变更原因" name="reason" :value="$val('reason','')" :required="true" maxlength="2000" :error="$err('reason')" />
    </x-nf.form-grid>
    {{-- UI23：保存仍是原生POST；取消仅关闭弹窗，不提交表单。 --}}
    <div class="nf-config-actions"><x-filament::button type="submit" :disabled="count($providerOptions)===0">保存模型新版本</x-filament::button>
        <x-filament::button type="button" color="gray" x-on:click="$dispatch('close-modal',{id:'nf-model-editor-modal'})">取消编辑</x-filament::button>
    </div>
</form>
