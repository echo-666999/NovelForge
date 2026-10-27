@props(['id','label','model','value'=>null,'multiple'=>false,'error'=>null])
@php
    $error ??= isset($errors) ? $errors->first($model) : null;
    // 复用旧视图已授权读取的option资料，不增加查询或重写业务值；禁止外部实体加载。
    $document = new \DOMDocument;
    $previousErrors=libxml_use_internal_errors(true);
    try {
        $document->loadHTML('<?xml encoding="UTF-8"><select>'.(string)$slot.'</select>', LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
        $options=[];
        foreach($document->getElementsByTagName('option') as $option){
            $key=$option->hasAttribute('value')?$option->getAttribute('value'):$option->textContent;
            $options[$key]=trim($option->textContent);
        }
    } finally { libxml_clear_errors();libxml_use_internal_errors($previousErrors); }
@endphp
@if($multiple)
    <fieldset class="nf-story-multiple" id="{{ $id }}" tabindex="-1" aria-invalid="{{ $error ? 'true' : 'false' }}" @if($error) aria-describedby="{{ $id }}-error" @endif><legend>{{ $label }}</legend>
        @if($error)<p id="{{ $id }}-error" role="alert">{{ $error }}</p>@endif
        <p class="nf-field-help">逐项选择，保留实际版本引用。</p>
        @forelse($options as $key=>$text)
            <label class="nf-story-check"><input type="checkbox" wire:model.live="{{ $model }}" value="{{ $key }}" @checked(in_array((string)$key,array_map('strval',(array)$value),true))>{{ $text }}</label>
        @empty<p>暂无可选记录；不会自动填入其他小说的数据。</p>@endforelse
    </fieldset>
@else
    <x-nf.select :id="$id" :label="$label" :model="$model" :value="$value??''" :options="$options" :error="$error" />
@endif
