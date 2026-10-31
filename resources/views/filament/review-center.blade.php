<x-filament-panels::page class="nf-story-screen nf-production-screen"><div class="nf-production-layout">
    @include('filament.book-navigation', ['navigation' => $this->bookNavigation()])
@php($pageRead=$this->productionReadFrame(['listing','policies']))
@if(!$pageRead['ok'])<x-nf.state state="error" title="资料读取失败"><p>{{ $pageRead['message'] }}</p><div class="nf-story-toolbar"><x-filament::button color="gray" wire:click="$refresh">重新读取</x-filament::button><x-filament::button color="gray" wire:click="resetProductionFilters">重置筛选</x-filament::button></div></x-nf.state>
@else
<div class="nf-story-toolbar"><x-filament::button color="gray" wire:click="newProductionOperation" wire:confirm="保留当前字段并开始新一轮操作？相同输入将不再作为上一次操作的重试。">开始新一轮操作</x-filament::button><span>相同输入重复保存复用原记录；新一轮请先明确开始。</span></div>
<div wire:loading role="status">正在读取或保存，请稍候…</div>
@if($errors->any())<x-nf.state state="error" title="请核对字段，输入仍保留"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-nf.state>@endif
    {{-- E04评审和策略共享服务鉴权，页面不提供修改正文或强制通过动作。 --}}
    <p>离线评分只决定候选资格。自然度需人工阅读评分确认，不以 AI 检测器概率验收。</p>
    <p role="status">{{ $notice }}</p>
    <x-nf.section heading="正文评审与人工评分"><div class="nf-production-layout">

        @include('filament.production-reference',['label'=>'正文版本','model'=>'version','kind'=>'chapter_version','multiple'=>false])@include('filament.production-reference',['label'=>'本章提取记录','model'=>'extraction','kind'=>'extraction','multiple'=>false])
        @include('filament.production-reference',['label'=>'评审策略版本','model'=>'policy','kind'=>'policy','multiple'=>false])
        <x-nf.story-select id="ui07-review-center-4-{{ md5('reviewer') }}" label="评审者" model="reviewer" :value="data_get($__livewire,'reviewer')" ><option value="offline">明确离线响应</option><option value="human">本人阅读后的人工评分</option></x-nf.story-select>
        <x-filament::button color="gray" wire:click="loadReviewSource" :disabled="$version===''">读取本章原文供评分</x-filament::button>
        @if($reviewSource && $reviewSource['id']===$version && $reviewSource['novel_id']===$novel)<details open wire:ignore.self><summary>当前评分正文 · v{{ $reviewSource['version'] }} · {{ $reviewSource['body_hash'] }}</summary><div class="nf-manuscript" tabindex="0">{{ $reviewSource['body'] }}</div></details>@endif
        @include('filament.production-editor',['property'=>'output','label'=>'七维评分与问题'])
        <details wire:ignore.self><summary>响应格式</summary>@include('filament.value-table',['value'=>\App\Review\ReviewContract::schema(),'label'=>'逐字段只读证据'])</details>
        <x-nf.field id="ui07-review-center-6-{{ md5('reason') }}" label="操作原因" :error="$errors->first('reason')"><x-nf.control tag="textarea" id="ui07-review-center-6-{{ md5('reason') }}" class="w-full" wire:model="reason"></x-nf.control></x-nf.field>
        <x-filament::button wire:loading.attr="disabled" wire:click="review">保存评分并验证</x-filament::button>
        <details wire:ignore.self><summary>通过模型路由评审</summary>
            @include('filament.production-reference',['label'=>'路由版本','model'=>'route','kind'=>'route','multiple'=>false])@include('filament.production-reference',['label'=>'预算策略','model'=>'budget','kind'=>'budget','multiple'=>false])@include('filament.production-reference',['label'=>'报价版本','model'=>'prices','kind'=>'price','multiple'=>true])
            @include('filament.stage-execution-mode')
<x-filament::button wire:loading.attr="disabled" wire:click="startPipeline">冻结并启动评审</x-filament::button>
            @include('filament.production-reference',['label'=>'运行 ','model'=>'run','kind'=>'run','multiple'=>false])<x-filament::button wire:loading.attr="disabled" wire:click="replayPipeline">执行离线回放</x-filament::button>
        </details>
    </div></x-nf.section>
    <x-nf.section heading="评审历史"><div class="nf-production-layout">
        <x-nf.story-select id="ui07-review-center-11-{{ md5('verdict') }}" label="结论" model="verdict" :value="data_get($__livewire,'verdict')" ><option value="">全部</option><option value="pass">PASS</option><option value="block">BLOCK</option><option value="stale">已过期</option></x-nf.story-select>
        <x-nf.story-select id="ui07-review-center-12-{{ md5('reviewerFilter') }}" label="评审者" model="reviewerFilter" :value="data_get($__livewire,'reviewerFilter')" ><option value="">全部</option><option value="offline">离线</option><option value="human">人工</option></x-nf.story-select>
        <x-nf.table label="生产资料列表"><table class="w-full"><thead><tr><th>记录 / 正文</th><th>评审者 / 结果</th><th>硬门禁 / 平均分 / 有效性</th></tr></thead><tbody>
        @foreach($pageRead['data']['listing'] as $r)<tr><td>{{ $r['id'] }} / {{ $r['chapter_version_id'] }}</td><td>{{ $r['reviewer_type'] }} / {{ $r['report']['verdict'] }}</td><td>{{ $r['report']['hard_pass'] ? '通过' : '阻塞' }} / {{ $r['report']['average'] ?? '不可用' }} / {{ $r['stale'] ? '已过期，不可作为提交依据' : '当前' }}</td></tr>@endforeach
        </tbody></table></x-nf.table>
        @include('filament.production-reference',['label'=>'查看记录','model'=>'selected','kind'=>'review','multiple'=>false])<x-filament::button wire:loading.attr="disabled" wire:click="inspect">查看原文、评分与证据</x-filament::button>
        @if($detail)
            <p>正文哈希 {{ $detail['body_hash'] }}；上下文 {{ $detail['context_hash'] }}；状态 {{ $detail['display_status'] }}</p>
            @if($detail['stale'])<p>正文、提取或上下文已改变，旧评审失效。</p>@endif
            <div class="nf-manuscript" tabindex="0">{{ $detail['frozen_input']['context']['body'] }}</div>
            <x-nf.table label="生产资料列表"><table class="w-full"><thead><tr><th>维度</th><th>评分</th><th>理由与原句</th></tr></thead><tbody>@foreach($detail['report']['scores'] as $dim=>$score)<tr><td>{{ \App\Review\ReviewContract::LABELS[$dim] }}</td><td>{{ $score['score'] }}</td><td>{{ $score['reason'] }}：「{{ $score['evidence']['quote'] }}」</td></tr>@endforeach</tbody></table></x-nf.table>
            <x-nf.story-select id="ui07-review-center-14-{{ md5('severity') }}" label="严重度" model="severity" :value="data_get($__livewire,'severity')" ><option value="">全部</option><option value="blocking">阻塞</option><option value="warning">警告</option><option value="info">提示</option></x-nf.story-select><x-nf.field id="ui07-review-center-15-{{ md5('category') }}" label="类型" :error="$errors->first('category')"><x-nf.control id="ui07-review-center-15-{{ md5('category') }}" wire:model.live="category" /></x-nf.field>
            @foreach($this->issues() as $i)<article class="rounded border p-3"><p>{{ $i['id'] }} · {{ $i['category'] }} / {{ $i['severity'] }}：{{ $i['message'] }}</p><p>位置 {{ $i['start_offset'] }}–{{ $i['end_offset'] }}：「{{ $i['quote'] }}」</p><p>{{ $i['suggested_fix'] }}</p>@include('filament.value-table',['value'=>$i['details'],'label'=>'逐字段只读证据'])@if($i['decision'])<p>人工意见：{{ $i['decision']->decision }}，{{ $i['decision']->reason }}</p>@endif</article>@endforeach
            @include('filament.production-reference',['label'=>'非阻塞问题 ','model'=>'issue','kind'=>'review_issue','multiple'=>false])<x-nf.story-select id="ui07-review-center-17-{{ md5('decision') }}" label="意见" model="decision" :value="data_get($__livewire,'decision')" ><option value="accepted">接受此文学提示</option><option value="rejected">拒绝此文学提示</option></x-nf.story-select><x-filament::button wire:loading.attr="disabled" wire:click="decide">追加人工意见</x-filament::button>
            <p>本页只维护评审，受限修订请进入修订工作台。正文修改后需要重新提取并重新评审，历史记录保留。</p>
        @endif
    </div></x-nf.section>
    <x-nf.section heading="文风与评审策略版本"><div class="nf-production-layout"><p>文风复用正文生成的同一版本；阈值降低须明确新版本与原因，不能改变已有结果。规则仅作提示，不自动删改文学表达。</p>
        <ul>@foreach($pageRead['data']['policies'] as $p)<li>{{ $p['id'] }} · {{ $p['key'] }} v{{ $p['version'] }} · {{ $p['status'] }} · 文风 {{ $p['style_version_id'] }}</li>@endforeach</ul>
        <x-nf.field id="ui07-review-center-18-{{ md5('policyKey') }}" label="策略键" :error="$errors->first('policyKey')"><x-nf.control id="ui07-review-center-18-{{ md5('policyKey') }}" wire:model="policyKey" /></x-nf.field><x-nf.field id="ui07-review-center-19-{{ md5('policyVersion') }}" label="预期版本" :error="$errors->first('policyVersion')"><x-nf.control id="ui07-review-center-19-{{ md5('policyVersion') }}" type="number" min="0" wire:model="policyVersion" /></x-nf.field>@include('filament.production-reference',['label'=>'正文文风版本','model'=>'style','kind'=>'style','multiple'=>false])
        @include('filament.production-editor',['property'=>'policyJson','label'=>'评审策略字段'])
        <x-filament::button wire:loading.attr="disabled" wire:click="policyAction('save')">追加策略草稿</x-filament::button><x-filament::button wire:loading.attr="disabled" wire:click="policyAction('publish')">发布选中策略</x-filament::button><x-filament::button wire:loading.attr="disabled" wire:click="policyAction('retire')">退役选中策略</x-filament::button>
        @include('filament.production-reference',['label'=>'对照策略版本','model'=>'comparePolicy','kind'=>'policy','multiple'=>false])<x-filament::button wire:loading.attr="disabled" wire:click="loadPolicy">读取或比较策略与文风</x-filament::button>
        @if($comparison)@include('filament.value-table',['value'=>$comparison,'label'=>'逐字段只读证据'])@endif
    </div></x-nf.section>
@endif
</div></x-filament-panels::page>
