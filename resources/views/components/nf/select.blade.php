@props(['id', 'label', 'model', 'value' => '', 'options' => [], 'disabled' => false])
@php
    // 只接收属性路径，选择仍通过 Livewire 的原更新链路，不另建筛选状态或写接口。
    if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/D', $model)) {
        throw new \InvalidArgumentException('无效的选择字段路径。');
    }
    $selectedLabel = $options[(string) $value] ?? '请选择';
@endphp
<x-nf.field :id="$id" :label="$label">
    <x-filament::dropdown placement="bottom-start" :shift="true" max-height="18rem"
        {{ $attributes->class(['nf-select']) }} wire:key="nf-select-{{ $id }}">
        <x-slot:trigger>
            <button type="button" id="{{ $id }}" class="nf-select-trigger" aria-label="{{ $label }}：{{ $selectedLabel }}" @disabled($disabled)>
                <span class="nf-select-value">{{ $selectedLabel }}</span>
                <x-filament::icon icon="heroicon-m-chevron-down" class="nf-select-chevron" />
            </button>
        </x-slot:trigger>
        <x-filament::dropdown.list>
            @foreach($options as $key => $text)
                @php
                    $selected = (string) $value === (string) $key;
                    $command = '$set('.\Illuminate\Support\Js::from($model).', '.\Illuminate\Support\Js::from((string) $key).')';
                @endphp
                <x-filament::dropdown.list.item role="menuitemradio" :aria-checked="$selected ? 'true' : 'false'"
                    icon="heroicon-m-check" :color="$selected ? 'primary' : 'gray'" :disabled="$disabled"
                    :wire:click="$command" x-on:click="close()">
                    {{ $text }}
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>
</x-nf.field>
