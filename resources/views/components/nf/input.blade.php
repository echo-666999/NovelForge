@props(['id', 'label', 'value' => null, 'type' => 'text', 'help' => null, 'error' => null, 'required' => false, 'disabled' => false, 'readonly' => false])
@php
    // 统一生成辅助描述关联，避免调用方漏接字段错误；仍允许原生属性和 wire:model 透传。
    $describedBy = trim(implode(' ', array_filter([
        $attributes->get('aria-describedby'),
        $help ? $id.'-help' : null,
        $error ? $id.'-error' : null,
    ])));
@endphp
<x-nf.field :id="$id" :label="$label" :help="$help" :error="$error" :required="$required">
    <x-filament::input.wrapper :disabled="$disabled" :valid="! filled($error)">
        <x-filament::input :id="$id" :type="$type" :value="$value" :disabled="$disabled" :readonly="$readonly" :required="$required"
            aria-invalid="{{ $error ? 'true' : 'false' }}"
            {{ $attributes->except('aria-describedby')->merge($describedBy !== '' ? ['aria-describedby' => $describedBy] : []) }} />
    </x-filament::input.wrapper>
</x-nf.field>
