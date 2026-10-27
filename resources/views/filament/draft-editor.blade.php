<x-filament-panels::page class="nf-story-screen nf-production-screen"><div class="nf-production-layout">
    @include('filament.novel-navigation', ['novelId' => $novelId])
@php($pageRead=$this->productionReadFrame(['listing']))
@if(!$pageRead['ok'])<x-nf.state state="error" title="资料读取失败"><p>{{ $pageRead['message'] }}</p><div class="nf-story-toolbar"><x-filament::button color="gray" wire:click="$refresh">重新读取</x-filament::button><x-filament::button color="gray" wire:click="resetProductionFilters">重置筛选</x-filament::button></div></x-nf.state>
@else
<div class="nf-story-toolbar"><x-filament::button color="gray" wire:click="newProductionOperation" wire:confirm="保留当前字段并开始新一轮操作？相同输入将不再作为上一次操作的重试。">开始新一轮操作</x-filament::button><span>相同输入重复保存复用原记录；新一轮请先明确开始。</span></div>
<div wire:loading role="status">正在读取或保存，请稍候…</div>
@if($errors->any())<x-nf.state state="error" title="请核对字段，输入仍保留"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-nf.state>@endif
    {{-- 当前正式映射与候选缓冲分别展示，历史PASS不代表当前提交资格。 --}}
    <p>候选编辑只追加新版本。正式正文在独立只读区查看；已存评审须另行核对当前资格，规则检查不等于评审或人工验收。</p>
    @php($listing = $pageRead['data']['listing'])
    @if($notice)<p role="status">{{ $notice }}</p>@endif
    <x-nf.section heading="章节与场景"><div class="nf-production-layout">
        <x-nf.story-select id="ui07-draft-editor-1-{{ md5('status') }}" label="状态" model="status" :value="data_get($__livewire,'status')" ><option value="">全部</option>@foreach(['planned','drafting','revision_required','stale','committed'] as $value)<option>{{ $value }}</option>@endforeach</x-nf.story-select>
        <label class="nf-story-check"><input type="checkbox" wire:model.live="missing"> 缺章</label>
        <label class="nf-story-check"><input type="checkbox" wire:model.live="wordIssue"> 字数不达标</label>
        <x-nf.table label="生产资料列表"><table class="w-full"><thead><tr><th>章序 / 标题</th><th>候选版本 / 字数</th><th>状态</th><th>正式 / 评审 / 人工验收</th></tr></thead><tbody>
        @foreach($listing['items'] as $ch)
            <tr><td>{{ $ch['sequence'] }} / {{ $ch['title'] }}</td><td>{{ $ch['candidate']['id'] ?? '无' }} / {{ $ch['candidate']['character_count'] ?? 0 }}</td><td>{{ $ch['status'] }}</td><td>正式版本 {{ $ch['formal']['id'] ?? '无' }} @if($ch['formal']) / v{{ $ch['formal']['version'] }} / {{ $ch['formal']['committed_at'] }} @endif<br>候选状态 {{ $ch['candidate_status'] }} / 已存评审 {{ $ch['candidate']['recorded_review']['verdict'] ?? '无' }}（当前资格需核对） / 未人工验收</td></tr>
            @foreach($ch['scenes'] as $sc)<tr><td>场景 {{ $sc['id'] }} {{ $sc['title'] }}</td><td>{{ $sc['candidate']['id'] ?? '无' }} / {{ $sc['candidate']['character_count'] ?? 0 }}</td><td>{{ $sc['candidate']['display_status'] ?? 'planned' }}</td><td></td></tr>@endforeach
        @endforeach
        </tbody></table></x-nf.table>
    </div></x-nf.section>
    <x-nf.section heading="编辑与比较"><div class="nf-production-layout">
        <x-nf.story-select id="ui07-draft-editor-2-{{ md5('kind') }}" label="类型" model="kind" :value="data_get($__livewire,'kind')" ><option value="chapter">章节</option><option value="scene">场景</option></x-nf.story-select>
        @include('filament.production-reference',['label'=>'版本 ','model'=>'selected','kind'=>$kind==='scene'?'scene_version':'chapter_version','multiple'=>false])@include('filament.production-reference',['label'=>'比较旧版本 ','model'=>'comparison','kind'=>$kind==='scene'?'scene_version':'chapter_version','multiple'=>false])
        <x-filament::button wire:loading.attr="disabled" wire:click="inspect" :wire:confirm="($body!==$savedBody || $bodyTitle!==$savedTitle)?'读取将替换未保存的候选缓冲，确认继续？':null">读取版本</x-filament::button>
        @if($detail)
            <p>当前载入：{{ $loadedKind }}正文版本 #{{ $detail['id'] }}。上方选择只有点击读取后才替换此缓冲。</p>
            <p>版本历史：@foreach($detail['history'] as $v) #{{ $v['id'] }}（v{{ $v['version'] }}） @endforeach</p>
            <div x-data="{ dirty: false }" x-on:input="dirty = true" x-on:draft-saved.window="dirty = false" x-on:beforeunload.window="if (dirty) { $event.preventDefault(); $event.returnValue = ''; }" wire:poll.15s="autoSave">
                <x-nf.field id="ui07-draft-editor-5-{{ md5('bodyTitle') }}" label="具体标题" :error="$errors->first('bodyTitle')"><input id="ui07-draft-editor-5-{{ md5('bodyTitle') }}" wire:model.live.debounce.500ms="bodyTitle"></x-nf.field>
                <x-nf.field id="ui07-draft-editor-6-{{ md5('body') }}" label="候选正文" :error="$errors->first('body')"><textarea id="ui07-draft-editor-6-{{ md5('body') }}" class="w-full" rows="18" wire:model.live.debounce.1000ms="body"></textarea></x-nf.field>
                <p>可见码点：{{ $this->count() }}；{{ ($body !== $savedBody || $bodyTitle !== $savedTitle) ? '尚未保存（每15秒尝试自动保存）' : '候选已保存' }}</p>
                <p>哈希 {{ $detail['body_hash'] }}；来源 {{ $detail['parent_version_id'] ?? '初始' }}；{{ implode('、', $detail['report']['issues']) ?: '规则检查无问题，评审资格需另行核对' }}</p>
                <x-filament::button wire:loading.attr="disabled" wire:click="save">保存为新候选版本</x-filament::button>
            </div>
            @if($previous)<h3>旧版本 v{{ $previous['version'] }}（与上方完整当前稿比较）</h3><div class="nf-manuscript" tabindex="0">{{ $previous['body'] }}</div>@endif
        @endif
    </div></x-nf.section>
    <x-nf.section heading="正式正文只读区"><div class="nf-production-layout">
        @if($formalDetail)
            <p>正式版本 #{{ $formalDetail['id'] }} / v{{ $formalDetail['version'] }}，基线 #{{ $formalDetail['revision_id'] }}，提交 #{{ $formalDetail['commit_id'] }} / {{ $formalDetail['committed_at'] }}</p>
            <h3>{{ $formalDetail['title'] }}</h3><div class="nf-manuscript" tabindex="0">{{ $formalDetail['body'] }}</div>
        @else
            <p>请选择已提交的章节查看正式正文；当前选择没有正式章快照，不以候选填充。</p>
        @endif
    </div></x-nf.section>
    @php($options = $this->options())
    <x-nf.section heading="场景离线生成验收"><div class="nf-production-layout">
        <p>显式回放提供的离线正文，不调用真实模型。每次只处理一个场景；前场景未完成时不能跳过。</p>
        <x-nf.story-select id="ui07-draft-editor-7-{{ md5('plan') }}" label="冻结计划" model="plan" :value="data_get($__livewire,'plan')" ><option value="">请选择</option>@foreach($options['plans'] as $v)<option value="{{ $v->id }}">{{ $v->id }} / v{{ $v->version }}</option>@endforeach</x-nf.story-select>
        @include('filament.production-reference',['label'=>'场景 ','model'=>'scene','kind'=>'scene','multiple'=>false])
        <x-nf.story-select id="ui07-draft-editor-9-{{ md5('pov') }}" label="POV" model="pov" :value="data_get($__livewire,'pov')" ><option value="">请选择</option>@foreach($options['povs'] as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach</x-nf.story-select>
        <x-nf.story-select id="ui07-draft-editor-10-{{ md5('route') }}" label="离线路由" model="route" :value="data_get($__livewire,'route')" ><option value="">请选择</option>@foreach($options['routes'] as $v)<option value="{{ $v->id }}">{{ $v->key }}</option>@endforeach</x-nf.story-select>
        <x-nf.story-select id="ui07-draft-editor-11-{{ md5('policy') }}" label="预算政策" model="policy" :value="data_get($__livewire,'policy')" ><option value="">请选择</option>@foreach($options['policies'] as $v)<option value="{{ $v->id }}">{{ $v->key }}</option>@endforeach</x-nf.story-select>
        @include('filament.production-reference',['label'=>'报价版本 ','model'=>'prices','kind'=>'price','multiple'=>true])
        <x-nf.field id="ui07-draft-editor-13-{{ md5('offlineBody') }}" label="离线完整场景正文" :error="$errors->first('offlineBody')"><textarea id="ui07-draft-editor-13-{{ md5('offlineBody') }}" class="w-full" rows="8" wire:model="offlineBody"></textarea></x-nf.field>
        <x-nf.field id="ui07-draft-editor-14-{{ md5('reason') }}" label="备注 / 原因" :error="$errors->first('reason')"><input id="ui07-draft-editor-14-{{ md5('reason') }}" wire:model="reason"></x-nf.field>
        <x-filament::button wire:loading.attr="disabled" wire:click="startScene">冻结并启动离线场景</x-filament::button>
        @include('filament.production-reference',['label'=>'运行 ','model'=>'run','kind'=>'run','multiple'=>false])<x-filament::button wire:loading.attr="disabled" wire:click="replay">执行离线回放</x-filament::button>
        @if(ctype_digit($run))<a href="/admin/recovery-desk?novel={{ $novelId }}&run={{ $run }}">查看恢复与证据</a>@endif
        <h3>章节组装</h3>@include('filament.production-reference',['label'=>'章节 ','model'=>'chapter','kind'=>'chapter','multiple'=>false])<x-nf.field id="ui07-draft-editor-17-{{ md5('assemblyTitle') }}" label="具体章名" :error="$errors->first('assemblyTitle')"><input id="ui07-draft-editor-17-{{ md5('assemblyTitle') }}" wire:model="assemblyTitle"></x-nf.field>
        <x-filament::button wire:loading.attr="disabled" wire:click="assemble">按当前场景版本完整组装候选章</x-filament::button>
    </div></x-nf.section>
@endif
</div></x-filament-panels::page>
