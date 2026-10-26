@props(['id', 'label', 'help' => null, 'error' => null, 'required' => false, 'full' => false])
{{-- 调用方为控件设置同一 id，并以 aria-describedby 关联下面的帮助/错误文本。 --}}
<x-filament-forms::field-wrapper :id="$id" :label="$label" :required="$required" :has-errors="false"
    {{ $attributes->class(['nf-field', 'nf-field-full' => $full]) }}>
    {{ $slot }}
    @if($help)<p id="{{ $id }}-help" class="nf-field-help">{{ $help }}</p>@endif
    @if($error)<p id="{{ $id }}-error" class="nf-field-error" role="alert">{{ $error }}</p>@endif
</x-filament-forms::field-wrapper>
