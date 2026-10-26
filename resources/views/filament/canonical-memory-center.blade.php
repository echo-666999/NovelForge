<x-filament-panels::page>
    {{-- 正式资料与候选库分开，摘要为保守结构化保留，向量仅为离线字符编码。 --}}
    <p>正式提交后派生中心。四层摘要保留精确事实和值，不代表模型语义摘要；offline_hash_v1不是语义嵌入。memory队列由定时补投恢复，下一章硬状态不依赖索引。</p>
    <label>小说<select wire:model.live="novel"><option value="">请选择</option>@foreach($this->books() as $b)<option value="{{ $b->id }}">{{ $b->title }}</option>@endforeach</select></label>
    @if($novel==='')<p>请选择小说查看正式任务、来源与重试历史。</p>@else
    @php($state=$this->state())
    <p>基线 #{{ $state['revision_id'] ?? '未初始化' }} / 正式状态 {{ $state['state_version'] }} / memory_pending：{{ $state['memory_pending'] ? '是' : '否' }} / 待处理 {{ $state['pending_count'] }}</p>
    <label>人工重试原因<input wire:model="reason"></label><p role="status">{{ $notice }}</p>
    <table><thead><tr><th>任务 / 正式提交 / 基线</th><th>处理状态 / 向量 / 错误</th><th>租约 / 尝试</th><th>恢复</th></tr></thead><tbody>@forelse($state['tasks'] as $t)<tr><td>#{{ $t['id'] }} / #{{ $t['commit_id'] }} / #{{ $t['revision_id'] }}</td><td>{{ $t['status'] }} / {{ $t['vector_status'] }} / {{ $t['error_code'] }}</td><td>{{ $t['lease_until'] }} / {{ $t['attempts'] }} / fencing {{ $t['token'] }}<details><summary>尝试历史</summary><pre>{{ json_encode($t['attempt_log'],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) }}</pre></details></td><td>@if($t['status']!=='running')<x-filament::button wire:click="retry('{{ $t['id'] }}')">明确重试派生任务</x-filament::button>@endif</td></tr>@empty<tr><td>尚无已登记任务；提交意图由定时器补投，不能用候选假装已完成。</td></tr>@endforelse</tbody></table>
    <h2>正式记忆版本（只读）</h2>@forelse($this->memories() as $m)<details><summary>#{{ $m['id'] }} {{ $m['kind'] }} / 第{{ $m['chapter_sequence'] }}章 / 提交 #{{ $m['commit_id'] }}</summary><p>哈希 {{ $m['content_hash'] }}</p><pre class="whitespace-pre-wrap">{{ $m['summary'] }}</pre></details>@empty<p>尚无正式摘要；下一章会直接补读正式状态与最近正文。</p>@endforelse
    <h2>正式检索与降级诊断</h2><label>截止正式章<input type="number" wire:model="chapter"></label><label>查询<input wire:model="query"></label><label>视角<select wire:model="audience"><option value="author">作者</option><option value="pov">人物POV</option></select></label><label>POV人物ID<input wire:model="pov"></label><label><input type="checkbox" wire:model="vector">启用离线向量</label><x-filament::button wire:click="search">检索当前正式基线</x-filament::button>
    @if($result)<p>降级 {{ $result['degraded'] ? '是' : '否' }} / {{ $result['degradation_reason'] }} / memory_pending {{ $result['memory_pending'] ? '是' : '否' }}</p>@forelse($result['results'] as $hit)<p>正式事件 #{{ $hit['event_id'] }}：{{ $hit['attribute'] }} / {{ $hit['body'] }}</p>@empty<p>无合法命中；未放宽基线、章位或人物知识过滤。</p>@endforelse @endif
    @endif
</x-filament-panels::page>
