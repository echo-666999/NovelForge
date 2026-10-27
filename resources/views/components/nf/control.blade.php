@props(['tag' => 'input'])
@aware(['id' => null, 'help' => null, 'error' => null])
@php
    // 父Field定义错误/帮助；保留外部描述，移除已消失的本字段描述引用。
    abort_unless(in_array($tag, ['input', 'textarea', 'select'], true), 422);
    $id = $attributes->get('id', $id);
    $descriptions = array_diff(preg_split('/\s+/', trim($attributes->get('aria-describedby', ''))), [$id.'-help', $id.'-error', '']);
    if ($help) $descriptions[] = $id.'-help';
    if ($error) $descriptions[] = $id.'-error';
    $control = $attributes->except(['aria-describedby', 'aria-invalid'])->merge(['id' => $id, 'aria-invalid' => $error ? 'true' : 'false']);
    if ($descriptions) $control = $control->merge(['aria-describedby' => implode(' ', array_unique($descriptions))]);
@endphp
@if($tag === 'textarea')<textarea {{ $control }}>{{ $slot }}</textarea>
@elseif($tag === 'select')<select {{ $control }}>{{ $slot }}</select>
@else<input {{ $control }} />@endif
