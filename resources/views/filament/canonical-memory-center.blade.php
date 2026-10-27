<x-filament-panels::page class="nf-story-screen nf-production-screen"><div class="nf-production-layout">
    @include('filament.book-navigation', ['navigation' => $this->bookNavigation()])
@php($pageRead=($novel===''?['ok'=>true,'data'=>[]]:$this->productionReadFrame(['state'])))
@if(!$pageRead['ok'])<x-nf.state state="error" title="资料读取失败"><p>{{ $pageRead['message'] }}</p><div class="nf-story-toolbar"><x-filament::button color="gray" wire:click="$refresh">重新读取</x-filament::button><x-filament::button color="gray" wire:click="resetProductionFilters">重置筛选</x-filament::button></div></x-nf.state>
@else
<div wire:loading role="status">正在读取或保存，请稍候…</div>
@if($errors->any())<x-nf.state state="error" title="请核对字段，输入仍保留"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-nf.state>@endif
    {{-- 正式资料与候选库分开，摘要为保守结构化保留，向量仅为离线字符编码。 --}}
    <p>正式提交后派生中心。四层摘要保留精确事实和值，不代表模型语义摘要；offline_hash_v1不是语义嵌入。memory队列由定时补投恢复，下一章硬状态不依赖索引。</p>

    @if($novel==='')<p>请选择小说查看正式任务、来源与重试历史。</p>@else
    @php($state=$pageRead['data']['state'])
    <p>基线 #{{ $state['revision_id'] ?? '未初始化' }} / 正式状态 {{ $state['state_version'] }} / memory_pending：{{ $state['memory_pending'] ? '是' : '否' }} / 待处理 {{ $state['pending_count'] }}</p>
    <x-nf.field id="ui07-canonical-memory-center-1-{{ md5('reason') }}" label="人工重试原因" :error="$errors->first('reason')"><input id="ui07-canonical-memory-center-1-{{ md5('reason') }}" wire:model="reason"></x-nf.field><p role="status">{{ $notice }}</p>
    <x-nf.table label="生产资料列表"><table><thead><tr><th>任务 / 正式提交 / 基线</th><th>处理状态 / 向量 / 错误</th><th>租约 / 尝试</th><th>恢复</th></tr></thead><tbody>@forelse($state['tasks'] as $t)<tr><td>#{{ $t['id'] }} / #{{ $t['commit_id'] }} / #{{ $t['revision_id'] }}</td><td>{{ $t['status'] }} / {{ $t['vector_status'] }} / {{ $t['error_code'] }}</td><td>{{ $t['lease_until'] }} / {{ $t['attempts'] }} / fencing {{ $t['token'] }}<details wire:ignore.self><summary>尝试历史</summary>@include('filament.value-table',['value'=>$t['attempt_log'],'label'=>'逐字段只读证据'])</details></td><td>@if($t['status']!=='running')<x-filament::button wire:loading.attr="disabled" wire:click="retry('{{ $t['id'] }}')">明确重试派生任务</x-filament::button>@endif</td></tr>@empty<tr><td>尚无已登记任务；提交意图由定时器补投，不能用候选假装已完成。</td></tr>@endforelse</tbody></table></x-nf.table>
    <h2>正式记忆版本（只读）</h2>@forelse($this->memories() as $m)<details wire:ignore.self><summary>#{{ $m['id'] }} {{ $m['kind'] }} / 第{{ $m['chapter_sequence'] }}章 / 提交 #{{ $m['commit_id'] }}</summary><p>哈希 {{ $m['content_hash'] }}</p>@include('filament.production-text',['text'=>$m['summary'],'label'=>'正式分层摘要只读'])</details>@empty<p>尚无正式摘要；下一章会直接补读正式状态与最近正文。</p>@endforelse
    <h2>正式检索与降级诊断</h2><x-nf.field id="ui07-canonical-memory-center-2-{{ md5('chapter') }}" label="截止正式章" :error="$errors->first('chapter')"><input id="ui07-canonical-memory-center-2-{{ md5('chapter') }}" type="number" wire:model="chapter"></x-nf.field><x-nf.field id="ui07-canonical-memory-center-3-{{ md5('query') }}" label="查询" :error="$errors->first('query')"><input id="ui07-canonical-memory-center-3-{{ md5('query') }}" wire:model="query"></x-nf.field><x-nf.story-select id="ui07-canonical-memory-center-4-{{ md5('audience') }}" label="视角" model="audience" :value="data_get($__livewire,'audience')" ><option value="author">作者</option><option value="pov">人物POV</option></x-nf.story-select>@include('filament.production-reference',['label'=>'POV人物','model'=>'pov','kind'=>'character','multiple'=>false])<label class="nf-story-check"><input type="checkbox" wire:model="vector">启用离线向量</label><x-filament::button wire:loading.attr="disabled" wire:click="search">检索当前正式基线</x-filament::button>
    @if($result)<p>降级 {{ $result['degraded'] ? '是' : '否' }} / {{ $result['degradation_reason'] }} / memory_pending {{ $result['memory_pending'] ? '是' : '否' }}</p>@forelse($result['results'] as $hit)<p>正式事件 #{{ $hit['event_id'] }}：{{ $hit['attribute'] }} / {{ $hit['body'] }}</p>@empty<p>无合法命中；未放宽基线、章位或人物知识过滤。</p>@endforelse @endif
    @endif
@endif
</div></x-filament-panels::page>
