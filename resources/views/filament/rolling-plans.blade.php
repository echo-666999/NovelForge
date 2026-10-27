<x-filament-panels::page class="nf-story-screen">
@include('filament.novel-navigation')
<div wire:loading role="status">正在读取或保存本书资料，请稍候…</div>
@if($errors->any())<x-nf.state state="error" title="请核对字段，输入仍保留"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-nf.state>@endif
@php($selected=$this->selected())
<x-nf.section heading="滚动细化与计划门禁">
<p>首窗为前十章，后续每窗五至十章。未来骨架不等于可生成计划；正文与正式历史使用独立入口，本页只处理规划窗口。</p>
<div class="nf-story-toolbar">{{ $this->nodeAction }} {{ $this->transformAction }} {{ $this->savePlanAction }} {{ $this->validatePlanAction }} {{ $this->freezePlanAction }}</div>
<x-nf.story-select id="ui06-rolling-plans-1-{{ md5('selectedId') }}" label="当前查看" model="selectedId" :value="data_get($__livewire,'selectedId')" ><option value="">可编辑工作草稿</option>@foreach($this->planOptions() as $id=>$label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</x-nf.story-select>
<a href="{{ \App\Filament\Resources\NovelResource::getUrl('structure',['record'=>$novelId]) }}">卷弧树、主支线与结构版本比较</a>
@if($selected)<p>计划 v{{ $selected['version'] }} · {{ $this::STATES[$selected['display_status']] }} · 第{{ $selected['start_chapter'] }}—{{ $selected['end_chapter'] }}章 · 蓝图 #{{ $selected['blueprint_version_id'] }} · 结构 #{{ $selected['outline_version_id'] }}</p>
<p>该视图只读。修改请切换工作草稿并另存新版本，旧检查和失败记录保留。</p>
@foreach($selected['report']['blockers'] as $b)<p>{{ $b['code'] }} · {{ $b['path'] }} · {{ $b['message'] }}</p>@endforeach
@if($selected['report']['passed'])<p>该计划检查通过；实际生成资格仍须核对当前冻结依赖。</p>@endif
<details wire:ignore.self><summary>覆盖证据与来源</summary>@foreach($selected['content']['coverages'] as $c)<p>要求 #{{ $c['requirement_version_id'] }} → 计划节点 #{{ $c['plan_node_id'] }} · {{ $c['field'] }} [{{ $c['start_offset'] }},{{ $c['end_offset'] }})：{{ $c['quote'] }}</p>@endforeach</details>
@endif
</x-nf.section>
<x-nf.section heading="章与场景（按全书章序 / 同章场景序）">
<div class="nf-story-toolbar">
<x-nf.story-select id="ui06-rolling-plans-2-{{ md5('type') }}" label="层级" model="type" :value="data_get($__livewire,'type')" ><option value="">全部</option>@foreach($this::LABELS as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</x-nf.story-select>
<x-nf.story-select id="ui06-rolling-plans-3-{{ md5('parent') }}" label="主父级" model="parent" :value="data_get($__livewire,'parent')" ><option value="">全部</option>@foreach($this->state()['nodes'] as $r)<option value="{{ $r['type'] }}:{{ $r['id'] }}">{{ $this::LABELS[$r['type']] }} {{ $r['title'] }}</option>@endforeach</x-nf.story-select>
<x-nf.story-select id="ui06-rolling-plans-4-{{ md5('status') }}" label="成熟度" model="status" :value="data_get($__livewire,'status')" ><option value="">全部</option><option value="skeleton">骨架</option><option value="detailed">已填细节</option></x-nf.story-select>
<x-nf.field id="ui06-rolling-plans-5-{{ md5('from') }}" label="序号从" :error="$errors->first('from')"><x-nf.control id="ui06-rolling-plans-5-{{ md5('from') }}" type="number" wire:model.live="from"  /></x-nf.field><x-nf.field id="ui06-rolling-plans-6-{{ md5('until') }}" label="至" :error="$errors->first('until')"><x-nf.control id="ui06-rolling-plans-6-{{ md5('until') }}" type="number" wire:model.live="until"  /></x-nf.field>
<label class="nf-story-check"><input type="checkbox" wire:model.live="missing" /> 缺少 POV 或预算</label>
</div>
@php($rows=$this->rows())
<x-nf.table label="故事资料列表"><table class="w-full text-left"><thead><tr><th>节点 / 父级</th><th>目标 / 必填信息</th><th>预算（下限 / 目标 / 上限）</th><th>成熟度 / 操作</th></tr></thead><tbody>
@foreach($rows as $r)<tr wire:key="plan-{{ $selectedId }}-{{ $r['type'] }}-{{ $r['id'] }}"><td>{{ $this::LABELS[$r['type']] }} #{{ $r['id'] }} · {{ $r['sequence'] }} {{ $r['title'] }}<p>父级 #{{ $r['parent_id']??'本书' }}</p></td><td>{{ $r['objective'] }}<details wire:ignore.self><summary>计划细节与依赖</summary>@foreach($r['details'] as $k=>$v)<p>{{ $this::DETAILS[$k]??$k }}：{{ $v }}</p>@endforeach</details></td><td>{{ $r['details']['min_words']??'缺失' }} / {{ $r['details']['target_words']??'缺失' }} / {{ $r['details']['max_words']??'缺失' }}</td><td>{{ $r['status']==='skeleton'?'骨架（不可生成）':'细节已填写（以门禁为准）' }} @if($this->editable()&&!$this->nodeFrozen($r['type'],$r['id']))<x-filament::button wire:click="mountAction('node',{node_type:'{{ $r['type'] }}',node:'{{ $r['id'] }}'})">编辑</x-filament::button>@endif</td></tr>@endforeach
</tbody></table></x-nf.table>{{ $rows->links() }}
</x-nf.section>
<x-nf.section heading="不可变计划历史">
<x-nf.story-select id="ui06-rolling-plans-7-{{ md5('planState') }}" label="计划状态" model="planState" :value="data_get($__livewire,'planState')" ><option value="">全部</option>@foreach($this::STATES as $k=>$label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</x-nf.story-select>
@php($history=$this->plans())
@foreach($history['items'] as $p)<p><button wire:click="$set('selectedId','{{ $p['id'] }}')">v{{ $p['version'] }} · 第{{ $p['start_chapter'] }}—{{ $p['end_chapter'] }}章</button> · {{ $this::STATES[$p['display_status']] }} · {{ $p['reason'] }} · {{ $p['created_at'] }}</p>@endforeach
@if($history['next_before'])<x-filament::button wire:click="$set('before','{{ $history['next_before'] }}')">较早版本</x-filament::button>@endif
@if($before)<x-filament::button wire:click="$set('before','')">最新版本</x-filament::button>@endif
</x-nf.section>
</x-filament-panels::page>
