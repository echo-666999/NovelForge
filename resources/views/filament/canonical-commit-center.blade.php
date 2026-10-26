<x-filament-panels::page>
    {{-- 正式状态来自提交回执，预检与模型评分不能代替提交。 --}}
    <p>顺序提交完整章节。预检、正式提交与作者成品认可分别记录；此页没有强制通过或修改正式历史的入口。</p>
    <label>小说<select wire:model.live="novel"><option value="">请选择</option>@foreach($this->books() as $book)<option value="{{ $book->id }}">{{ $book->title }}</option>@endforeach</select></label>
    @if($novel==='')<p>请选择小说查看基线、预检与提交记录。</p>@else
    @php($state=$this->state())
    @if(!$state['revision'])<p>尚未初始化正式基线。空根初始化不会产生正文、事件或推进剧情版本。</p><x-filament::button wire:click="initialize">初始化空根基线</x-filament::button>
    @else<p>正式基线 #{{ $state['revision']['id'] }} / 连续提交至第{{ $state['revision']['cursor'] }}章 / 状态版本{{ $state['state_version'] }} / 待处理记忆意图{{ $state['outbox_pending'] }}</p>@endif
    <x-filament::section heading="章节提交预检">
        <label>候选版本<select wire:model="candidate"><option value="">请选择</option>@foreach($this->candidates() as $v)<option value="{{ $v->id }}">{{ $v->title }} / V{{ $v->version }} / #{{ $v->id }}</option>@endforeach</select></label><x-filament::button wire:click="loadCandidate">载入候选与当前依赖</x-filament::button>
        @if($form)<div class="grid gap-3 md:grid-cols-2">
        @foreach(['chapter_id'=>'章节ID','chapter_version_id'=>'正文版本','expected_revision_id'=>'预期正式基线','expected_state_version'=>'预期状态版本','review_id'=>'有效评审ID','event_artifact_id'=>'E03事件产物ID','plan_node_version_id'=>'冻结章节节点ID','reason'=>'提交理由'] as $key=>$label)<label>{{ $label }}<input wire:model="form.{{ $key }}"></label>@endforeach</div>
        <x-filament::button wire:click="check">预检</x-filament::button><x-filament::button wire:click="submit">提交本章</x-filament::button>
        <p>当前请求键：{{ $commandKey }}。重复提交返回原回执；依赖变化需重新载入、预检或复评。</p>@endif
        @if($preflight)<p>预检 #{{ $preflight['id'] }}：{{ $preflight['status'] }} / {{ $preflight['message'] }}</p>@if($preflight['status']==='ready')<p>事件{{ $preflight['event_count'] }}条 / 正文哈希{{ $preflight['body_hash'] }}</p><details><summary>拟提交状态变化（尚未正式生效）</summary><pre class="whitespace-pre-wrap">{{ json_encode($preflight['candidate_state'], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) }}</pre></details>@else<p>{{ $preflight['code'] }}</p>@endif @endif
    </x-filament::section>
    <p role="status">{{ $notice }}</p>
    <div class="flex flex-wrap gap-3"><label>章ID<input wire:model.live="chapterFilter"></label><label>基线ID<input wire:model.live="revisionFilter"></label><label>状态<select wire:model.live="statusFilter"><option value="">全部</option><option value="ready">预检通过</option><option value="blocked">预检阻塞</option><option value="committed">已正式提交</option></select></label><label>预检错误码<input wire:model.live="errorFilter"></label><label>开始日期<input type="date" wire:model.live="from"></label><label>结束日期<input type="date" wire:model.live="to"></label></div>
    <p>第{{ $state['page'] }}页，每页最多50项；正式提交{{ $state['commits_total'] }}项，预检{{ $state['preflights_total'] }}项。</p><x-filament::button wire:click="newer">上一页</x-filament::button><x-filament::button wire:click="older">下一页</x-filament::button>
    <x-filament::section heading="正式提交历史">
        <table><thead><tr><th>章序 / 正文</th><th>状态版本</th><th>幂等键 / 操作者</th><th>时间 / 原因</th><th>动作</th></tr></thead><tbody>@forelse($state['commits'] as $c)<tr><td>第{{ $c['chapter_sequence'] }}章 / #{{ $c['chapter_version_id'] }}</td><td>{{ $c['previous_state_version'] }} → {{ $c['new_state_version'] }}</td><td>{{ $c['idempotency_key'] }} / {{ $c['actor_id'] }}</td><td>{{ $c['created_at'] }} / {{ $c['reason'] }}</td><td><x-filament::button wire:click="inspect('{{ $c['id'] }}')">查看回执与正式状态</x-filament::button></td></tr>@empty<tr><td>尚无正式提交。候选正文和评审通过不会自动出现在这里。</td></tr>@endforelse</tbody></table>
    </x-filament::section>
    <x-filament::section heading="预检与阻塞记录">@forelse($state['preflights'] as $p)<p>#{{ $p['id'] }} / {{ $p['created_at'] }} / {{ $p['status'] }} / {{ $p['code']??'' }} / {{ $p['message'] }}</p>@empty<p>暂无符合条件的预检记录。</p>@endforelse</x-filament::section>
    @if($receipt)<x-filament::section heading="正式回执"><pre class="whitespace-pre-wrap">{{ json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) }}</pre></x-filament::section>@endif
    <p>历史修订、候选基线回放与切换由G01提供；记忆索引消费由E07提供，本轮未开放。</p>
    @endif
</x-filament-panels::page>
