<x-filament-panels::page class="nf-story-screen nf-production-screen"><div class="nf-production-layout">
    @include('filament.book-navigation', ['navigation' => $this->bookNavigation()])
@php($pageRead=$this->productionReadFrame(['listing']))
@if(!$pageRead['ok'])<x-nf.state state="error" title="资料读取失败"><p>{{ $pageRead['message'] }}</p><div class="nf-story-toolbar"><x-filament::button color="gray" wire:click="$refresh">重新读取</x-filament::button><x-filament::button color="gray" wire:click="resetProductionFilters">重置筛选</x-filament::button></div></x-nf.state>
@else
<div class="nf-story-toolbar"><x-filament::button color="gray" wire:click="newProductionOperation" wire:confirm="保留当前字段并开始新一轮操作？相同输入将不再作为上一次操作的重试。">开始新一轮操作</x-filament::button><span>相同输入重复保存复用原记录；新一轮请先明确开始。</span></div>
<div wire:loading role="status">正在读取或保存，请稍候…</div>
@if($errors->any())<x-nf.state state="error" title="请核对字段，输入仍保留"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-nf.state>@endif
    {{-- 明确冻结范围、选择补丁和重新复评，不把修订采纳当作正式提交。 --}}
    <p role="status">{{ $notice }}</p>
    <p>补丁不会自动采纳；默认最多三轮自动文学修订。无改善、预算不足或达到上限转人工，旧稿与旧失败保留。</p>
    <x-nf.section heading="选择问题与允许范围"><div class="nf-production-layout">
        @include('filament.production-reference',['label'=>'来源评审','model'=>'review','kind'=>'review','multiple'=>false])<x-filament::button wire:loading.attr="disabled" wire:click="prepare">读取评审并准备首段范围</x-filament::button>
        @include('filament.production-reference',['label'=>'问题 ','model'=>'issues','kind'=>'review_issue','multiple'=>true])<x-nf.story-select id="ui07-revision-desk-3-{{ md5('mode') }}" label="方式" model="mode" :value="data_get($__livewire,'mode')" ><option value="offline">明确离线自动补丁</option><option value="manual">人工编辑</option></x-nf.story-select>
        @include('filament.production-editor',['property'=>'scopeJson','label'=>'允许范围'])
        @include('filament.production-reference',['label'=>'额外保留事件 ','model'=>'facts','kind'=>'event','multiple'=>true])<p>已提取的事实、知识/因果关系和伏笔会自动纳入保留门禁，不能通过空列表省略。</p>
        @include('filament.production-editor',['property'=>'protectedJson','label'=>'额外保留引文'])<x-nf.field id="ui07-revision-desk-7-{{ md5('reason') }}" label="操作原因" :error="$errors->first('reason')"><x-nf.control tag="textarea" id="ui07-revision-desk-7-{{ md5('reason') }}" class="w-full" wire:model="reason"></x-nf.control></x-nf.field>
        <x-filament::button wire:loading.attr="disabled" wire:click="createTask">冻结修订任务</x-filament::button>
    </div></x-nf.section>
    <x-nf.section heading="修订历史与候选差异"><div class="nf-production-layout">
        <ul>@foreach($pageRead['data']['listing'] as $t)<li>{{ $t['id'] }} · 正文 {{ $t['source_version_id'] }} · {{ $t['display_status'] }} · 自动轮次 {{ $t['automatic_round'] }}/{{ $t['max_rounds'] }}</li>@endforeach</ul>
        @include('filament.production-reference',['label'=>'任务 ','model'=>'selected','kind'=>'revision_task','multiple'=>false])<x-filament::button wire:loading.attr="disabled" wire:click="inspect">查看问题、原文与差异</x-filament::button>
        @if($detail)
            <p>状态 {{ $detail['display_status'] }}；自动轮次 {{ $detail['automatic_round'] }}/{{ $detail['max_rounds'] }}。与技术重试次数分别计数。</p>
            <details open wire:ignore.self><summary>冻结原文、问题与保留事项</summary><div class="nf-manuscript" tabindex="0">{{ $detail['input']['source']['body'] }}</div>@include('filament.value-table',['value'=>['issues'=>$detail['input']['issues'],'scope'=>$detail['input']['allowed_scope'],'protected'=>$detail['input']['protected'],'facts'=>$detail['input']['preserve_facts']],'label'=>'逐字段只读证据'])</details>
            @foreach($detail['proposals'] as $p)<article class="border p-3"><p>提案 {{ $p['id'] }} · {{ $p['report']['valid'] ? '范围校验通过，待明确选择' : '校验失败，转人工' }}</p>
            @foreach($p['output']['patches']??[] as $patch) @if(is_array($patch))<label class="nf-story-check"><input type="checkbox" wire:model="patchKeys" value="{{ $patch['key']??'' }}">{{ $patch['key']??'' }} · {{ $patch['start']??'' }}–{{ $patch['end']??'' }}</label><del>{{ $patch['quote']??'' }}</del><div class="nf-manuscript" tabindex="0">{{ $patch['replacement']??'' }}</div><p>{{ $patch['reason']??'' }}</p>@endif @endforeach
            @include('filament.value-table',['value'=>$p['report'],'label'=>'逐字段只读证据'])</article>@endforeach
            @include('filament.production-reference',['label'=>'提案 ','model'=>'proposal','kind'=>'proposal','multiple'=>false])<x-filament::button wire:loading.attr="disabled" wire:click="decide('accept')">只采纳选中补丁</x-filament::button><x-filament::button wire:loading.attr="disabled" wire:click="decide('reject')">拒绝本轮提案</x-filament::button><x-filament::button wire:loading.attr="disabled" wire:click="decide('abandon')">放弃本轮</x-filament::button>
            <details wire:ignore.self><summary>手工编辑冻结范围</summary><x-nf.field id="ui07-revision-desk-10-{{ md5('range') }}" label="范围序号（从0开始）" :error="$errors->first('range')"><x-nf.control id="ui07-revision-desk-10-{{ md5('range') }}" type="number" min="0" wire:model="range" /></x-nf.field><x-filament::button wire:loading.attr="disabled" wire:click="loadManual">载入允许范围原文</x-filament::button><x-nf.field id="ui07-manual-text" label="冻结范围内的人工候选正文"><x-nf.control tag="textarea" id="ui07-manual-text" rows="8" wire:model="manualText"></x-nf.control></x-nf.field><x-filament::button wire:loading.attr="disabled" wire:click="decide('manual')">保存人工编辑候选</x-filament::button></details>
            @if($detail['decision'])@include('filament.value-table',['value'=>$detail['decisions'],'label'=>'逐字段只读证据'])@endif
            @if($detail['assessment'])@include('filament.value-table',['value'=>$detail['assessment'],'label'=>'逐字段只读证据'])<a href="/admin/review-center?novel={{ $novel }}">查看完整章节评审与证据</a>@endif
        @endif
    </div></x-nf.section>
    <x-nf.section heading="明确离线补丁与新正文复评"><div class="nf-production-layout"><x-filament::button wire:loading.attr="disabled" wire:click="confirm">人工确认已达标的非阻塞文学候选</x-filament::button>@include('filament.production-editor',['property'=>'patchJson','label'=>'有序修订补丁'])<x-filament::button wire:loading.attr="disabled" wire:click="propose">仅验证并保存离线提案</x-filament::button>
        <details wire:ignore.self><summary>通过预算与离线路由生成提案</summary>@include('filament.production-reference',['label'=>'路由版本','model'=>'route','kind'=>'route','multiple'=>false])@include('filament.production-reference',['label'=>'预算策略','model'=>'budget','kind'=>'budget','multiple'=>false])@include('filament.production-reference',['label'=>'报价版本','model'=>'prices','kind'=>'price','multiple'=>true])<x-filament::button wire:loading.attr="disabled" wire:click="startPipeline">冻结离线运行</x-filament::button>@include('filament.production-reference',['label'=>'运行 ','model'=>'run','kind'=>'run','multiple'=>false])<x-filament::button wire:loading.attr="disabled" wire:click="replayPipeline">执行离线回放</x-filament::button></details>
        <p>采纳后必须针对新正文重新定位证据，重新取证并评审完整章节衔接，不能复用旧引文。</p>
        @include('filament.production-editor',['property'=>'extractionJson','label'=>'新正文候选提取'])@include('filament.production-editor',['property'=>'reviewJson','label'=>'完整章节复评'])<x-nf.story-select id="ui07-revision-desk-18-{{ md5('reviewer') }}" label="复评者" model="reviewer" :value="data_get($__livewire,'reviewer')" ><option value="offline">明确离线响应</option><option value="human">本人阅读后的人工评分</option></x-nf.story-select><x-filament::button wire:loading.attr="disabled" wire:click="assess">重新取证并完整章节复评</x-filament::button>
    </div></x-nf.section>
@endif
</div></x-filament-panels::page>
