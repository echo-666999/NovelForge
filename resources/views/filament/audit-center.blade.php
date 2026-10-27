<x-filament-panels::page class="nf-story-screen nf-production-screen"><div class="nf-production-layout">
@php($pageRead=$this->productionReadFrame(['catalog']))
@if(!$pageRead['ok'])<x-nf.state state="error" title="资料读取失败"><p>{{ $pageRead['message'] }}</p><div class="nf-story-toolbar"><x-filament::button color="gray" wire:click="$refresh">重新读取</x-filament::button><x-filament::button color="gray" wire:click="resetProductionFilters">重置筛选</x-filament::button></div></x-nf.state>
@else
    {{-- 健康状态只表示已经观测的数据库事实，不推断外部供应商或Worker在线。 --}}
    @php($health=$this->health())
    <h2>数据库观测：{{ $health['state'] }}</h2><p>{{ $health['scope'] }}</p>
    @include('filament.value-table',['value'=>$health,'label'=>'逐字段只读证据'])
<div wire:loading role="status">正在读取或保存，请稍候…</div>
@if($errors->any())<x-nf.state state="error" title="请核对字段，输入仍保留"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-nf.state>@endif
    <div class="nf-story-toolbar">
        @foreach(['novel'=>'小说ID','trace'=>'根/操作追踪号','action'=>'动作','status'=>'结果或失败码','dateFrom'=>'开始日期 UTC','dateTo'=>'结束日期 UTC'] as $field=>$label)
            @if($field==='novel')@include('filament.production-reference',['label'=>$label,'model'=>$field,'kind'=>'book'])@else<x-nf.field id="ui07-audit-center-1-{{ md5(($field)) }}" label="{{ $label }}" :error="$errors->first(($field))"><input id="ui07-audit-center-1-{{ md5(($field)) }}" class="border rounded p-2" type="{{ in_array($field,['dateFrom','dateTo'],true)?'date':'text' }}" wire:model.live.debounce.500ms="{{ $field }}" /></x-nf.field>@endif
        @endforeach
        <label class="nf-story-check"><input type="checkbox" wire:model.live="highRisk" />仅高风险</label>
    </div>
    @php($catalog=$pageRead['data']['catalog'])
    <p>{{ $catalog['total'] }} 条 / 第 {{ $catalog['page'] }} 页；审计只追加，没有删除动作。</p>
    <a href="{{ '/api/v1/audit/export?'.http_build_query(array_filter($this->filters(),fn($v)=>$v!==null)) }}">下载当前页脱敏审计记录</a>
    <x-nf.field id="ui07-audit-center-2-{{ md5('page') }}" label="页码" :error="$errors->first('page')"><input id="ui07-audit-center-2-{{ md5('page') }}" type="number" min="1" wire:model.live="page" /></x-nf.field>
    @forelse($catalog['rows'] as $row)
        <details wire:ignore.self><summary>{{ $row['created_at'] }} / {{ $row['object_type'] }} #{{ $row['object_id'] }} / {{ $row['action'] }} / {{ $row['outcome'] }}</summary>
            <p>操作人 {{ $row['actor_id']??'系统' }}；原因：{{ $row['reason'] }}；根追踪：{{ $row['trace_id'] }}；操作追踪：{{ $row['operation_trace_id'] }}</p>
            @if($row['run_id'])<a href="{{ '/admin/run-center?novel='.$row['novel_id'].'&run='.$row['run_id'] }}">查看运行及outbox</a>@endif
            @if($row['artifact_url'])<a href="{{ $row['artifact_url'] }}">下载完整受控产物</a>@endif
            @include('filament.value-table',['value'=>['before'=>$row['before_state'],'after'=>$row['after_state']],'label'=>'逐字段只读证据'])
        </details>
    @empty <p>暂无符合条件的审计；历史补建追踪号不代表补造历史操作。</p> @endforelse
@endif
</div></x-filament-panels::page>
