@props(['heading' => null, 'description' => null])
{{-- 复用原生 Section 的标题、说明与内容容器，不额外发明卡片行为。 --}}
<x-filament::section :heading="$heading" :description="$description" {{ $attributes->class(['nf-section']) }}>
    {{ $slot }}
</x-filament::section>
