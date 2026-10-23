<x-filament-panels::page>
@php($state=$this->state())
<x-filament::section>
<h2>{{ $state['novel']['title'] }}</h2><p>Novel → Volume → Story Arc → Chapter → Scene。正式基线尚未建立。</p>
<p>结构版本仅保存可追溯快照。要求覆盖、字数预算与冻结门禁见章节场景计划页面；当前不可用于生成。</p>
<div class="flex flex-wrap gap-3">{{ $this->nodeAction }} {{ $this->threadAction }} {{ $this->snapshotAction }}</div>
<label>查看版本 <select wire:model.live="versionId"><option value="">当前工作草稿</option>@foreach($this->versions() as $v)<option value="{{ $v['id'] }}">结构 v{{ $v['version'] }} · {{ $v['reason'] }}</option>@endforeach</select></label>
@if($versionId)<p>当前查看不可变历史版本；切回工作草稿修改并保存新版本，历史内容不会覆盖。</p>@endif
<label>与历史比较 <select wire:model.live="compareId"><option value="">不比较</option>@foreach($this->versions() as $v)<option value="{{ $v['id'] }}">结构 v{{ $v['version'] }}</option>@endforeach</select></label>
</x-filament::section>
<x-filament::section heading="结构节点">
<div class="flex flex-wrap gap-3">
<label>层级 <select wire:model.live="type"><option value="">全部</option>@foreach($this::LABELS as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
<label>主父级 <select wire:model.live="parent"><option value="">全部</option>@foreach($state['nodes'] as $row)@if($type && ($this::LABELS[$row['type']]??'') && (\App\Services\StructureService::PARENTS[$type]??null)===$row['type'])<option value="{{ $row['type'] }}:{{ $row['id'] }}">{{ $row['title'] }}</option>@endif @endforeach</select></label>
<label>线索 <select wire:model.live="thread"><option value="">全部</option>@foreach($state['threads'] as $t)<option value="{{ $t['id'] }}">{{ $t['title'] }}</option>@endforeach</select></label>
<label>成熟度 <select wire:model.live="status"><option value="">全部</option><option value="skeleton">骨架</option><option value="detailed">已填写细节</option></select></label>
<label>序号从 <input type="number" min="1" wire:model.live="from" /></label><label>至 <input type="number" min="1" wire:model.live="until" /></label>
<label><input type="checkbox" wire:model.live="missing" />缺少视角或目标字数</label>
<label>每页 <select wire:model.live="perPage"><option>10</option><option>25</option><option>50</option><option>100</option></select></label>
</div>
@php($rows=$this->rows())
<div class="overflow-x-auto"><table class="w-full text-left"><thead><tr><th>层级 / 顺序 / 标题</th><th>主父级 / 范围</th><th>目标 / 细节</th><th>成熟度 / 来源</th><th>操作</th></tr></thead><tbody>
@forelse($rows as $row)
<tr wire:key="{{ $versionId }}-{{ $row['type'] }}-{{ $row['id'] }}"><td>{{ $this::LABELS[$row['type']] }} {{ $row['sequence'] }} · {{ $row['title'] }}</td>
<td>@if(isset($row['parent_id'])){{ collect($state['nodes'])->first(fn($p)=>$p['type']===(\App\Services\StructureService::PARENTS[$row['type']]??'')&&$p['id']===$row['parent_id'])['title']??'父级缺失' }}@else 本书 @endif
@if(isset($row['start_chapter']))<p>第{{ $row['start_chapter'] }}—{{ $row['end_chapter'] }}章</p>@endif</td>
<td><p>{{ $row['objective'] }}</p><details><summary>摘要与计划细节</summary><p>{{ $row['summary'] }}</p>@foreach($row['details'] as $key=>$value)<p>{{ $this::DETAILS[$key]??$key }}：{{ $value }}</p>@endforeach @foreach($row['threads']??[] as $t)<p>线索：{{ $t['title'] }} · {{ $t['objective'] }}</p>@endforeach</details></td>
<td>{{ $row['status']==='skeleton'?'骨架':'已填写细节' }}<p>原始版本 #{{ $row['source_version_id'] }} · 修改{{ $row['row_version'] }}次</p><p>覆盖证据见章节场景计划</p></td>
<td>@if($this->editable())<x-filament::button size="sm" wire:click="mountAction('node',{ node_type:'{{ $row['type'] }}',node:'{{ $row['id'] }}' })">编辑 / 调整顺序</x-filament::button>@else 只读 @endif</td></tr>
@empty<tr><td colspan="5">尚无匹配的结构节点。请从卷开始，依次建立故事弧、章、场景。</td></tr>@endforelse
</tbody></table></div>{{ $rows->links() }}
</x-filament::section>
<x-filament::section heading="全书线索关联">
<p>线索可由不同卷中的故事弧承接，不改变故事弧的唯一主归属。主支线批准与终局契约见蓝图页面。</p>
@foreach($state['threads'] as $t)<div>{{ $t['title'] }} · {{ $t['objective'] }} @if($this->editable())<x-filament::button size="sm" wire:click="mountAction('thread',{thread:'{{ $t['id'] }}'})">修正线索说明</x-filament::button>@endif</div>@endforeach
</x-filament::section>
@if($compareId)<x-filament::section heading="与所选历史版本的差异">
@forelse($this->differences() as $diff)<details><summary>{{ $diff['key'] }} · {{ $diff['after']['title']??$diff['before']['title'] }}</summary><p>历史版本</p><pre class="whitespace-pre-wrap">{{ json_encode($diff['before'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre><p>当前选择</p><pre class="whitespace-pre-wrap">{{ json_encode($diff['after'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details>@empty<p>节点内容没有差异。</p>@endforelse
</x-filament::section>@endif
</x-filament-panels::page>
