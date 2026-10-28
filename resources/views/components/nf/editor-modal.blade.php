@props(['id', 'heading'])
{{-- UI23：复用原生焦点陷阱、Escape、滚动锁和响应式窗口，不自行实现弹层行为。 --}}
<x-filament::modal :id="$id" :heading="$heading" width="5xl" :sticky-header="true" :close-by-clicking-away="false" {{ $attributes->class(['nf-editor-modal']) }}>
    {{ $slot }}
</x-filament::modal>
