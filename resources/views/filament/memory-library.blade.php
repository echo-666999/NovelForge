<x-filament-panels::page>
    {{-- 来源和摘要均为不可信文本，始终转义；角色范围仅供作者核对。 --}}
    <p>候选记忆库：正式基线尚未接入。摘要不能代替正式事实；角色范围声明不等于人物已知。嵌入与模型摘要执行未开放。</p>
    <label>小说<select wire:model.live="novel"><option value="">请选择</option>@foreach($this->books() as $b)<option value="{{ $b->id }}">{{ $b->title }}</option>@endforeach</select></label>
    <label>候选分支<select wire:model.live="branch"><option value="">请选择持久运行分支</option>@foreach($this->branches() as $b)<option value="{{ $b->id }}">#{{ $b->id }} / {{ $b->status }}</option>@endforeach</select></label>
    @if($novel==='' || $branch==='')<p>请先选择小说和候选分支。没有来源时不能创建无依据摘要。</p>@else
    @php($data=$this->listing()) @php($options=$this->options())
    <div class="grid gap-4 md:grid-cols-3">
        <label>类别<select wire:model.live="kind"><option value="">全部</option>@foreach(['working'=>'工作','episodic'=>'情节','semantic'=>'语义','event'=>'事件'] as $k=>$label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</select></label>
        <label>内容状态<select wire:model.live="status"><option value="">全部</option>@foreach(['pending'=>'候选/非当前版本','active'=>'分支活动','stale'=>'来源失效','failed'=>'摘要校验失败'] as $k=>$label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</select></label>
        <label>索引状态<select wire:model.live="indexStatus"><option value="">全部</option><option value="not_requested">尚未请求嵌入</option><option value="failed">索引失败（当前未接入）</option></select></label>
        <label>截至章位<input type="number" min="1" wire:model.live="chapter"></label><label>声明可见角色<select wire:model.live="character"><option value="">全部</option>@foreach($options['characters'] as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></label>
        <label>页码<input type="number" min="1" wire:model.live="page"></label>
    </div>
    <div class="overflow-x-auto"><table class="w-full"><thead><tr><th>记忆/版本</th><th>类别/章位</th><th>内容状态</th><th>嵌入状态</th><th>创建时间</th><th>操作</th></tr></thead><tbody>
        @forelse($data['items'] as $m)<tr><td>{{ $m['name'] }} V{{ $m['version'] }}</td><td>{{ $m['kind'] }} / {{ $m['introduced_chapter'] }}</td><td>{{ $m['content_status'] }} @if($m['source_issue']){{ $m['source_issue']['message'] }}@endif</td><td>尚未请求</td><td>{{ $m['created_at'] }}</td><td><x-filament::button wire:click="inspect('{{ $m['id'] }}')">原文与压缩比较</x-filament::button></td></tr>@empty<tr><td colspan="6">暂无符合条件的记忆。</td></tr>@endforelse
    </tbody></table><p>共 {{ $data['total'] }} 条，每页50条。</p></div>
    <x-filament::section heading="证据事实清单">
        <p>登记需要保留的精确原文片段。清单完整性仍需作者复核；不能凭此证明摘要涵盖全部语义。</p>
        <label>来源<select wire:model="source"><option value="">请选择</option>@foreach($options['sources'] as $s)<option value="{{ $s->id }}">来源 #{{ $s->id }}</option>@endforeach</select></label>
        <label>原文证据<select wire:model="evidence"><option value="">请选择</option>@foreach($options['evidence'] as $e)<option value="{{ $e->id }}">#{{ $e->id }} / 来源{{ $e->source_version_id }} / {{ mb_substr($e->quote,0,120) }}</option>@endforeach</select></label>
        <label>事实类别<select wire:model="category">@foreach(['irreversible'=>'不可逆变化','causal'=>'因果','knowledge'=>'知情者','open_issue'=>'未解事项','other'=>'其他'] as $k=>$label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</select></label>
        <label>精确事实片段<textarea wire:model="statement"></textarea></label><label>有效章位<input type="number" min="1" wire:model="factChapter"></label>
        <x-filament::button wire:click="registerFact">登记事实清单</x-filament::button>
    </x-filament::section>
    <x-filament::section heading="摘要候选与分块">
        <x-filament::button color="gray" wire:click="newMemory">新建记忆主题</x-filament::button>
        <div class="grid gap-4 md:grid-cols-2">
        <label>类别<select wire:model="form.kind">@foreach(['working'=>'工作','episodic'=>'情节','semantic'=>'语义','event'=>'事件'] as $k=>$label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</select></label>
        <label>主题<input wire:model="form.name"></label><label>有效章位<input type="number" min="1" wire:model="form.introduced_chapter"></label><label>重要度1–5<input type="number" min="1" max="5" wire:model="form.importance"></label>
        <label>声明可见范围<select wire:model="form.visibility.mode"><option value="author_only">仅作者</option><option value="characters">指定角色（尚非可用POV）</option></select></label><label>角色<select multiple wire:model="form.visibility.character_ids">@foreach($options['characters'] as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></label>
        <label>来源版本<select multiple wire:model="form.source_ids">@foreach($options['sources'] as $s)<option value="{{ $s->id }}">#{{ $s->id }}</option>@endforeach</select></label>
        <label>保留事实<select multiple wire:model="form.fact_ids">@foreach($options['facts'] as $f)<option value="{{ $f->id }}">#{{ $f->id }} / {{ $f->category }} / {{ $f->statement }}</option>@endforeach</select></label>
        <label>父记忆版本<select multiple wire:model="form.parent_ids">@foreach($options['memories'] as $m)<option value="{{ $m->id }}">#{{ $m->id }} / 主题{{ $m->collection_id }} V{{ $m->version }}</option>@endforeach</select></label>
        <label>每块码点数（不是Token）<input type="number" min="32" max="4000" wire:model="form.chunk_size"></label>
        </div>
        <label>摘要<textarea wire:model="form.summary" rows="8" class="w-full"></textarea></label>
        <p>修订自动继承当前活动版本的来源与全部事实。遗漏事实仅保存失败候选，不替换旧版本。</p>
        <label>操作理由<textarea wire:model="reason" class="w-full"></textarea></label>
        <x-filament::button wire:click="saveMemory">保存并检查候选</x-filament::button><x-filament::button color="gray" wire:click="refreshVersion">刷新操作基线</x-filament::button><p role="status">{{ $notice }}</p>
    </x-filament::section>
    @if($detail && $detail['novel_id']===$novel && $detail['branch_id']===$branch)
    <x-filament::section heading="来源、事实保留与失效链">
        <p>{{ $detail['name'] }} V{{ $detail['version'] }} / 内容：{{ $detail['content_status'] }} / 索引：尚未请求</p>
        <pre class="whitespace-pre-wrap">{{ $detail['summary'] }}</pre>
        @foreach($detail['report']['issues'] as $issue)<p>问题 {{ $issue['code'] }} / 事实 #{{ $issue['fact_id'] ?? '无' }}</p>@endforeach
        <x-filament::button wire:click="loadRevision">载入为修订或失败重试</x-filament::button><x-filament::button wire:click="activate">激活分支候选</x-filament::button><x-filament::button color="danger" wire:click="disableMemory" wire:confirm="停用会使依赖此摘要的派生记忆失效，确认继续？">停用错误派生记忆</x-filament::button>
        <p>重建嵌入待D05；自动提取与正式提交消费待E07，当前没有可执行按钮。</p>
        @foreach($detail['parents'] as $parent)<details><summary>父版本 #{{ $parent['id'] }} / {{ $parent['content_status'] }} / {{ $parent['content_hash'] }}</summary><pre class="whitespace-pre-wrap">{{ $parent['summary'] }}</pre></details>@endforeach
        @foreach($detail['facts'] as $f)<p>事实 #{{ $f['id'] }} / {{ $f['category'] }} / 第{{ $f['introduced_chapter'] }}章：{{ $f['statement'] }}</p>@endforeach
        @foreach($detail['sources'] as $s)<details><summary>原文来源 #{{ $s['id'] }} / {{ $s['source_hash'] }}</summary><pre class="whitespace-pre-wrap">{{ $s['body'] }}</pre><x-filament::button color="danger" wire:click="disableSource('{{ $s['id'] }}')" wire:confirm="停用来源会使所有派生摘要失效，确认继续？">标记此来源失效</x-filament::button></details>@endforeach
        @foreach($detail['evidence'] as $e)<p>证据 #{{ $e['id'] }} / 码点 {{ $e['start_offset'] }}–{{ $e['end_offset'] }}：{{ $e['quote'] }}</p>@endforeach
        @foreach($detail['chunks'] as $c)<details><summary>块{{ $c['chunk_no'] }} / 码点 {{ $c['start_offset'] }}–{{ $c['end_offset'] }}</summary><pre class="whitespace-pre-wrap">{{ $c['text'] }}</pre></details>@endforeach
        @foreach($detail['invalidations'] as $i)<p>分支停用记录：来源 #{{ $i['source_version_id'] ?? '无' }} / 记忆 #{{ $i['memory_id'] ?? '无' }} / {{ $i['reason'] }}</p>@endforeach
    </x-filament::section>
    @endif
    @endif
</x-filament-panels::page>
