@props(['id', 'label', 'help' => null, 'error' => null, 'required' => false, 'full' => false])
{{-- 原生控件复用nf.control继承本字段的id与说明；自定义控件须提供同等ARIA关联。 --}}
<x-filament-forms::field-wrapper :id="$id" :label="$label" :required="$required" :has-errors="false"
    {{ $attributes->class(['nf-field', 'nf-field-full' => $full]) }}>
    {{ $slot }}
    @if($help)<p id="{{ $id }}-help" class="nf-field-help">{{ $help }}</p>@endif
    @if($error)<p id="{{ $id }}-error" class="nf-field-error" role="alert">{{ $error }}</p>@endif
</x-filament-forms::field-wrapper>
