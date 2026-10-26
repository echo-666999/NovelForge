{{-- 目录按真实主归属逐层展开；使用普通列表和按钮，不伪造未实现的ARIA树键盘协议。 --}}
@php($next=['volume'=>'story_arc','story_arc'=>'chapter','chapter'=>'scene'])
<ul class="nf-story-tree">
@foreach($branches as $branch)
    @php($key=$branch['type'].'-'.$branch['id'])
    @php($children=isset($next[$branch['type']])?array_values(array_filter($treeNodes,fn($n)=>$n['type']===$next[$branch['type']]&&($n['parent_id']??null)===$branch['id'])):[])
    <li>
        <div class="nf-story-toolbar">
            @if($children)<x-filament::button color="gray" size="sm" wire:click="toggleStoryBranch('{{ $branch['type'] }}','{{ $branch['id'] }}')" aria-expanded="{{ ($treeOpen[$key]??false)?'true':'false' }}" :aria-controls="($treeOpen[$key]??false)?'branch-'.$key:null">{{ ($treeOpen[$key]??false)?'收起':'展开' }}{{ $this::LABELS[$branch['type']] }}</x-filament::button>@endif
            <x-filament::button color="gray" size="sm" wire:click="focusStoryNode('{{ $branch['type'] }}','{{ $branch['id'] }}')">{{ $this::LABELS[$branch['type']] }}{{ $branch['sequence'] }} · {{ $branch['title'] }}</x-filament::button>
            <span>{{ count($children) }} 个直接子节点{{ $this->nodeFrozen($branch['type'],$branch['id'])?' · 已冻结':'' }}</span>
        </div>
        @if($children && ($treeOpen[$key]??false))<div id="branch-{{ $key }}">@include('filament.story-tree',['branches'=>$children,'treeNodes'=>$treeNodes])</div>@endif
    </li>
@endforeach
</ul>
