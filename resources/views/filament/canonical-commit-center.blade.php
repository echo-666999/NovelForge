<x-filament-panels::page class="nf-story-screen nf-production-screen"><div class="nf-production-layout">
    @include('filament.book-navigation', ['navigation' => $this->bookNavigation()])
@php($pageRead=($novel===''?['ok'=>true,'data'=>[]]:$this->productionReadFrame(['state'])))
@if(!$pageRead['ok'])<x-nf.state state="error" title="资料读取失败"><p>{{ $pageRead['message'] }}</p><div class="nf-story-toolbar"><x-filament::button color="gray" wire:click="$refresh">重新读取</x-filament::button><x-filament::button color="gray" wire:click="resetProductionFilters">重置筛选</x-filament::button></div></x-nf.state>
@else
<div wire:loading role="status">正在读取或保存，请稍候…</div>
@if($errors->any())<x-nf.state state="error" title="请核对字段，输入仍保留"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-nf.state>@endif
    {{-- 正式状态来自提交回执，预检与模型评分不能代替提交。 --}}
    <p>顺序提交完整章节。预检、正式提交与作者成品认可分别记录；此页没有强制通过或修改正式历史的入口。</p>

    @if($novel==='')<p>请选择小说查看基线、预检与提交记录。</p>@else
    @php($state=$pageRead['data']['state'])
    @if(!$state['revision'])<p>尚未初始化正式基线。空根初始化不会产生正文、事件或推进剧情版本。</p><x-filament::button wire:loading.attr="disabled" wire:click="initialize" wire:confirm="确认建立空根正式基线？不会产生正文或事件。">初始化空根基线</x-filament::button>
    @else<p>正式基线 #{{ $state['revision']['id'] }} / 连续提交至第{{ $state['revision']['cursor'] }}章 / 状态版本{{ $state['state_version'] }} / 待处理记忆意图{{ $state['outbox_pending'] }}</p>@endif
    <x-nf.section heading="章节提交预检"><div class="nf-production-layout">
        <x-nf.story-select id="ui07-canonical-commit-center-1-{{ md5('candidate') }}" label="候选版本" model="candidate" :value="data_get($__livewire,'candidate')" ><option value="">请选择</option>@foreach($this->candidates() as $v)<option value="{{ $v->id }}">{{ $v->title }} / V{{ $v->version }} / #{{ $v->id }}</option>@endforeach</x-nf.story-select><x-filament::button wire:loading.attr="disabled" wire:click="loadCandidate">载入候选与当前依赖</x-filament::button>
        @if($form)<div class="nf-story-fields">
        @foreach(['chapter_id'=>'章节ID','chapter_version_id'=>'正文版本','expected_revision_id'=>'预期正式基线','expected_state_version'=>'预期状态版本','review_id'=>'有效评审ID','event_artifact_id'=>'E03事件产物ID','plan_node_version_id'=>'冻结章节节点ID','reason'=>'提交理由'] as $key=>$label)<x-nf.field id="ui07-canonical-commit-center-2-{{ md5('form.'.($key)) }}" label="{{ $label }}" :error="$errors->first('form.'.($key))"><x-nf.control id="ui07-canonical-commit-center-2-{{ md5('form.'.($key)) }}" wire:model="form.{{ $key }}" @readonly($key!=='reason') /></x-nf.field>@endforeach</div>
        <x-filament::button wire:loading.attr="disabled" wire:click="check">预检</x-filament::button><x-filament::button wire:loading.attr="disabled" :disabled="!$preflight || $preflight['status']!=='ready'" wire:click="submit" wire:confirm="确认提交当前已载入并预检的完整章节？提交不会被候选评分替代。">提交本章</x-filament::button>
        <p>当前请求键：{{ $commandKey }}。重复提交返回原回执；依赖变化需重新载入、预检或复评。</p>@endif
        @if($preflight)<p>预检 #{{ $preflight['id'] }}：{{ $preflight['status'] }} / {{ $preflight['message'] }}</p>@if($preflight['status']==='ready')<p>事件{{ $preflight['event_count'] }}条 / 正文哈希{{ $preflight['body_hash'] }}</p><details wire:ignore.self><summary>拟提交状态变化（尚未正式生效）</summary>@include('filament.value-table',['value'=>$preflight['candidate_state'],'label'=>'逐字段只读证据'])</details>@else<p>{{ $preflight['code'] }}</p>@endif @endif
    </div></x-nf.section>
    <p role="status">{{ $notice }}</p>
    <div class="nf-story-toolbar">@include('filament.production-reference',['label'=>'章','model'=>'chapterFilter','kind'=>'chapter','multiple'=>false])@include('filament.production-reference',['label'=>'基线','model'=>'revisionFilter','kind'=>'revision','multiple'=>false])<x-nf.story-select id="ui07-canonical-commit-center-5-{{ md5('statusFilter') }}" label="状态" model="statusFilter" :value="data_get($__livewire,'statusFilter')" ><option value="">全部</option><option value="ready">预检通过</option><option value="blocked">预检阻塞</option><option value="committed">已正式提交</option></x-nf.story-select><x-nf.field id="ui07-canonical-commit-center-6-{{ md5('errorFilter') }}" label="预检错误码" :error="$errors->first('errorFilter')"><x-nf.control id="ui07-canonical-commit-center-6-{{ md5('errorFilter') }}" wire:model.live="errorFilter" /></x-nf.field><x-nf.field id="ui07-canonical-commit-center-7-{{ md5('from') }}" label="开始日期" :error="$errors->first('from')"><x-nf.control id="ui07-canonical-commit-center-7-{{ md5('from') }}" type="date" wire:model.live="from" /></x-nf.field><x-nf.field id="ui07-canonical-commit-center-8-{{ md5('to') }}" label="结束日期" :error="$errors->first('to')"><x-nf.control id="ui07-canonical-commit-center-8-{{ md5('to') }}" type="date" wire:model.live="to" /></x-nf.field></div>
    <p>第{{ $state['page'] }}页，每页最多50项；正式提交{{ $state['commits_total'] }}项，预检{{ $state['preflights_total'] }}项。</p><x-filament::button wire:loading.attr="disabled" wire:click="newer">上一页</x-filament::button><x-filament::button wire:loading.attr="disabled" wire:click="older">下一页</x-filament::button>
    <x-nf.section heading="正式提交历史"><div class="nf-production-layout">
        <x-nf.table label="生产资料列表"><table><thead><tr><th>章序 / 正文</th><th>状态版本</th><th>幂等键 / 操作者</th><th>时间 / 原因</th><th>动作</th></tr></thead><tbody>@forelse($state['commits'] as $c)<tr><td>第{{ $c['chapter_sequence'] }}章 / #{{ $c['chapter_version_id'] }}</td><td>{{ $c['previous_state_version'] }} → {{ $c['new_state_version'] }}</td><td>{{ $c['idempotency_key'] }} / {{ $c['actor_id'] }}</td><td>{{ $c['created_at'] }} / {{ $c['reason'] }}</td><td><x-filament::button wire:loading.attr="disabled" wire:click="inspect('{{ $c['id'] }}')">查看回执与正式状态</x-filament::button></td></tr>@empty<tr><td>尚无正式提交。候选正文和评审通过不会自动出现在这里。</td></tr>@endforelse</tbody></table></x-nf.table>
    </div></x-nf.section>
    <x-nf.section heading="预检与阻塞记录"><div class="nf-production-layout">@forelse($state['preflights'] as $p)<p>#{{ $p['id'] }} / {{ $p['created_at'] }} / {{ $p['status'] }} / {{ $p['code']??'' }} / {{ $p['message'] }}</p>@empty<p>暂无符合条件的预检记录。</p>@endforelse</div></x-nf.section>
    @if($receipt)<x-nf.section heading="正式回执"><div class="nf-production-layout">@include('filament.value-table',['value'=>$receipt,'label'=>'逐字段只读证据'])</div></x-nf.section>@endif
    <p>历史修订、候选基线回放与切换由G01提供；提交后记忆索引任务请从正式记忆与索引中心查看。</p>
    @endif
@endif
</div></x-filament-panels::page>
