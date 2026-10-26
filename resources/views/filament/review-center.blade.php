<x-filament-panels::page>
    @include('filament.book-navigation', ['navigation' => $this->bookNavigation()])
    {{-- E04评审和策略共享服务鉴权，页面不提供修改正文或强制通过动作。 --}}
    <p>离线评分只决定候选资格。自然度需人工阅读评分确认，不以 AI 检测器概率验收。</p>
    <p role="status">{{ $notice }}</p>
    <section class="space-y-3 rounded-xl border p-4">
        <h2 class="font-bold">正文评审与人工评分</h2>

        <label>正文版本 <input wire:model="version"></label><label>本章提取记录 <input wire:model="extraction"></label>
        <label>评审策略版本 <input wire:model="policy"></label>
        <label>评审者 <select wire:model="reviewer"><option value="offline">明确离线响应</option><option value="human">本人阅读后的人工评分</option></select></label>
        <label class="block">七维评分与问题 JSON <textarea class="w-full" rows="8" wire:model="output"></textarea></label>
        <details><summary>响应格式</summary><pre class="overflow-auto">{{ json_encode(\App\Review\ReviewContract::schema(), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details>
        <label class="block">操作原因 <textarea class="w-full" wire:model="reason"></textarea></label>
        <x-filament::button wire:click="review">保存评分并验证</x-filament::button>
        <details><summary>通过离线路由评审</summary>
            <label>路由版本 <input wire:model="route"></label><label>预算策略 <input wire:model="budget"></label><label>报价版本（逗号分隔）<input wire:model="prices"></label>
            <x-filament::button wire:click="startPipeline">冻结并启动离线评审</x-filament::button>
            <label>运行 ID <input wire:model="run"></label><x-filament::button wire:click="replayPipeline">执行离线回放</x-filament::button>
        </details>
    </section>
    <section class="space-y-3 rounded-xl border p-4">
        <h2 class="font-bold">评审历史</h2>
        <label>结论 <select wire:model.live="verdict"><option value="">全部</option><option value="pass">PASS</option><option value="block">BLOCK</option><option value="stale">已过期</option></select></label>
        <label>评审者 <select wire:model.live="reviewerFilter"><option value="">全部</option><option value="offline">离线</option><option value="human">人工</option></select></label>
        <table class="w-full"><thead><tr><th>记录 / 正文</th><th>评审者 / 结果</th><th>硬门禁 / 平均分 / 有效性</th></tr></thead><tbody>
        @foreach($this->listing() as $r)<tr><td>{{ $r['id'] }} / {{ $r['chapter_version_id'] }}</td><td>{{ $r['reviewer_type'] }} / {{ $r['report']['verdict'] }}</td><td>{{ $r['report']['hard_pass'] ? '通过' : '阻塞' }} / {{ $r['report']['average'] ?? '不可用' }} / {{ $r['stale'] ? '已过期，不可作为提交依据' : '当前' }}</td></tr>@endforeach
        </tbody></table>
        <label>查看记录 <input wire:model="selected"></label><x-filament::button wire:click="inspect">查看原文、评分与证据</x-filament::button>
        @if($detail)
            <p>正文哈希 {{ $detail['body_hash'] }}；上下文 {{ $detail['context_hash'] }}；状态 {{ $detail['display_status'] }}</p>
            @if($detail['stale'])<p>正文、提取或上下文已改变，旧评审失效。</p>@endif
            <pre class="whitespace-pre-wrap">{{ $detail['frozen_input']['context']['body'] }}</pre>
            <table class="w-full"><thead><tr><th>维度</th><th>评分</th><th>理由与原句</th></tr></thead><tbody>@foreach($detail['report']['scores'] as $dim=>$score)<tr><td>{{ \App\Review\ReviewContract::LABELS[$dim] }}</td><td>{{ $score['score'] }}</td><td>{{ $score['reason'] }}：「{{ $score['evidence']['quote'] }}」</td></tr>@endforeach</tbody></table>
            <label>严重度 <select wire:model.live="severity"><option value="">全部</option><option value="blocking">阻塞</option><option value="warning">警告</option><option value="info">提示</option></select></label><label>类型 <input wire:model.live="category"></label>
            @foreach($this->issues() as $i)<article class="rounded border p-3"><p>{{ $i['id'] }} · {{ $i['category'] }} / {{ $i['severity'] }}：{{ $i['message'] }}</p><p>位置 {{ $i['start_offset'] }}–{{ $i['end_offset'] }}：「{{ $i['quote'] }}」</p><p>{{ $i['suggested_fix'] }}</p><pre class="overflow-auto">{{ json_encode($i['details'],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) }}</pre>@if($i['decision'])<p>人工意见：{{ $i['decision']->decision }}，{{ $i['decision']->reason }}</p>@endif</article>@endforeach
            <label>非阻塞问题 ID <input wire:model="issue"></label><label>意见 <select wire:model="decision"><option value="accepted">接受此文学提示</option><option value="rejected">拒绝此文学提示</option></select></label><x-filament::button wire:click="decide">追加人工意见</x-filament::button>
            <p>本轮不生成修订任务。正文修改后需要重新提取并重新评审，历史记录保留。</p>
        @endif
    </section>
    <section class="space-y-3 rounded-xl border p-4">
        <h2 class="font-bold">文风与评审策略版本</h2><p>文风复用正文生成的同一版本；阈值降低须明确新版本与原因，不能改变已有结果。规则仅作提示，不自动删改文学表达。</p>
        <ul>@foreach($this->policies() as $p)<li>{{ $p['id'] }} · {{ $p['key'] }} v{{ $p['version'] }} · {{ $p['status'] }} · 文风 {{ $p['style_version_id'] }}</li>@endforeach</ul>
        <label>策略键 <input wire:model="policyKey"></label><label>预期版本 <input type="number" min="0" wire:model="policyVersion"></label><label>正文文风版本 <input wire:model="style"></label>
        <textarea class="w-full" rows="8" wire:model="policyJson" aria-label="评审策略内容"></textarea>
        <x-filament::button wire:click="policyAction('save')">追加策略草稿</x-filament::button><x-filament::button wire:click="policyAction('publish')">发布选中策略</x-filament::button><x-filament::button wire:click="policyAction('retire')">退役选中策略</x-filament::button>
        <label>对照策略版本 <input wire:model="comparePolicy"></label><x-filament::button wire:click="loadPolicy">读取或比较策略与文风</x-filament::button>
        @if($comparison)<pre class="overflow-auto">{{ json_encode($comparison, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>@endif
    </section>
</x-filament-panels::page>
