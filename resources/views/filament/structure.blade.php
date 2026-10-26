<x-filament-panels::page class="nf-story-screen">
@include('filament.novel-navigation')
<div wire:loading role="status">正在读取或保存本书资料，请稍候…</div>
@if($errors->any())<x-nf.state state="error" title="请核对字段，输入仍保留"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-nf.state>@endif
@php($state=$this->state())
<x-nf.section heading="当前规划与版本">
<h2>{{ $state['novel']['title'] }}</h2><p>Novel → Volume → Story Arc → Chapter → Scene。正式基线以上方小说上下文为准。</p>
<p>结构快照本身不等同于生成许可；要求覆盖、字数预算与冻结结果以章节场景计划门禁为准。</p>
<div class="nf-story-toolbar">{{ $this->nodeAction }} {{ $this->threadAction }} {{ $this->snapshotAction }}</div>
<x-nf.story-select id="ui06-structure-1-{{ md5('versionId') }}" label="查看版本" model="versionId" :value="data_get($__livewire,'versionId')" ><option value="">当前工作草稿</option>@foreach($this->versions() as $v)<option value="{{ $v['id'] }}">结构 v{{ $v['version'] }} · {{ $v['reason'] }}</option>@endforeach</x-nf.story-select>
@if($versionId)<p>当前查看不可变历史版本；切回工作草稿修改并保存新版本，历史内容不会覆盖。</p>@endif
<x-nf.story-select id="ui06-structure-2-{{ md5('compareId') }}" label="与历史比较" model="compareId" :value="data_get($__livewire,'compareId')" ><option value="">不比较</option>@foreach($this->versions() as $v)<option value="{{ $v['id'] }}">结构 v{{ $v['version'] }}</option>@endforeach</x-nf.story-select>
</x-nf.section>
<x-nf.section heading="五层结构目录">
@php($treeNodes=$state['nodes'])
@include('filament.story-tree',['branches'=>array_values(array_filter($treeNodes,fn($n)=>$n['type']==='volume')),'treeNodes'=>$treeNodes])
@if(!$treeNodes)<x-nf.state state="empty" title="尚无五层结构">先建立卷，再逐级承接故事弧、章节和场景。</x-nf.state>@endif
</x-nf.section>
<x-nf.section heading="结构节点">
<div class="nf-story-toolbar">
<x-nf.story-select id="ui06-structure-3-{{ md5('type') }}" label="层级" model="type" :value="data_get($__livewire,'type')" ><option value="">全部</option>@foreach($this::LABELS as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</x-nf.story-select>
<x-nf.story-select id="ui06-structure-4-{{ md5('parent') }}" label="主父级" model="parent" :value="data_get($__livewire,'parent')" ><option value="">全部</option>@foreach($state['nodes'] as $row)@if($type && ($this::LABELS[$row['type']]??'') && (\App\Services\StructureService::PARENTS[$type]??null)===$row['type'])<option value="{{ $row['type'] }}:{{ $row['id'] }}">{{ $row['title'] }}</option>@endif @endforeach</x-nf.story-select>
<x-nf.story-select id="ui06-structure-5-{{ md5('thread') }}" label="线索" model="thread" :value="data_get($__livewire,'thread')" ><option value="">全部</option>@foreach($state['threads'] as $t)<option value="{{ $t['id'] }}">{{ $t['title'] }}</option>@endforeach</x-nf.story-select>
<x-nf.story-select id="ui06-structure-6-{{ md5('status') }}" label="成熟度" model="status" :value="data_get($__livewire,'status')" ><option value="">全部</option><option value="skeleton">骨架</option><option value="detailed">已填写细节</option></x-nf.story-select>
<x-nf.field id="ui06-structure-7-{{ md5('from') }}" label="序号从" :error="$errors->first('from')"><input id="ui06-structure-7-{{ md5('from') }}" type="number" min="1" wire:model.live="from" /></x-nf.field><x-nf.field id="ui06-structure-8-{{ md5('until') }}" label="至" :error="$errors->first('until')"><input id="ui06-structure-8-{{ md5('until') }}" type="number" min="1" wire:model.live="until" /></x-nf.field>
<label class="nf-story-check"><input type="checkbox" wire:model.live="missing" />缺少视角或目标字数</label>
<x-nf.story-select id="ui06-structure-9-{{ md5('perPage') }}" label="每页" model="perPage" :value="data_get($__livewire,'perPage')" ><option>10</option><option>25</option><option>50</option><option>100</option></x-nf.story-select>
</div>
@php($rows=$this->rows())
<x-nf.table label="故事资料列表"><table class="w-full text-left"><thead><tr><th>层级 / 顺序 / 标题</th><th>主父级 / 范围</th><th>目标 / 细节</th><th>成熟度 / 来源</th><th>操作</th></tr></thead><tbody>
@forelse($rows as $row)
<tr wire:key="{{ $versionId }}-{{ $row['type'] }}-{{ $row['id'] }}"><td>{{ $this::LABELS[$row['type']] }} {{ $row['sequence'] }} · {{ $row['title'] }}</td>
<td>@if(isset($row['parent_id'])){{ collect($state['nodes'])->first(fn($p)=>$p['type']===(\App\Services\StructureService::PARENTS[$row['type']]??'')&&$p['id']===$row['parent_id'])['title']??'父级缺失' }}@else 本书 @endif
@if(isset($row['start_chapter']))<p>第{{ $row['start_chapter'] }}—{{ $row['end_chapter'] }}章</p>@endif</td>
<td><p>{{ $row['objective'] }}</p><details wire:ignore.self><summary>摘要与计划细节</summary><p>{{ $row['summary'] }}</p>@foreach($row['details'] as $key=>$value)<p>{{ $this::DETAILS[$key]??$key }}：{{ $value }}</p>@endforeach @foreach($row['threads']??[] as $t)<p>线索：{{ $t['title'] }} · {{ $t['objective'] }}</p>@endforeach</details></td>
<td>{{ $row['status']==='skeleton'?'骨架':'已填写细节' }}<p>原始版本 #{{ $row['source_version_id'] }} · 修改{{ $row['row_version'] }}次</p><p>覆盖证据见章节场景计划</p></td>
<td>@if($this->editable()&&!$this->nodeFrozen($row['type'],$row['id']))<x-filament::button size="sm" wire:click="mountAction('node',{ node_type:'{{ $row['type'] }}',node:'{{ $row['id'] }}' })">编辑 / 调整顺序</x-filament::button>@else 只读 @endif</td></tr>
@empty<tr><td colspan="5">尚无匹配的结构节点。请从卷开始，依次建立故事弧、章、场景。</td></tr>@endforelse
</tbody></table></x-nf.table>{{ $rows->links() }}
</x-nf.section>
<x-nf.section heading="全书线索关联">
<p>线索可由不同卷中的故事弧承接，不改变故事弧的唯一主归属。主支线批准与终局契约见蓝图页面。</p>
@foreach($state['threads'] as $t)<div>{{ $t['title'] }} · {{ $t['objective'] }} @if($this->editable())<x-filament::button size="sm" wire:click="mountAction('thread',{thread:'{{ $t['id'] }}'})">修正线索说明</x-filament::button>@endif</div>@endforeach
</x-nf.section>
@if($compareId)<x-nf.section heading="与所选历史版本的差异">
@forelse($this->differences() as $diff)<details wire:ignore.self><summary>{{ $diff['key'] }} · {{ $diff['after']['title']??$diff['before']['title'] }}</summary><p>历史版本</p>@include('filament.value-table',['value'=>$diff['before'],'label'=>'逐字段只读资料'])<p>当前选择</p>@include('filament.value-table',['value'=>$diff['after'],'label'=>'逐字段只读资料'])</details>@empty<p>节点内容没有差异。</p>@endforelse
</x-nf.section>@endif
</x-filament-panels::page>
