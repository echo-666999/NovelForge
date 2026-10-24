<x-filament-panels::page>
    {{-- 只刷新数据库投影，不在页面生命周期调用生成器。 --}}
    <div wire:poll.15s class="space-y-4">
        <p>当前仅离线阶段可运行。正式章节进度未知；页面关闭不会取消持久任务。</p>
        <div class="flex flex-wrap gap-3">
            @foreach(['novel'=>'小说ID','run'=>'查看运行ID','status'=>'运行状态','error'=>'错误码','trace'=>'追踪号','dateFrom'=>'开始日期 UTC','dateTo'=>'结束日期 UTC'] as $field=>$label)
                <label>{{ $label }} <input class="border rounded p-2" wire:model.live.debounce.500ms="{{ $field }}" /></label>
            @endforeach
        </div>
        @php($catalog=$this->catalog())
        <p>筛选结果 {{ $catalog['total'] }} 条 / 第 {{ $catalog['page'] }} 页。各状态：{{ json_encode($catalog['status_counts'],JSON_UNESCAPED_UNICODE) }}</p>
        <div class="overflow-x-auto"><table class="w-full text-left"><thead><tr><th>运行 / 小说</th><th>状态 / 错误</th><th>阶段完成</th><th>费用（分币种）</th><th>开始时间</th><th>追踪号</th></tr></thead><tbody>
        @forelse($catalog['rows'] as $row)
            <tr><td><a href="{{ $row['detail_url'] }}">{{ $row['id'] }} / {{ $row['title'] }}</a></td><td>{{ $row['status'] }} / {{ $row['error_code']??'无' }}</td><td>{{ $row['steps_succeeded'] }}/{{ $row['steps_total'] }}（非正式章数）</td><td>{{ json_encode($row['costs'],JSON_UNESCAPED_UNICODE) }}</td><td>{{ $row['created_at'] }}</td><td>{{ $row['trace_id'] }}</td></tr>
        @empty <tr><td colspan="6">尚无符合条件的运行，不创建演示成功记录。</td></tr> @endforelse
        </tbody></table></div>
        <label>页码 <input type="number" min="1" wire:model.live="page" /></label>
        @php($detail=$this->detail())
        @if($detail)
            <h2>运行 {{ $detail['run']['id'] }}：{{ $detail['run']['status'] }}</h2>
            <p>追踪 {{ $detail['trace_id'] }} / {{ $detail['trace_origin'] }}</p>
            <div class="flex gap-4"><a href="{{ $detail['recovery_url'] }}">暂停、取消与恢复决定</a><a href="{{ $detail['billing_url'] }}">成本账本及预算</a><a href="{{ $detail['audit_url'] }}">审计链</a></div>
            <details><summary>冻结输入、路由及预算快照</summary><pre class="overflow-auto">{{ json_encode($detail['run']['frozen_config'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details>
            <label>阶段状态 <input wire:model.live="stepStatus" /></label><label>尝试号 <input wire:model.live="attempt" /></label><label>产物类型 <input wire:model.live="artifactType" /></label>
            <div class="overflow-x-auto"><table class="w-full text-left"><thead><tr><th>阶段 / 尝试</th><th>状态 / 错误</th><th>心跳 / 租约</th><th>耗时秒</th><th>检查点</th></tr></thead><tbody>
            @foreach($detail['steps'] as $step)
                @if(($stepStatus==='' || $stepStatus===$step['status']) && ($attempt==='' || (string)$step['attempt_no']===$attempt))
                <tr><td>{{ $step['scope_key'] }} / {{ $step['attempt_no'] }}</td><td>{{ $step['status'] }} / {{ $step['error_code'] }}</td><td>{{ $step['heartbeat_at']??'未知' }} / {{ $step['lease_expired']?'已过期':($step['lease_until']??'无') }}</td><td>{{ $step['duration_seconds'] }}</td><td>{{ $step['checkpoint_artifact_id']??$step['resume_artifact_id']??'无' }}</td></tr>
                @endif
            @endforeach
            </tbody></table></div>
            @foreach($detail['artifacts'] as $artifact)
                @if($artifactType==='' || $artifactType===$artifact['type'])
                <section><h3>产物 {{ $artifact['id'] }} / {{ $artifact['type'] }}</h3><pre class="overflow-auto">{{ $artifact['preview'] }}</pre><p>{{ $artifact['truncated']?'摘要已截断，完整内容请受控下载。':'摘要完整。' }} <a href="{{ $artifact['download_url'] }}">下载完整产物</a></p></section>
                @endif
            @endforeach
            <h3>请求、Token、已结算与未知风险（分币种）</h3><p>共 {{ $detail['requests_total'] }} 条，每页50条。</p><label>请求页码 <input type="number" min="1" wire:model.live="requestPage" /></label>
            @forelse($detail['requests'] as $request)
                <details><summary>请求 {{ $request['id'] }} / {{ $request['status'] }} / {{ $request['error_code'] }}</summary><pre class="overflow-auto">{{ json_encode($request,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>
                @foreach($request['evidence'] as $e)<a class="mr-4" href="{{ $e['download_url'] }}">下载完整脱敏{{ $e['kind'] }}证据</a>@endforeach</details>
            @empty <p>没有模型请求；不显示虚构的实际费用。</p> @endforelse
            <details><summary>预算与账本汇总</summary><pre class="overflow-auto">{{ json_encode($detail['billing'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details>
            <details><summary>持久投递与回执</summary><pre class="overflow-auto">{{ json_encode($detail['outbox'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details>
        @endif
    </div>
</x-filament-panels::page>
