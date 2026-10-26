@php
    // 递归仅遍历当前树，不预生成指数增长的空表单；字段名作为值，不参与Livewire路径。
    $id='nf-json-'.str_replace('.', '-', $path);
    $labels=['type'=>'Schema类型','properties'=>'属性定义','required'=>'必填字段名','additionalProperties'=>'允许额外属性','items'=>'列表元素结构','maxLength'=>'文本长度上限','maxItems'=>'列表长度上限','variables'=>'样例变量','output'=>'预期输出','required_facts'=>'必须保留的事实','text'=>'样章正文','scores'=>'人工标注分数','expected_pass'=>'预期通过','description'=>'维度说明','threshold'=>'最低分'];
@endphp
@php($expanded=!str_contains($path,'.children.') || !in_array($node['type'],['object','array'],true) || ($expandedJson[$path]??false))
<div class="nf-json-container">
@if(str_contains($path,'.children.') && in_array($node['type'],['object','array'],true))
    <x-filament::button size="sm" color="gray" wire:click="toggleJsonNode('{{ $path }}')">{{ $expanded?'收起':'展开' }} {{ $label }} · {{ \App\Filament\Support\JsonFields::TYPES[$node['type']] }}（{{ count($node['children']) }}项）</x-filament::button>
@endif
@if($expanded)
<fieldset class="nf-json-node" wire:key="{{ $id }}">
    <legend>{{ $label }}</legend>
    @error($path)<p role="alert">{{ $message }}</p>@enderror
    <x-nf.form-grid>
        <x-nf.select :id="$id.'-type'" label="值的类型" :model="\App\Filament\Support\JsonFields::binding($path.'.type')" :value="$node['type']" :options="\App\Filament\Support\JsonFields::TYPES" />
        @if($node['type'] === 'boolean')
            <x-nf.select :id="$id.'-value'" label="布尔值" :model="\App\Filament\Support\JsonFields::binding($path.'.value')" :value="$node['value']" :options="['0'=>'否（false）','1'=>'是（true）']" />
        @elseif($node['type'] === 'string' && $label === 'Schema类型')
            <x-nf.select :id="$id.'-value'" label="Schema 类型" :model="\App\Filament\Support\JsonFields::binding($path.'.value')" :value="$node['value']" :options="['object'=>'对象 object','array'=>'列表 array','string'=>'文本 string','integer'=>'整数 integer','boolean'=>'布尔 boolean','null'=>'空值 null']" />
        @elseif(in_array($node['type'], ['integer','number'], true))
            <x-nf.input :id="$id.'-value'" label="数值" wire:model="{{ \App\Filament\Support\JsonFields::binding($path.'.value') }}" :error="$errors->first($path.'.value')" />
        @elseif($node['type'] === 'string')
            <x-nf.field :id="$id.'-value'" label="文本值" :error="$errors->first($path.'.value')">
                <textarea id="{{ $id }}-value" rows="2" wire:model="{{ \App\Filament\Support\JsonFields::binding($path.'.value') }}" aria-describedby="{{ $id }}-value-error"></textarea>
            </x-nf.field>
        @elseif($node['type'] === 'null')<p>明确的 JSON 空值，不等于空文本或空列表。</p>@endif
    </x-nf.form-grid>
    @error($path.'.type')<p role="alert">{{ $message }}</p>@enderror
    @if(in_array($node['type'], ['object','array'], true))
        @forelse($node['children'] as $i=>$row)
            <div class="nf-json-row" wire:key="{{ $id }}-row-{{ $i }}">
                <div class="nf-json-row-actions">
                    @if($node['type'] === 'object')<x-nf.input :id="$id.'-key-'.$i" label="属性名" wire:model="{{ \App\Filament\Support\JsonFields::binding($path.'.children.'.$i.'.key') }}" :error="$errors->first($path.'.children.'.$i.'.key')" />
                    @else<strong>第 {{ $i+1 }} 项</strong>@endif
                    <x-filament::button size="sm" color="gray" wire:click="changeJsonRow('{{ $path }}', {{ $i }}, 'up')" :disabled="$i === 0">上移</x-filament::button>
                    <x-filament::button size="sm" color="gray" wire:click="changeJsonRow('{{ $path }}', {{ $i }}, 'down')" :disabled="$i === count($node['children'])-1">下移</x-filament::button>
                    <x-filament::button size="sm" color="danger" wire:click="changeJsonRow('{{ $path }}', {{ $i }}, 'remove')">移除此字段</x-filament::button>
                </div>
                @include('filament.json-field', ['node'=>$row['node'],'path'=>$path.'.children.'.$i.'.node','label'=>$node['type']==='array'?'列表项 '.($i+1):($labels[$row['key']] ?? ($row['key'] ?: '新属性'))])
            </div>
        @empty<p class="nf-json-empty">{{ $node['type']==='array'?'空列表（保留为空列表）':'空对象（原服务暂不支持保存，内容保留供导出）' }}</p>@endforelse
        <x-filament::button size="sm" color="gray" wire:click="addJsonField('{{ $path }}')">{{ $node['type']==='array'?'添加列表项':'添加属性' }}</x-filament::button>
    @endif
</fieldset>
@endif
</div>
