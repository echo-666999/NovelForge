<x-filament-panels::page>
    @include('filament.book-navigation', ['navigation' => $this->bookNavigation()])
    {{-- 明确冻结范围、选择补丁和重新复评，不把修订采纳当作正式提交。 --}}
    <p role="status">{{ $notice }}</p>
    <p>补丁不会自动采纳；默认最多三轮自动文学修订。无改善、预算不足或达到上限转人工，旧稿与旧失败保留。</p>
    <section class="space-y-3 border rounded p-4">
        <h2 class="font-bold">选择问题与允许范围</h2>
        <label>来源评审 <input wire:model="review"></label><x-filament::button wire:click="prepare">读取评审并准备首段范围</x-filament::button>
        <label>问题 ID（逗号分隔）<input wire:model="issues"></label><label>方式 <select wire:model="mode"><option value="offline">明确离线自动补丁</option><option value="manual">人工编辑</option></select></label>
        <label class="block">范围（段落、来源场景或明确整章）<textarea class="w-full" rows="5" wire:model="scopeJson"></textarea></label>
        <label>额外保留事件 ID <input wire:model="facts"></label><p>已提取的事实、知识/因果关系和伏笔会自动纳入保留门禁，不能通过空列表省略。</p>
        <label class="block">额外保留引文 JSON <textarea class="w-full" wire:model="protectedJson"></textarea></label><label class="block">操作原因 <textarea class="w-full" wire:model="reason"></textarea></label>
        <x-filament::button wire:click="createTask">冻结修订任务</x-filament::button>
    </section>
    <section class="space-y-3 border rounded p-4">
        <h2 class="font-bold">修订历史与候选差异</h2>
        <ul>@foreach($this->listing() as $t)<li>{{ $t['id'] }} · 正文 {{ $t['source_version_id'] }} · {{ $t['display_status'] }} · 自动轮次 {{ $t['automatic_round'] }}/{{ $t['max_rounds'] }}</li>@endforeach</ul>
        <label>任务 ID <input wire:model="selected"></label><x-filament::button wire:click="inspect">查看问题、原文与差异</x-filament::button>
        @if($detail)
            <p>状态 {{ $detail['display_status'] }}；自动轮次 {{ $detail['automatic_round'] }}/{{ $detail['max_rounds'] }}。与技术重试次数分别计数。</p>
            <details open><summary>冻结原文、问题与保留事项</summary><pre class="whitespace-pre-wrap">{{ $detail['input']['source']['body'] }}</pre><pre class="overflow-auto">{{ json_encode(['issues'=>$detail['input']['issues'],'scope'=>$detail['input']['allowed_scope'],'protected'=>$detail['input']['protected'],'facts'=>$detail['input']['preserve_facts']],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) }}</pre></details>
            @foreach($detail['proposals'] as $p)<article class="border p-3"><p>提案 {{ $p['id'] }} · {{ $p['report']['valid'] ? '范围校验通过，待明确选择' : '校验失败，转人工' }}</p>
            @foreach($p['output']['patches']??[] as $patch) @if(is_array($patch))<label class="block"><input type="checkbox" wire:model="patchKeys" value="{{ $patch['key']??'' }}">{{ $patch['key']??'' }} · {{ $patch['start']??'' }}–{{ $patch['end']??'' }}</label><del>{{ $patch['quote']??'' }}</del><pre class="whitespace-pre-wrap">{{ $patch['replacement']??'' }}</pre><p>{{ $patch['reason']??'' }}</p>@endif @endforeach
            <pre class="overflow-auto">{{ json_encode($p['report'],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) }}</pre></article>@endforeach
            <label>提案 ID <input wire:model="proposal"></label><x-filament::button wire:click="decide('accept')">只采纳选中补丁</x-filament::button><x-filament::button wire:click="decide('reject')">拒绝本轮提案</x-filament::button><x-filament::button wire:click="decide('abandon')">放弃本轮</x-filament::button>
            <details><summary>手工编辑冻结范围</summary><label>范围序号（从0开始）<input type="number" min="0" wire:model="range"></label><x-filament::button wire:click="loadManual">载入允许范围原文</x-filament::button><textarea class="w-full" rows="8" wire:model="manualText"></textarea><x-filament::button wire:click="decide('manual')">保存人工编辑候选</x-filament::button></details>
            @if($detail['decision'])<pre class="overflow-auto">{{ json_encode($detail['decisions'],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) }}</pre>@endif
            @if($detail['assessment'])<pre class="overflow-auto">{{ json_encode($detail['assessment'],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) }}</pre><a href="/admin/review-center?novel={{ $novel }}">查看完整章节评审与证据</a>@endif
        @endif
    </section>
    <section class="space-y-3 border rounded p-4">
        <h2 class="font-bold">明确离线补丁与新正文复评</h2><x-filament::button wire:click="confirm">人工确认已达标的非阻塞文学候选</x-filament::button><label class="block">补丁响应 JSON <textarea class="w-full" rows="6" wire:model="patchJson"></textarea></label><x-filament::button wire:click="propose">仅验证并保存离线提案</x-filament::button>
        <details><summary>通过预算与离线路由生成提案</summary><label>路由版本 <input wire:model="route"></label><label>预算策略 <input wire:model="budget"></label><label>报价版本（逗号分隔）<input wire:model="prices"></label><x-filament::button wire:click="startPipeline">冻结离线运行</x-filament::button><label>运行 ID <input wire:model="run"></label><x-filament::button wire:click="replayPipeline">执行离线回放</x-filament::button></details>
        <p>采纳后必须针对新正文重新定位证据，重新取证并评审完整章节衔接，不能复用旧引文。</p>
        <label class="block">新正文提取响应 JSON <textarea class="w-full" rows="6" wire:model="extractionJson"></textarea></label><label class="block">完整章节七维复评响应 JSON <textarea class="w-full" rows="6" wire:model="reviewJson"></textarea></label><label>复评者 <select wire:model="reviewer"><option value="offline">明确离线响应</option><option value="human">本人阅读后的人工评分</option></select></label><x-filament::button wire:click="assess">重新取证并完整章节复评</x-filament::button>
    </section>
</x-filament-panels::page>
