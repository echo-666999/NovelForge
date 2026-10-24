<x-filament-panels::page>
    {{-- 健康状态只表示已经观测的数据库事实，不推断外部供应商或Worker在线。 --}}
    @php($health=$this->health())
    <h2>数据库观测：{{ $health['state'] }}</h2><p>{{ $health['scope'] }}</p>
    <pre>{{ json_encode($health,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>
    <div class="flex flex-wrap gap-3">
        @foreach(['novel'=>'小说ID','trace'=>'根/操作追踪号','action'=>'动作','status'=>'结果或失败码','dateFrom'=>'开始日期 UTC','dateTo'=>'结束日期 UTC'] as $field=>$label)
            <label>{{ $label }} <input class="border rounded p-2" wire:model.live.debounce.500ms="{{ $field }}" /></label>
        @endforeach
        <label><input type="checkbox" wire:model.live="highRisk" />仅高风险</label>
    </div>
    @php($catalog=$this->catalog())
    <p>{{ $catalog['total'] }} 条 / 第 {{ $catalog['page'] }} 页；审计只追加，没有删除动作。</p>
    <a href="{{ '/api/v1/audit/export?'.http_build_query(array_filter($this->filters(),fn($v)=>$v!==null)) }}">下载当前页脱敏审计记录</a>
    <label>页码 <input type="number" min="1" wire:model.live="page" /></label>
    @forelse($catalog['rows'] as $row)
        <details><summary>{{ $row['created_at'] }} / {{ $row['object_type'] }} #{{ $row['object_id'] }} / {{ $row['action'] }} / {{ $row['outcome'] }}</summary>
            <p>操作人 {{ $row['actor_id']??'系统' }}；原因：{{ $row['reason'] }}；根追踪：{{ $row['trace_id'] }}；操作追踪：{{ $row['operation_trace_id'] }}</p>
            @if($row['run_id'])<a href="{{ '/admin/run-center?novel='.$row['novel_id'].'&run='.$row['run_id'] }}">查看运行及outbox</a>@endif
            @if($row['artifact_url'])<a href="{{ $row['artifact_url'] }}">下载完整受控产物</a>@endif
            <pre class="overflow-auto">{{ json_encode(['before'=>$row['before_state'],'after'=>$row['after_state']],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>
        </details>
    @empty <p>暂无符合条件的审计；历史补建追踪号不代表补造历史操作。</p> @endforelse
</x-filament-panels::page>
