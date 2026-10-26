@props(['id', 'label', 'model' => null, 'name' => null, 'value' => '', 'options' => [], 'disabled' => false])
@php
    // Livewire筛选沿用原更新链；GET导航仅改变隐藏表单值，确认切书后才重建整页上下文。
    $live = $model !== null;
    $path = $live ? $model : $name;
    if (! is_string($path) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.(?:[A-Za-z_][A-Za-z0-9_]*|[0-9]+))*$/D', $path)) {
        throw new \InvalidArgumentException('无效的选择字段路径。');
    }
    $selectedLabel = $options[(string) $value] ?? '请选择';
@endphp
<x-nf.field :id="$id" :label="$label">
    <div @if(!$live) x-data="{ selection: @js((string) $value), labels: @js($options) }" @endif>
        @if(!$live)<input type="hidden" name="{{ $name }}" value="{{ $value }}" x-bind:value="selection">@endif
        <x-filament::dropdown placement="bottom-start" :shift="true" max-height="18rem"
            {{ $attributes->class(['nf-select']) }} wire:key="nf-select-{{ $id }}">
            <x-slot:trigger>
                <button type="button" id="{{ $id }}" class="nf-select-trigger" aria-label="{{ $label }}：{{ $selectedLabel }}" @disabled($disabled)
                    @if(!$live) x-bind:aria-label="@js($label.'：') + (labels[selection] ?? '请选择')" @endif>
                    <span class="nf-select-value" @if(!$live) x-text="labels[selection] ?? '请选择'" @endif>{{ $selectedLabel }}</span>
                    <x-filament::icon icon="heroicon-m-chevron-down" class="nf-select-chevron" />
                </button>
            </x-slot:trigger>
            <x-filament::dropdown.list>
                @foreach($options as $key => $text)
                    @php
                        $selected = (string) $value === (string) $key;
                        $bindings = $live
                            ? ['wire:click' => '$set('.\Illuminate\Support\Js::from($model).', '.\Illuminate\Support\Js::from((string) $key).')', 'x-on:click' => 'close()']
                            : ['x-on:click' => 'selection = '.\Illuminate\Support\Js::from((string) $key).'; close()', 'x-bind:aria-checked' => 'selection === '.\Illuminate\Support\Js::from((string) $key)];
                    @endphp
                    <x-filament::dropdown.list.item role="menuitemradio" :aria-checked="$selected ? 'true' : 'false'"
                        icon="heroicon-m-check" :color="$live && $selected ? 'primary' : 'gray'" :disabled="$disabled"
                        :attributes="new \Illuminate\View\ComponentAttributeBag($bindings)">
                        {{ $text }}
                    </x-filament::dropdown.list.item>
                @endforeach
            </x-filament::dropdown.list>
        </x-filament::dropdown>
    </div>
</x-nf.field>
