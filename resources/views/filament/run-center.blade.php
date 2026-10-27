<x-filament-panels::page class="nf-story-screen nf-production-screen"><div class="nf-production-layout">
    @include('filament.book-navigation', ['navigation' => $this->bookNavigation()])
@php($pageRead=$this->productionReadFrame(['catalog','detail']))
@if(!$pageRead['ok'])<x-nf.state state="error" title="资料读取失败"><p>{{ $pageRead['message'] }}</p><div class="nf-story-toolbar"><x-filament::button color="gray" wire:click="$refresh">重新读取</x-filament::button><x-filament::button color="gray" wire:click="resetProductionFilters">重置筛选</x-filament::button></div></x-nf.state>
@else
<div wire:loading role="status">正在读取或保存，请稍候…</div>
@if($errors->any())<x-nf.state state="error" title="请核对字段，输入仍保留"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-nf.state>@endif
    {{-- 只刷新数据库投影，不在页面生命周期调用生成器。 --}}
    <div wire:poll.15s class="space-y-4">
        <p>当前仅离线阶段可运行。本页阶段数不是正式章数，正式前缀见小说上下文；页面关闭不会取消持久任务。</p>
        <div class="nf-story-toolbar">
            @foreach(['run'=>'查看运行ID','status'=>'运行状态','error'=>'错误码','trace'=>'追踪号','dateFrom'=>'开始日期 UTC','dateTo'=>'结束日期 UTC'] as $field=>$label)
                @if($field==='run')@include('filament.production-reference',['label'=>$label,'model'=>$field,'kind'=>'run'])@else<x-nf.field id="ui07-run-center-1-{{ md5(($field)) }}" label="{{ $label }}" :error="$errors->first(($field))"><x-nf.control id="ui07-run-center-1-{{ md5(($field)) }}" class="border rounded p-2" type="{{ in_array($field,['dateFrom','dateTo'],true)?'date':'text' }}" wire:model.live.debounce.500ms="{{ $field }}"  /></x-nf.field>@endif
            @endforeach
        </div>
        @php($catalog=$pageRead['data']['catalog'])
        <div class="nf-production-note">筛选结果 {{ $catalog['total'] }} 条 / 第 {{ $catalog['page'] }} 页。各状态：@include('filament.value-table',['value'=>$catalog['status_counts'],'label'=>'分类明细'])</div>
        <div><x-nf.table label="生产资料列表"><table class="w-full text-left"><thead><tr><th>运行 / 小说</th><th>状态 / 错误</th><th>阶段完成</th><th>费用（分币种）</th><th>开始时间</th><th>追踪号</th></tr></thead><tbody>
        @forelse($catalog['rows'] as $row)
            <tr><td><a href="{{ $row['detail_url'] }}">{{ $row['id'] }} / {{ $row['title'] }}</a></td><td>{{ $row['status'] }} / {{ $row['error_code']??'无' }}</td><td>{{ $row['steps_succeeded'] }}/{{ $row['steps_total'] }}（非正式章数）</td><td>@include('filament.value-table',['value'=>$row['costs'],'label'=>'分类明细'])</td><td>{{ $row['created_at'] }}</td><td>{{ $row['trace_id'] }}</td></tr>
        @empty <tr><td colspan="6">尚无符合条件的运行，不创建演示成功记录。</td></tr> @endforelse
        </tbody></table></x-nf.table></div>
        <x-nf.field id="ui07-run-center-2-{{ md5('page') }}" label="页码" :error="$errors->first('page')"><x-nf.control id="ui07-run-center-2-{{ md5('page') }}" type="number" min="1" wire:model.live="page"  /></x-nf.field>
        @php($detail=$pageRead['data']['detail'])
        @if($detail)
            <h2>运行 {{ $detail['run']['id'] }}：{{ $detail['run']['status'] }}</h2>
            <p>追踪 {{ $detail['trace_id'] }} / {{ $detail['trace_origin'] }}</p>
            <div class="nf-story-toolbar"><a href="{{ $detail['recovery_url'] }}">暂停、取消与恢复决定</a><a href="{{ $detail['billing_url'] }}">成本账本及预算</a><a href="{{ $detail['audit_url'] }}">审计链</a></div>
            <details wire:ignore.self><summary>冻结输入、路由及预算快照</summary>@include('filament.value-table',['value'=>$detail['run']['frozen_config'],'label'=>'逐字段只读证据'])</details>
            <x-nf.field id="ui07-run-center-3-{{ md5('stepStatus') }}" label="阶段状态" :error="$errors->first('stepStatus')"><x-nf.control id="ui07-run-center-3-{{ md5('stepStatus') }}" wire:model.live="stepStatus"  /></x-nf.field><x-nf.field id="ui07-run-center-4-{{ md5('attempt') }}" label="尝试号" :error="$errors->first('attempt')"><x-nf.control id="ui07-run-center-4-{{ md5('attempt') }}" wire:model.live="attempt"  /></x-nf.field><x-nf.field id="ui07-run-center-5-{{ md5('artifactType') }}" label="产物类型" :error="$errors->first('artifactType')"><x-nf.control id="ui07-run-center-5-{{ md5('artifactType') }}" wire:model.live="artifactType"  /></x-nf.field>
            <div><x-nf.table label="生产资料列表"><table class="w-full text-left"><thead><tr><th>阶段 / 尝试</th><th>状态 / 错误</th><th>心跳 / 租约</th><th>耗时秒</th><th>检查点</th></tr></thead><tbody>
            @foreach($detail['steps'] as $step)
                @if(($stepStatus==='' || $stepStatus===$step['status']) && ($attempt==='' || (string)$step['attempt_no']===$attempt))
                <tr><td>{{ $step['scope_key'] }} / {{ $step['attempt_no'] }}</td><td>{{ $step['status'] }} / {{ $step['error_code'] }}</td><td>{{ $step['heartbeat_at']??'未知' }} / {{ $step['lease_expired']?'已过期':($step['lease_until']??'无') }}</td><td>{{ $step['duration_seconds'] }}</td><td>{{ $step['checkpoint_artifact_id']??$step['resume_artifact_id']??'无' }}</td></tr>
                @endif
            @endforeach
            </tbody></table></x-nf.table></div>
            @foreach($detail['artifacts'] as $artifact)
                @if($artifactType==='' || $artifactType===$artifact['type'])
                <section><h3>产物 {{ $artifact['id'] }} / {{ $artifact['type'] }}</h3>@include('filament.production-artifact',['artifact'=>$artifact])<p>{{ $artifact['truncated']?'摘要已截断，完整内容请受控下载。':'摘要完整。' }} <a href="{{ $artifact['download_url'] }}">下载完整产物</a></p></section>
                @endif
            @endforeach
            <h3>请求、Token、已结算与未知风险（分币种）</h3><p>共 {{ $detail['requests_total'] }} 条，每页50条。</p><x-nf.field id="ui07-run-center-6-{{ md5('requestPage') }}" label="请求页码" :error="$errors->first('requestPage')"><x-nf.control id="ui07-run-center-6-{{ md5('requestPage') }}" type="number" min="1" wire:model.live="requestPage"  /></x-nf.field>
            @forelse($detail['requests'] as $request)
                <details wire:ignore.self><summary>请求 {{ $request['id'] }} / {{ $request['status'] }} / {{ $request['error_code'] }}</summary>@include('filament.value-table',['value'=>$request,'label'=>'逐字段只读证据'])
                @foreach($request['evidence'] as $e)<a class="mr-4" href="{{ $e['download_url'] }}">下载完整脱敏{{ $e['kind'] }}证据</a>@endforeach</details>
            @empty <p>没有模型请求；不显示虚构的实际费用。</p> @endforelse
            <details wire:ignore.self><summary>预算与账本汇总</summary>@include('filament.value-table',['value'=>$detail['billing'],'label'=>'逐字段只读证据'])</details>
            <details wire:ignore.self><summary>持久投递与回执</summary>@include('filament.value-table',['value'=>$detail['outbox'],'label'=>'逐字段只读证据'])</details>
        @endif
    </div>
@endif
</div></x-filament-panels::page>
