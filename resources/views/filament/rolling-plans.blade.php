<x-filament-panels::page>
@include('filament.novel-navigation')
@php($selected=$this->selected())
<x-filament::section heading="滚动细化与计划门禁">
<p>首窗为前十章，后续每窗五至十章。未来骨架不等于可生成计划；正文生成、正式历史及伏笔生命周期尚未接入。</p>
<div class="flex flex-wrap gap-3">{{ $this->nodeAction }} {{ $this->transformAction }} {{ $this->savePlanAction }} {{ $this->validatePlanAction }} {{ $this->freezePlanAction }}</div>
<label>当前查看 <select wire:model.live="selectedId"><option value="">可编辑工作草稿</option>@foreach($this->planOptions() as $id=>$label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select></label>
<a href="{{ \App\Filament\Resources\NovelResource::getUrl('structure',['record'=>$novelId]) }}">卷弧树、主支线与结构版本比较</a>
@if($selected)<p>计划 v{{ $selected['version'] }} · {{ $this::STATES[$selected['display_status']] }} · 第{{ $selected['start_chapter'] }}—{{ $selected['end_chapter'] }}章 · 蓝图 #{{ $selected['blueprint_version_id'] }} · 结构 #{{ $selected['outline_version_id'] }}</p>
<p>该视图只读。修改请切换工作草稿并另存新版本，旧检查和失败记录保留。</p>
@foreach($selected['report']['blockers'] as $b)<p>{{ $b['code'] }} · {{ $b['path'] }} · {{ $b['message'] }}</p>@endforeach
@if($selected['report']['passed'])<p>计划门禁通过；尚未开放模型生成。</p>@endif
<details><summary>覆盖证据与来源</summary>@foreach($selected['content']['coverages'] as $c)<p>要求 #{{ $c['requirement_version_id'] }} → 计划节点 #{{ $c['plan_node_id'] }} · {{ $c['field'] }} [{{ $c['start_offset'] }},{{ $c['end_offset'] }})：{{ $c['quote'] }}</p>@endforeach</details>
@endif
</x-filament::section>
<x-filament::section heading="章与场景（按全书章序 / 同章场景序）">
<div class="flex flex-wrap gap-3">
<label>层级 <select wire:model.live="type"><option value="">全部</option>@foreach($this::LABELS as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
<label>主父级 <select wire:model.live="parent"><option value="">全部</option>@foreach($this->state()['nodes'] as $r)<option value="{{ $r['type'] }}:{{ $r['id'] }}">{{ $this::LABELS[$r['type']] }} {{ $r['title'] }}</option>@endforeach</select></label>
<label>成熟度 <select wire:model.live="status"><option value="">全部</option><option value="skeleton">骨架</option><option value="detailed">已填细节</option></select></label>
<label>序号从 <input type="number" wire:model.live="from" /></label><label>至 <input type="number" wire:model.live="until" /></label>
<label><input type="checkbox" wire:model.live="missing" /> 缺少 POV 或预算</label>
</div>
@php($rows=$this->rows())
<div class="overflow-x-auto"><table class="w-full text-left"><thead><tr><th>节点 / 父级</th><th>目标 / 必填信息</th><th>预算（下限 / 目标 / 上限）</th><th>成熟度 / 操作</th></tr></thead><tbody>
@foreach($rows as $r)<tr wire:key="plan-{{ $selectedId }}-{{ $r['type'] }}-{{ $r['id'] }}"><td>{{ $this::LABELS[$r['type']] }} #{{ $r['id'] }} · {{ $r['sequence'] }} {{ $r['title'] }}<p>父级 #{{ $r['parent_id']??'本书' }}</p></td><td>{{ $r['objective'] }}<details><summary>计划细节与依赖</summary>@foreach($r['details'] as $k=>$v)<p>{{ $this::DETAILS[$k]??$k }}：{{ $v }}</p>@endforeach</details></td><td>{{ $r['details']['min_words']??'缺失' }} / {{ $r['details']['target_words']??'缺失' }} / {{ $r['details']['max_words']??'缺失' }}</td><td>{{ $r['status']==='skeleton'?'骨架（不可生成）':'细节已填写（以门禁为准）' }} @if($this->editable())<x-filament::button wire:click="mountAction('node',{node_type:'{{ $r['type'] }}',node:'{{ $r['id'] }}'})">编辑</x-filament::button>@endif</td></tr>@endforeach
</tbody></table></div>{{ $rows->links() }}
</x-filament::section>
<x-filament::section heading="不可变计划历史">
<label>计划状态 <select wire:model.live="planState"><option value="">全部</option>@foreach($this::STATES as $k=>$label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</select></label>
@php($history=$this->plans())
@foreach($history['items'] as $p)<p><button wire:click="$set('selectedId','{{ $p['id'] }}')">v{{ $p['version'] }} · 第{{ $p['start_chapter'] }}—{{ $p['end_chapter'] }}章</button> · {{ $this::STATES[$p['display_status']] }} · {{ $p['reason'] }} · {{ $p['created_at'] }}</p>@endforeach
@if($history['next_before'])<x-filament::button wire:click="$set('before','{{ $history['next_before'] }}')">较早版本</x-filament::button>@endif
@if($before)<x-filament::button wire:click="$set('before','')">最新版本</x-filament::button>@endif
</x-filament::section>
</x-filament-panels::page>
