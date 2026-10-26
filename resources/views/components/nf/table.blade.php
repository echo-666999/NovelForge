@props(['label'])
{{-- 横向滚动仅发生于本区域；提供名称和键盘焦点，实际数据表仍由调用方/Filament提供。 --}}
<div role="region" aria-label="{{ $label }}" tabindex="0" {{ $attributes->class(['nf-table-region']) }}>
    {{ $slot }}
</div>
