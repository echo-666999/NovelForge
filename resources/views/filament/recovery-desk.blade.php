<x-filament-panels::page>
    @include('filament.book-navigation', ['navigation' => $this->bookNavigation()])
    {{-- 跨页只传同书运行标识，详情页再次鉴权。 --}}
    @if($novel !== '' && $run !== '')<a href="{{ '/admin/run-center?novel='.$novel.'&run='.$run }}">返回运行、请求与审计详情</a>@endif
    {{-- 恢复台明确区分证据、执行状态与尚未实现的业务阶段。 --}}
    <p>暂停和取消不会抹掉费用；迟到响应仅留证，不能自动提交。</p>
    <section class="space-y-3">
        <h2>已有运行（最近100条）</h2>
        @forelse($this->runs() as $item)
            <p>小说 {{ $item->novel_id }} / 运行 {{ $item->id }} / {{ $item->status }}</p>
        @empty
            <p>尚无运行。当前没有真实生成入口，不会创建演示成功记录。</p>
        @endforelse

        <label>运行标识 <input wire:model="run" class="border rounded p-2" /></label>
        <label>拟变更的节点（逗号分隔；不变更则留空） <input wire:model="changedScopes" class="border rounded p-2" /></label>
        <x-filament::button wire:click="inspect">刷新恢复预览</x-filament::button>
    </section>
    @if($notice)<p role="status">{{ $notice }}</p>@endif
    @if($preview)
        <h2>运行 {{ $preview['run_id'] }}：{{ $preview['status'] }}</h2>
        <p>{{ $preview['configuration_rule'] }}</p>
        <p>{{ $preview['execution_boundary'] }}</p>
        <p>隔离迟到证据 {{ $preview['late_evidence_count'] }} 条；决策版本 {{ $preview['decision_version'] }}。</p>
        <label>筛选 <select wire:model.live="filter"><option value="">全部阶段</option value="redo">需要重做</option><option value="reuse">可复用</option><option value="checkpoint">检查点续跑</option><option value="stale">来源过期</option></select></label>
        <div class="overflow-x-auto"><table class="w-full text-left"><thead><tr><th>阶段</th><th>状态 / 错误</th><th>恢复范围</th><th>技术尝试 / 文学修订</th><th>证据</th><th>建议</th></tr></thead><tbody>
        @foreach($preview['rows'] as $row)
            @if($filter === '' || $filter === $row['disposition'])
            <tr><td>{{ $row['scope'] }}</td><td>{{ $row['status'] }} / {{ $row['error_code'] ?? '无' }}</td><td>{{ $row['disposition'] }}</td><td>{{ $row['technical_attempts'] }} / 0（修订未接入）</td><td>{{ $row['artifact_id'] ?? '无' }}@if($row['artifact_content'])<details><summary>原始产物</summary><pre>{{ json_encode($row['artifact_content'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details>@endif</td><td>{{ $row['classification']['advice'] }}@if($row['next_retry_at'])<p>下一次投递：{{ $row['next_retry_at'] }}</p>@endif</td></tr>
            @endif
        @endforeach
        </tbody></table></div>
        <details><summary>隔离证据与人工决策记录</summary><pre class="overflow-auto">{{ json_encode(['late'=>$preview['late_evidence'],'decisions'=>$preview['decisions']], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details>
        <details><summary>请求与费用证据</summary><pre class="overflow-auto">{{ json_encode($preview['requests'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details>
        <label>操作阶段 <input wire:model="scope" class="border rounded p-2" /></label>
        <label>批准的备用模型版本（可选） <input wire:model="modelVersion" class="border rounded p-2" /></label>
        <label>决定原因 <textarea wire:model="reason" class="border rounded p-2 w-full"></textarea></label>
        <div class="flex flex-wrap gap-3">
            @foreach(['pause'=>'暂停','resume'=>'恢复调度','cancel'=>'取消运行','manual'=>'转人工','retry'=>'重试此阶段','reextract'=>'登记原证据重新提取','change_plan'=>'登记配置变更影响'] as $action=>$label)
                <x-filament::button wire:click="decide('{{ $action }}')" wire:confirm="确认{{ $label }}？操作将保留审计，取消不能撤销。" wire:loading.attr="disabled">{{ $label }}</x-filament::button>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
