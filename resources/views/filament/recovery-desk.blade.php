<x-filament-panels::page class="nf-story-screen nf-production-screen"><div class="nf-production-layout">
    @include('filament.book-navigation', ['navigation' => $this->bookNavigation()])
<div wire:loading role="status">正在读取或保存，请稍候…</div>
@if($errors->any())<x-nf.state state="error" title="请核对字段，输入仍保留"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-nf.state>@endif
    {{-- 跨页只传同书运行标识，详情页再次鉴权。 --}}
    @if($novel !== '' && $run !== '')<a href="{{ '/admin/run-center?novel='.$novel.'&run='.$run }}">返回运行、请求与审计详情</a>@endif
    {{-- 恢复台明确区分证据、执行状态与尚未实现的业务阶段。 --}}
    <p>暂停和取消不会抹掉费用；迟到响应仅留证，不能自动提交。</p>
    <x-nf.section heading="已有运行（最近100条）"><div class="nf-production-layout">
        @forelse($this->runs() as $item)
            <p>小说 {{ $item->novel_id }} / 运行 {{ $item->id }} / {{ $item->status }}</p>
        @empty
            <p>尚无运行。当前没有真实生成入口，不会创建演示成功记录。</p>
        @endforelse

        @include('filament.production-reference',['label'=>'运行标识','model'=>'run','kind'=>'run','multiple'=>false])
        @include('filament.production-reference',['label'=>'拟变更的节点','model'=>'changedScopes','kind'=>'scope','multiple'=>true])
        <x-filament::button wire:loading.attr="disabled" wire:click="inspect">刷新恢复预览</x-filament::button>
    </div></x-nf.section>
    @if($notice)<p role="status">{{ $notice }}</p>@endif
    @if($preview)
        <h2>运行 {{ $preview['run_id'] }}：{{ $preview['status'] }}</h2>
        <p>{{ $preview['configuration_rule'] }}</p>
        <p>{{ $preview['execution_boundary'] }}</p>
        <p>隔离迟到证据 {{ $preview['late_evidence_count'] }} 条；决策版本 {{ $preview['decision_version'] }}。</p>
        <x-nf.story-select id="ui07-recovery-desk-3-{{ md5('filter') }}" label="筛选" model="filter" :value="data_get($__livewire,'filter')" ><option value="">全部阶段</option><option value="redo">需要重做</option><option value="reuse">可复用</option><option value="checkpoint">检查点续跑</option><option value="stale">来源过期</option></x-nf.story-select>
        <div><x-nf.table label="生产资料列表"><table class="w-full text-left"><thead><tr><th>阶段</th><th>状态 / 错误</th><th>恢复范围</th><th>技术尝试 / 文学修订</th><th>证据</th><th>建议</th></tr></thead><tbody>
        @foreach($preview['rows'] as $row)
            @if($filter === '' || $filter === $row['disposition'])
            <tr><td>{{ $row['scope'] }}</td><td>{{ $row['status'] }} / {{ $row['error_code'] ?? '无' }}</td><td>{{ $row['disposition'] }}</td><td>{{ $row['technical_attempts'] }} / 本页未读取文学轮次</td><td>{{ $row['artifact_id'] ?? '无' }}@if($row['artifact_content'])<details wire:ignore.self><summary>原始产物</summary>@include('filament.value-table',['value'=>$row['artifact_content'],'label'=>'逐字段只读证据'])</details>@endif</td><td>{{ $row['classification']['advice'] }}@if($row['next_retry_at'])<p>下一次投递：{{ $row['next_retry_at'] }}</p>@endif</td></tr>
            @endif
        @endforeach
        </tbody></table></x-nf.table></div>
        <details wire:ignore.self><summary>隔离证据与人工决策记录</summary>@include('filament.value-table',['value'=>['late'=>$preview['late_evidence'],'decisions'=>$preview['decisions']],'label'=>'逐字段只读证据'])</details>
        <details wire:ignore.self><summary>请求与费用证据</summary>@include('filament.value-table',['value'=>$preview['requests'],'label'=>'逐字段只读证据'])</details>
        @include('filament.production-reference',['label'=>'操作阶段','model'=>'scope','kind'=>'scope','multiple'=>false])
        @include('filament.production-reference',['label'=>'批准的备用模型版本（可选）','model'=>'modelVersion','kind'=>'model','multiple'=>false])
        <x-nf.field id="ui07-recovery-desk-6-{{ md5('reason') }}" label="决定原因" :error="$errors->first('reason')"><textarea id="ui07-recovery-desk-6-{{ md5('reason') }}" wire:model="reason" class="border rounded p-2 w-full"></textarea></x-nf.field>
        <div class="nf-story-toolbar">
            @foreach(['pause'=>'暂停','resume'=>'恢复调度','cancel'=>'取消运行','manual'=>'转人工','retry'=>'重试此阶段','reextract'=>'登记原证据重新提取','change_plan'=>'登记配置变更影响'] as $action=>$label)
                <x-filament::button wire:loading.attr="disabled" wire:click="decide('{{ $action }}')" wire:confirm="确认{{ $label }}？操作将保留审计，取消不能撤销。" >{{ $label }}</x-filament::button>
            @endforeach
        </div>
    @endif
</div></x-filament-panels::page>
