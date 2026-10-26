@props(['filters' => false])
{{-- 此组件仅负责响应式布局，不嵌套 form，不接管提交和数据状态。 --}}
<div {{ $attributes->class([$filters ? 'nf-filter-grid' : 'nf-form-grid']) }}>
    {{ $slot }}
</div>
