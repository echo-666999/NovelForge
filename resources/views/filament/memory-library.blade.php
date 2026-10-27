<x-filament-panels::page class="nf-story-screen nf-production-screen"><div class="nf-production-layout">
    @include('filament.book-navigation', ['navigation' => $this->bookNavigation()])
@php($pageRead=$this->productionReadFrame(['listing']))
@if(!$pageRead['ok'])<x-nf.state state="error" title="资料读取失败"><p>{{ $pageRead['message'] }}</p><div class="nf-story-toolbar"><x-filament::button color="gray" wire:click="$refresh">重新读取</x-filament::button><x-filament::button color="gray" wire:click="resetProductionFilters">重置筛选</x-filament::button></div></x-nf.state>
@else
<div wire:loading role="status">正在读取或保存，请稍候…</div>
@if($errors->any())<x-nf.state state="error" title="请核对字段，输入仍保留"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-nf.state>@endif
    {{-- 来源和摘要均为不可信文本，始终转义；角色范围仅供作者核对。 --}}
    <p>候选记忆库：正式派生资料请到正式记忆与索引中心。摘要不能代替正式事实；角色范围声明不等于人物已知。索引可在检索实验室使用离线编码构建；真实嵌入与模型摘要执行未开放。</p>

    <x-nf.story-select id="ui07-memory-library-1-{{ md5('branch') }}" label="候选分支" model="branch" :value="data_get($__livewire,'branch')" ><option value="">请选择持久运行分支</option>@foreach($this->branches() as $b)<option value="{{ $b->id }}">#{{ $b->id }} / {{ $b->status }}</option>@endforeach</x-nf.story-select>
    @if($novel==='' || $branch==='')<p>请先选择小说和候选分支。没有来源时不能创建无依据摘要。</p>@else
    @php($data=$pageRead['data']['listing']) @php($options=$this->options())
    <div class="nf-story-fields">
        <x-nf.story-select id="ui07-memory-library-2-{{ md5('kind') }}" label="类别" model="kind" :value="data_get($__livewire,'kind')" ><option value="">全部</option>@foreach(['working'=>'工作','episodic'=>'情节','semantic'=>'语义','event'=>'事件'] as $k=>$label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</x-nf.story-select>
        <x-nf.story-select id="ui07-memory-library-3-{{ md5('status') }}" label="内容状态" model="status" :value="data_get($__livewire,'status')" ><option value="">全部</option>@foreach(['pending'=>'候选/非当前版本','active'=>'分支活动','stale'=>'来源失效','failed'=>'摘要校验失败'] as $k=>$label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</x-nf.story-select>
        <x-nf.story-select id="ui07-memory-library-4-{{ md5('indexStatus') }}" label="索引状态" model="indexStatus" :value="data_get($__livewire,'indexStatus')" ><option value="">全部</option><option value="not_requested">尚未请求嵌入</option><option value="pending">未活动索引</option><option value="partial">部分分块已索引</option><option value="indexed">已有活动索引</option><option value="stale">索引来源已过期</option></x-nf.story-select>
        <x-nf.field id="ui07-memory-library-5-{{ md5('chapter') }}" label="截至章位" :error="$errors->first('chapter')"><x-nf.control id="ui07-memory-library-5-{{ md5('chapter') }}" type="number" min="1" wire:model.live="chapter" /></x-nf.field><x-nf.story-select id="ui07-memory-library-6-{{ md5('character') }}" label="声明可见角色" model="character" :value="data_get($__livewire,'character')" ><option value="">全部</option>@foreach($options['characters'] as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</x-nf.story-select>
        <x-nf.field id="ui07-memory-library-7-{{ md5('page') }}" label="页码" :error="$errors->first('page')"><x-nf.control id="ui07-memory-library-7-{{ md5('page') }}" type="number" min="1" wire:model.live="page" /></x-nf.field>
    </div>
    <div><x-nf.table label="生产资料列表"><table class="w-full"><thead><tr><th>记忆/版本</th><th>类别/章位</th><th>内容状态</th><th>嵌入状态</th><th>创建时间</th><th>操作</th></tr></thead><tbody>
        @forelse($data['items'] as $m)<tr><td>{{ $m['name'] }} V{{ $m['version'] }}</td><td>{{ $m['kind'] }} / {{ $m['introduced_chapter'] }}</td><td>{{ $m['content_status'] }} @if($m['source_issue']){{ $m['source_issue']['message'] }}@endif</td><td>{{ $m['index_status'] }}</td><td>{{ $m['created_at'] }}</td><td><x-filament::button wire:loading.attr="disabled" wire:click="inspect('{{ $m['id'] }}')">原文与压缩比较</x-filament::button></td></tr>@empty<tr><td colspan="6">暂无符合条件的记忆。</td></tr>@endforelse
    </tbody></table></x-nf.table><p>共 {{ $data['total'] }} 条，每页50条。</p></div>
    <x-nf.section heading="证据事实清单"><div class="nf-production-layout">
        <p>登记需要保留的精确原文片段。清单完整性仍需作者复核；不能凭此证明摘要涵盖全部语义。</p>
        <x-nf.story-select id="ui07-memory-library-8-{{ md5('source') }}" label="来源" model="source" :value="data_get($__livewire,'source')" ><option value="">请选择</option>@foreach($options['sources'] as $s)<option value="{{ $s->id }}">来源 #{{ $s->id }}</option>@endforeach</x-nf.story-select>
        <x-nf.story-select id="ui07-memory-library-9-{{ md5('evidence') }}" label="原文证据" model="evidence" :value="data_get($__livewire,'evidence')" ><option value="">请选择</option>@foreach($options['evidence'] as $e)<option value="{{ $e->id }}">#{{ $e->id }} / 来源{{ $e->source_version_id }} / {{ mb_substr($e->quote,0,120) }}</option>@endforeach</x-nf.story-select>
        <x-nf.story-select id="ui07-memory-library-10-{{ md5('category') }}" label="事实类别" model="category" :value="data_get($__livewire,'category')" >@foreach(['irreversible'=>'不可逆变化','causal'=>'因果','knowledge'=>'知情者','open_issue'=>'未解事项','other'=>'其他'] as $k=>$label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</x-nf.story-select>
        <x-nf.field id="ui07-memory-library-11-{{ md5('statement') }}" label="精确事实片段" :error="$errors->first('statement')"><x-nf.control tag="textarea" id="ui07-memory-library-11-{{ md5('statement') }}" wire:model="statement"></x-nf.control></x-nf.field><x-nf.field id="ui07-memory-library-12-{{ md5('factChapter') }}" label="有效章位" :error="$errors->first('factChapter')"><x-nf.control id="ui07-memory-library-12-{{ md5('factChapter') }}" type="number" min="1" wire:model="factChapter" /></x-nf.field>
        <x-filament::button wire:loading.attr="disabled" wire:click="registerFact">登记事实清单</x-filament::button>
    </div></x-nf.section>
    <x-nf.section heading="摘要候选与分块"><div class="nf-production-layout">
        <x-filament::button wire:loading.attr="disabled" color="gray" wire:click="newMemory">新建记忆主题</x-filament::button>
        <div class="nf-story-fields">
        <x-nf.story-select id="ui07-memory-library-13-{{ md5('form.kind') }}" label="类别" model="form.kind" :value="data_get($__livewire,'form.kind')" >@foreach(['working'=>'工作','episodic'=>'情节','semantic'=>'语义','event'=>'事件'] as $k=>$label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</x-nf.story-select>
        <x-nf.field id="ui07-memory-library-14-{{ md5('form.name') }}" label="主题" :error="$errors->first('form.name')"><x-nf.control id="ui07-memory-library-14-{{ md5('form.name') }}" wire:model="form.name" /></x-nf.field><x-nf.field id="ui07-memory-library-15-{{ md5('form.introduced_chapter') }}" label="有效章位" :error="$errors->first('form.introduced_chapter')"><x-nf.control id="ui07-memory-library-15-{{ md5('form.introduced_chapter') }}" type="number" min="1" wire:model="form.introduced_chapter" /></x-nf.field><x-nf.field id="ui07-memory-library-16-{{ md5('form.importance') }}" label="重要度1–5" :error="$errors->first('form.importance')"><x-nf.control id="ui07-memory-library-16-{{ md5('form.importance') }}" type="number" min="1" max="5" wire:model="form.importance" /></x-nf.field>
        <x-nf.story-select id="ui07-memory-library-17-{{ md5('form.visibility.mode') }}" label="声明可见范围" model="form.visibility.mode" :value="data_get($__livewire,'form.visibility.mode')" ><option value="author_only">仅作者</option><option value="characters">指定角色（尚非可用POV）</option></x-nf.story-select><x-nf.story-select id="ui07-memory-library-18-{{ md5('form.visibility.character_ids') }}" label="角色" model="form.visibility.character_ids" :value="data_get($__livewire,'form.visibility.character_ids')" :multiple="true">@foreach($options['characters'] as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</x-nf.story-select>
        <x-nf.story-select id="ui07-memory-library-19-{{ md5('form.source_ids') }}" label="来源版本" model="form.source_ids" :value="data_get($__livewire,'form.source_ids')" :multiple="true">@foreach($options['sources'] as $s)<option value="{{ $s->id }}">#{{ $s->id }}</option>@endforeach</x-nf.story-select>
        <x-nf.story-select id="ui07-memory-library-20-{{ md5('form.fact_ids') }}" label="保留事实" model="form.fact_ids" :value="data_get($__livewire,'form.fact_ids')" :multiple="true">@foreach($options['facts'] as $f)<option value="{{ $f->id }}">#{{ $f->id }} / {{ $f->category }} / {{ $f->statement }}</option>@endforeach</x-nf.story-select>
        <x-nf.story-select id="ui07-memory-library-21-{{ md5('form.parent_ids') }}" label="父记忆版本" model="form.parent_ids" :value="data_get($__livewire,'form.parent_ids')" :multiple="true">@foreach($options['memories'] as $m)<option value="{{ $m->id }}">#{{ $m->id }} / 主题{{ $m->collection_id }} V{{ $m->version }}</option>@endforeach</x-nf.story-select>
        <x-nf.field id="ui07-memory-library-22-{{ md5('form.chunk_size') }}" label="每块码点数（不是Token）" :error="$errors->first('form.chunk_size')"><x-nf.control id="ui07-memory-library-22-{{ md5('form.chunk_size') }}" type="number" min="32" max="4000" wire:model="form.chunk_size" /></x-nf.field>
        </div>
        <x-nf.field id="ui07-memory-library-23-{{ md5('form.summary') }}" label="摘要" :error="$errors->first('form.summary')"><x-nf.control tag="textarea" id="ui07-memory-library-23-{{ md5('form.summary') }}" wire:model="form.summary" rows="8" class="w-full"></x-nf.control></x-nf.field>
        <p>修订自动继承当前活动版本的来源与全部事实。遗漏事实仅保存失败候选，不替换旧版本。</p>
        <x-nf.field id="ui07-memory-library-24-{{ md5('reason') }}" label="操作理由" :error="$errors->first('reason')"><x-nf.control tag="textarea" id="ui07-memory-library-24-{{ md5('reason') }}" wire:model="reason" class="w-full"></x-nf.control></x-nf.field>
        <x-filament::button wire:loading.attr="disabled" wire:click="saveMemory">保存并检查候选</x-filament::button><x-filament::button wire:loading.attr="disabled" color="gray" wire:click="refreshVersion">刷新操作基线</x-filament::button><p role="status">{{ $notice }}</p>
    </div></x-nf.section>
    @if($detail && $detail['novel_id']===$novel && $detail['branch_id']===$branch)
    <x-nf.section heading="来源、事实保留与失效链"><div class="nf-production-layout">
        <p>{{ $detail['name'] }} V{{ $detail['version'] }} / 内容：{{ $detail['content_status'] }} / 索引：{{ $detail['index_status'] }}</p>
        <div class="nf-manuscript" tabindex="0">{{ $detail['summary'] }}</div>
        @foreach($detail['report']['issues'] as $issue)<p>问题 {{ $issue['code'] }} / 事实 #{{ $issue['fact_id'] ?? '无' }}</p>@endforeach
        <x-filament::button wire:loading.attr="disabled" wire:click="loadRevision">载入为修订或失败重试</x-filament::button><x-filament::button wire:loading.attr="disabled" wire:click="activate">激活分支候选</x-filament::button><x-filament::button wire:loading.attr="disabled" color="danger" wire:click="disableMemory" wire:confirm="停用会使依赖此摘要的派生记忆失效，确认继续？">停用错误派生记忆</x-filament::button>
        <p>离线索引请前往检索实验室构建；正式提交后的派生任务请在正式记忆与索引中心查看。</p>
        @foreach($detail['parents'] as $parent)<details wire:ignore.self><summary>父版本 #{{ $parent['id'] }} / {{ $parent['content_status'] }} / {{ $parent['content_hash'] }}</summary><div class="nf-manuscript" tabindex="0">{{ $parent['summary'] }}</div></details>@endforeach
        @foreach($detail['facts'] as $f)<p>事实 #{{ $f['id'] }} / {{ $f['category'] }} / 第{{ $f['introduced_chapter'] }}章：{{ $f['statement'] }}</p>@endforeach
        @foreach($detail['sources'] as $s)<details wire:ignore.self><summary>原文来源 #{{ $s['id'] }} / {{ $s['source_hash'] }}</summary><div class="nf-manuscript" tabindex="0">{{ $s['body'] }}</div><x-filament::button wire:loading.attr="disabled" color="danger" wire:click="disableSource('{{ $s['id'] }}')" wire:confirm="停用来源会使所有派生摘要失效，确认继续？">标记此来源失效</x-filament::button></details>@endforeach
        @foreach($detail['evidence'] as $e)<p>证据 #{{ $e['id'] }} / 码点 {{ $e['start_offset'] }}–{{ $e['end_offset'] }}：{{ $e['quote'] }}</p>@endforeach
        @foreach($detail['chunks'] as $c)<details wire:ignore.self><summary>块{{ $c['chunk_no'] }} / 码点 {{ $c['start_offset'] }}–{{ $c['end_offset'] }}</summary><div class="nf-manuscript" tabindex="0">{{ $c['text'] }}</div></details>@endforeach
        @foreach($detail['invalidations'] as $i)<p>分支停用记录：来源 #{{ $i['source_version_id'] ?? '无' }} / 记忆 #{{ $i['memory_id'] ?? '无' }} / {{ $i['reason'] }}</p>@endforeach
    </div></x-nf.section>
    @endif
    @endif
@endif
</div></x-filament-panels::page>
