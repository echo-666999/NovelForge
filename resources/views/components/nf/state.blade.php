@props(['state' => 'notice', 'title'])
@php
    // 状态白名单防止动态类名失配；技术状态由页面映射，组件不推断业务成功。
    if (! in_array($state, ['notice', 'loading', 'empty', 'ready', 'error', 'disabled', 'success'], true)) {
        throw new \InvalidArgumentException('未知界面状态。');
    }
    $stateClass = match ($state) {
        'error' => 'nf-state-error',
        'success' => 'nf-state-success',
        default => null,
    };
@endphp
<div role="{{ $state === 'error' ? 'alert' : 'status' }}" aria-live="{{ $state === 'error' ? 'assertive' : 'polite' }}"
    @if($state === 'loading') aria-busy="true" @endif
    {{ $attributes->class(['nf-state', $stateClass]) }}>
    <p class="nf-state-title">{{ $title }}</p>
    <div class="nf-state-content">{{ $slot }}</div>
    @isset($actions)<div class="nf-state-actions">{{ $actions }}</div>@endisset
</div>
