@php
    // 语义化Schema主视图直接编辑同一类型树；扩展关键词仍在下方高级字段区保留。
    $keys=array_column($node['children']??[], 'key');
    $lookup=fn($key)=>array_search($key,$keys,true);
    $typeIndex=$lookup('type');$schemaType=$typeIndex===false?'':$node['children'][$typeIndex]['node']['value'];
    $propsIndex=$lookup('properties');$requiredIndex=$lookup('required');$itemsIndex=$lookup('items');
    $requiredNames=$requiredIndex===false?[]:array_column($node['children'][$requiredIndex]['node']['children'],'node');
    $requiredNames=array_column($requiredNames,'value');
    $allowed=match($schemaType){'object'=>['type','properties','required','additionalProperties'],'array'=>['type','items','maxItems'],'string'=>['type','maxLength'],default=>['type']};
    $unsupported=array_diff($keys,$allowed);
    $id='schema-'.str_replace('.','-',$path);
@endphp
<fieldset class="nf-json-node">
    <legend>{{ $label }}</legend>
    @error($path)<p role="alert">{{ $message }}</p>@enderror
    @if($typeIndex!==false)
        <x-nf.select :id="$id.'-type'" label="Schema 类型" :model="\App\Filament\Support\JsonFields::binding($path.'.children.'.$typeIndex.'.node.value')" :value="$schemaType" :options="['object'=>'对象','array'=>'列表','string'=>'文本','integer'=>'整数','boolean'=>'布尔','null'=>'空值']" />
    @else<p role="alert">缺少type字段，请在高级字段区修正；保存会拒绝无效结构。</p>@endif
    @if($unsupported)<p role="alert">当前Schema类型不支持这些关键词：{{ implode('、',$unsupported) }}。原值保留，请在高级字段区明确修改或移除，保存前不会自动清理。</p>@endif
    @if($schemaType==='object')
        <p>属性逐行编辑；必填标记只修改此对象的 required 列表，额外属性按原契约禁止。</p>
        @if($propsIndex!==false)
            @foreach($node['children'][$propsIndex]['node']['children'] as $i=>$property)
                <div class="nf-json-row">
                    <div class="nf-json-row-actions">
                        <x-nf.input :id="$id.'-name-'.$i" label="属性名" wire:model.live="{{ \App\Filament\Support\JsonFields::binding($path.'.children.'.$propsIndex.'.node.children.'.$i.'.key') }}" />
                        <x-filament::button size="sm" color="gray" wire:click="toggleSchemaRequired('{{ $path }}', {{ $i }})">{{ in_array($property['key'],$requiredNames,true)?'必填 ✓（切换为选填）':'选填（切换为必填）' }}</x-filament::button>
                        @if($path==='trees.variable_schema')<x-filament::button size="sm" color="gray" wire:click="insertPromptVariable({{ $i }})">插入正文变量</x-filament::button>@endif
                        <x-filament::button size="sm" color="danger" wire:click="removeSchemaProperty('{{ $path }}', {{ $i }})">移除属性及必填标记</x-filament::button>
                    </div>
                    <details class="nf-schema-property" open>
                        <summary>{{ $property['key']?:'未命名属性' }} 的结构</summary>
                        @include('filament.schema-fields',['node'=>$property['node'],'path'=>$path.'.children.'.$propsIndex.'.node.children.'.$i.'.node','label'=>'属性结构'])
                    </details>
                </div>
            @endforeach
        @endif
        <x-filament::button color="gray" size="sm" wire:click="addSchemaProperty('{{ $path }}')">添加属性</x-filament::button>
        @if($propsIndex!==false)
            @php($defined=array_column($node['children'][$propsIndex]['node']['children'],'key'))
            @foreach($requiredNames as $i=>$name)@if(!in_array($name,$defined,true))
                <p role="alert">必填名“{{ $name }}”尚未对应属性；请在高级字段区移除旧名称，或恢复属性名。</p>
            @endif
            @endforeach
        @endif
    @elseif($schemaType==='array')
        @if($itemsIndex!==false)@include('filament.schema-fields',['node'=>$node['children'][$itemsIndex]['node'],'path'=>$path.'.children.'.$itemsIndex.'.node','label'=>'列表元素结构'])
        @else<p>请重新选择列表类型以补充元素结构，或在高级字段区添加 items。</p>@endif
    @endif
    @foreach(['maxLength'=>'文本长度上限','maxItems'=>'列表项数上限'] as $keyword=>$text)
        @php($index=$lookup($keyword))
        @if($index!==false)<x-nf.input :id="$id.'-'.$keyword" :label="$text" wire:model="{{ \App\Filament\Support\JsonFields::binding($path.'.children.'.$index.'.node.value') }}" :error="$errors->first($path.'.children.'.$index.'.node.value')" />
        @elseif(($schemaType==='string' && $keyword==='maxLength')||($schemaType==='array' && $keyword==='maxItems'))
            <x-filament::button size="sm" color="gray" wire:click="addSchemaLimit('{{ $path }}','{{ $keyword }}')">设置{{ $text }}</x-filament::button>
        @endif
    @endforeach
</fieldset>
