@php
    // 固定对象按契约分组，只有当前选中的列表行渲染输入，避免大响应堆积隐藏控件。
    $schema=$this->responseSchema($path);
    $labels=\App\Filament\Support\ExtractionUi::LABELS;
@endphp
@error($path)<p role="alert">{{ $message }}</p>@enderror
@if($node['type']==='object' && isset($schema['properties']))
    <div class="nf-extraction-object">
    @php($positions=array_flip(array_keys($schema['properties'])))
    @php($ordered=collect($node['children'])->sortBy(fn($f)=>$positions[$f['key']]??PHP_INT_MAX))
    @foreach($ordered as $i=>$field)
        @php($childPath=$path.'.children.'.$i.'.node')
        @php($key=$field['key'])
        @if(isset($schema['properties'][$key]))
            @if(in_array($field['node']['type'],['array','object'],true) || $key==='value')
                <fieldset class="nf-json-node"><legend>{{ $labels[$key]??$key }}</legend>
                @if($key==='value')
                    @include('filament.json-field',['path'=>$childPath,'node'=>$field['node'],'label'=>'事实值（保留类型）'])
                @else
                    @include('filament.extraction-fields',['path'=>$childPath,'node'=>$field['node']])
                @endif
                </fieldset>
            @else
                @php($opts=\App\Filament\Support\ExtractionUi::choices($key,$path,$selections,isset($schema['properties']['clock'])))
                @if($key==='relation_type' && isset($schema['properties']['predecessor_ref']))@php($opts=['before'=>'先于','depends_on'=>'依赖','contains'=>'包含','simultaneous'=>'同时'])@endif
                @php($binding=\App\Filament\Support\JsonFields::binding($childPath.'.value'))
                <div @class(['nf-extraction-field','nf-full-row'=>in_array($key,['quote','reason','state'],true)])>
                @if($opts!==null)
                    @php($opts=[''=>'请选择']+$opts)
                    @if($field['node']['value']!=='' && !array_key_exists((string)$field['node']['value'],$opts))
                        @php($opts[(string)$field['node']['value']]='当前值未在可选范围：'.$field['node']['value'])
                    @endif
                    <x-nf.select :id="'ef-'.md5($childPath)" :label="$labels[$key]??$key" :model="$binding" :value="$field['node']['value']" :options="$opts" />
                @elseif(in_array($key,['quote','reason','state'],true))
                    <x-nf.field :id="'ef-'.md5($childPath)" :label="$labels[$key]??$key"><textarea id="{{ 'ef-'.md5($childPath) }}" rows="3" wire:model.blur="{{ $binding }}">{{ $field['node']['value'] }}</textarea></x-nf.field>
                @else
                    <x-nf.input :id="'ef-'.md5($childPath)" :label="$labels[$key]??$key" :type="$field['node']['type']==='integer'?'number':'text'" wire:model.blur="{{ $binding }}" :value="$field['node']['value']" :readonly="$key==='source_hash'" :error="$errors->first($childPath.'.value')" />
                @endif
                @error($childPath)<p role="alert">{{ $message }}</p>@enderror
                @error($childPath.'.value')<p role="alert">{{ $message }}</p>@enderror
                @if(!in_array($key,$schema['required']??[],true))<x-filament::button size="sm" color="gray" wire:click="removeResponseField('{{ $path }}',{{ $i }})">移除{{ $labels[$key]??$key }}</x-filament::button>@endif
                </div>
            @endif
        @else
            <x-nf.state state="notice" title="未支持的扩展字段，仍保留原值">{{ $key }}</x-nf.state>
            @include('filament.value-table',['value'=>\App\Filament\Support\JsonFields::value($field['node']),'label'=>'扩展字段只读'])
            <x-filament::button size="sm" color="danger" wire:click="removeResponseField('{{ $path }}',{{ $i }})">明确移除此扩展字段</x-filament::button>
        @endif
    @endforeach
    <div class="nf-config-actions nf-full-row">
    @foreach(array_diff(array_keys($schema['properties']),array_column($node['children'],'key')) as $missing)
        <x-filament::button size="sm" color="gray" wire:click="addResponseField('{{ $path }}','{{ $missing }}')">添加{{ $labels[$missing]??$missing }}</x-filament::button>
    @endforeach
    </div>
    </div>
@elseif($node['type']==='array' && isset($schema['items']))
    <div class="nf-config-actions">
        <span>{{ count($node['children']) }} 项 · 逐行编辑，顺序会保留</span>
        <x-filament::button size="sm" color="gray" wire:click="addResponseRow('{{ $path }}')" :disabled="count($node['children'])>=($schema['maxItems']??200)">添加条目</x-filament::button>
    </div>
    @if(!$node['children'])<p>暂无条目；按实际正文添加，不自动猜测事实。</p>@endif
    @foreach($node['children'] as $i=>$row)
        <div class="nf-json-node" id="er-{{ md5($path) }}-{{ $i }}" tabindex="-1" wire:key="row-{{ md5($path) }}-{{ $i }}">
            <p>{{ $this->responseRowTitle($row['node']) }}</p>
            <div class="nf-config-actions">
                <x-filament::button size="sm" color="gray" wire:click="$set('activeRows.r{{ md5($path) }}',{{ $i }})">编辑第{{ $i+1 }}项</x-filament::button>
                <x-filament::button size="sm" color="gray" wire:click="changeJsonRow('{{ $path }}',{{ $i }},'up')" :disabled="$i===0">上移</x-filament::button>
                <x-filament::button size="sm" color="gray" wire:click="changeJsonRow('{{ $path }}',{{ $i }},'down')" :disabled="$i===count($node['children'])-1">下移</x-filament::button>
                <x-filament::button size="sm" color="danger" wire:click="changeJsonRow('{{ $path }}',{{ $i }},'remove')">删除此项</x-filament::button>
            </div>
            @if((int)($activeRows['r'.md5($path)]??0)===$i)
                @include('filament.extraction-fields',['path'=>$path.'.children.'.$i.'.node','node'=>$row['node']])
            @endif
        </div>
    @endforeach
@else
    @include('filament.json-field',['path'=>$path,'node'=>$node,'label'=>'字段类型和值'])
@endif
