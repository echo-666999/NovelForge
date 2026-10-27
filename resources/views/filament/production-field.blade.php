@php
    // 契约属性不要求作者重命名；列表顺序与未知字段保留，深节点按需展开。
    $container=in_array($node['type'],['object','array'],true);
    $root=!str_contains($path,'.children.');
    $open=$root||!$container||($expandedJson[$path]??($node['type']==='object' && substr_count($path,'.children.')<=2));
    $id='nf-prod-'.substr(hash('sha256',$path),0,20);
@endphp
<div class="nf-production-node" data-node-type="{{ $node['type'] }}" data-field="{{ $fieldKey }}" wire:key="{{ $id }}">
@if(!$root && $container)<x-filament::button color="gray" size="sm" wire:click="toggleProductionNode('{{ $path }}')">{{ $open?'收起':'展开' }} {{ $label }}（{{ count($node['children']) }}项）</x-filament::button>@endif
@if($open)
@if(!isset($schema['type']) || $node['type']!==$schema['type'] && !($node['type']==='null'&&($schema['nullable']??false)))
<p class="nf-field-help">保留原字段与类型；如与契约不符，请明确修正，不会自动改写。</p>
@include('filament.json-field',['node'=>$node,'path'=>$path,'label'=>$label])
@else
@if($schema['nullable']??false)<div class="nf-story-toolbar"><x-filament::button color="gray" size="sm" wire:click="setProductionUsage('{{ $path }}',false)" wire:confirm="明确清除这些用量字段并标为未知？">明确用量未知</x-filament::button><x-filament::button color="gray" size="sm" wire:click="setProductionUsage('{{ $path }}',true)" wire:confirm="重建用量字段，当前用量将清空？">录入已知Token用量</x-filament::button></div>@endif
@if($container)
<fieldset><legend @class(['sr-only'=>!$root])>{{ $label }}</legend>
    @if(method_exists($this,'locateReviewQuote') && isset($schema['properties']['quote'],$schema['properties']['start'],$schema['properties']['end']))<x-filament::button color="gray" size="sm" wire:click="locateReviewQuote('{{ $path }}')">按唯一引文定位正文码点</x-filament::button>@endif
    @error($path)<p role="alert">{{ $message }}</p>@enderror
    <div class="nf-production-fields">
    @forelse($node['children'] as $i=>$row)
        @php($childSchema=$node['type']==='array'?($schema['items']??[]):($schema['properties'][$row['key']]??[]))
        <div @class(['nf-production-row','nf-production-row-wide'=>in_array($row['node']['type'],['object','array'],true)])>
            @if($node['type']==='array')
            <div class="nf-story-toolbar"><strong>第{{ $i+1 }}项</strong>
                <x-filament::button color="gray" size="sm" wire:click="changeJsonRow('{{ $path }}',{{ $i }},'up')" :disabled="$i===0">上移</x-filament::button><x-filament::button color="gray" size="sm" wire:click="changeJsonRow('{{ $path }}',{{ $i }},'down')" :disabled="$i===count($node['children'])-1">下移</x-filament::button><x-filament::button color="gray" size="sm" wire:click="changeJsonRow('{{ $path }}',{{ $i }},'remove')">移除此项</x-filament::button>
            </div>
            @elseif(!in_array($row['key'],$schema['required']??[],true))<div class="nf-story-toolbar"><x-filament::button color="gray" size="sm" wire:click="changeJsonRow('{{ $path }}',{{ $i }},'remove')">移除{{ \App\Filament\Support\ProductionSchemas::label($row['key']) }}</x-filament::button></div>@endif
            @include('filament.production-field',['node'=>$row['node'],'path'=>$path.'.children.'.$i.'.node','schema'=>$childSchema,'label'=>$node['type']==='array'?'列表项'.($i+1):\App\Filament\Support\ProductionSchemas::label($row['key']),'fieldKey'=>$node['type']==='array'?$fieldKey:$row['key']])
        </div>
    @empty<p>暂无条目；不会自动填入事实、评分或凭证。</p>@endforelse
    </div>
    <div class="nf-story-toolbar">
    @if($node['type']==='array')<x-filament::button color="gray" size="sm" wire:click="addProductionField('{{ $path }}')">添加{{ $label }}条目</x-filament::button>
    @else @foreach($schema['properties']??[] as $key=>$unused)@if(!in_array($key,array_column($node['children'],'key'),true))<x-filament::button color="gray" size="sm" wire:click="addProductionField('{{ $path }}','{{ $key }}')">添加{{ \App\Filament\Support\ProductionSchemas::label($key) }}</x-filament::button>@endif @endforeach @endif
    </div>
</fieldset>
@elseif($node['type']==='boolean')
    <x-nf.select :id="$id" :label="$label" :model="\App\Filament\Support\JsonFields::binding($path.'.value')" :value="$node['value']" :options="['0'=>'否','1'=>'是']" />
@elseif(($references=$this->productionFieldChoices($path,$fieldKey))!==null)
    <x-nf.select :id="$id" :label="$label" :model="\App\Filament\Support\JsonFields::binding($path.'.value')" :value="$node['value']" :options="[''=>'请选择实际记录']+$references" />
@elseif($fieldKey==='type' && class_basename($this)==='PlanningCandidates')
    <x-nf.select :id="$id" :label="$label" :model="\App\Filament\Support\JsonFields::binding($path.'.value')" :value="$node['value']" :options="[''=>'请选择层级','volume'=>'卷','story_arc'=>'故事弧','chapter'=>'章节','scene'=>'场景']" />
@elseif($fieldKey==='severity')
    <x-nf.select :id="$id" :label="$label" :model="\App\Filament\Support\JsonFields::binding($path.'.value')" :value="$node['value']" :options="[''=>'请选择严重度','blocking'=>'阻塞','warning'=>'警告','info'=>'提示']" />
@elseif(isset($schema['enum']))
    <x-nf.select :id="$id" :label="$label" :model="\App\Filament\Support\JsonFields::binding($path.'.value')" :value="$node['value']" :options="array_combine($schema['enum'],$schema['enum'])" />
@elseif($node['type']==='null')<p>明确空值（未知），不是零或空文本。</p>
@else
    <x-nf.field :id="$id" :label="$label" :error="$errors->first($path.'.value')">
        @if(in_array($node['type'],['integer','number'],true))<input id="{{ $id }}" inputmode="numeric" wire:model="{{ \App\Filament\Support\JsonFields::binding($path.'.value') }}" />
        @else
        @php($long=in_array($fieldKey,['quote','source_quote','reason','message','suggested_fix','replacement','summary','content','description','evidence_ref'],true)||(($schema['maxLength']??0)>=1000&&!in_array($fieldKey,['key','title','name','field'],true)))
        @if($long)<textarea id="{{ $id }}" rows="2" wire:model="{{ \App\Filament\Support\JsonFields::binding($path.'.value') }}"></textarea>
        @else<input id="{{ $id }}" @if($fieldKey==='key') wire:model.live.debounce.400ms="{{ \App\Filament\Support\JsonFields::binding($path.'.value') }}" @else wire:model="{{ \App\Filament\Support\JsonFields::binding($path.'.value') }}" @endif />@endif
        @endif
    </x-nf.field>
@endif
@endif
@endif
</div>
