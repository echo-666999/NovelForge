<x-filament-panels::page>
    @include('filament.book-navigation', ['navigation' => $this->bookNavigation()])
    <p>仅候选正文的离线提取回放。规则检查不等于文学评审或正式提交；正式事件与正式伏笔状态保持关闭。</p>
    @if($notice)<p role="status">{{ $notice }}</p>@endif
    <section class="space-y-3 rounded-xl border p-4">
        <label>章节正文版本 ID <input wire:model="version"></label>
        <x-filament::button wire:click="loadBody">读取实际正文</x-filament::button>
        <label>前章有效候选提取 ID <input wire:model="baseline"></label>
        <label>误提取来源 ID（仅同正文）<input wire:model="parent"></label>
        <label>作者规则上下文 ID <input wire:model="ruleContext"></label>
        <p>规则仅从作者选择的既有时间线规则读取；响应禁止提供规则上限。事件引用使用 local:事件键 或 event:前章事件ID。偏移为从零开始的 Unicode 码点，结束位置不包含在引文内。</p>
        <label class="block">离线提取响应 <textarea class="w-full" rows="16" wire:model="output"></textarea></label>
        <label>原因 <input wire:model="reason"></label><x-filament::button wire:click="extract">仅验证离线响应（不建模型请求）</x-filament::button>
    </section>
    @php($options = $this->options())
    <section class="space-y-3 rounded-xl border p-4">
        <h2>冻结路由的离线提取阶段</h2>
        <p>复用上方实际正文、响应及原因；使用fake供应商和offline_fake路由，产生持久预算、请求证据和恢复记录。</p>
        <label>离线路由 <select wire:model="route"><option value="">请选择</option>@foreach($options['routes'] as $v)<option value="{{ $v->id }}">{{ $v->key }}</option>@endforeach</select></label>
        <label>预算政策 <select wire:model="policy"><option value="">请选择</option>@foreach($options['policies'] as $v)<option value="{{ $v->id }}">{{ $v->key }}</option>@endforeach</select></label>
        <label>报价版本 ID（逗号分隔）<input wire:model="prices"></label>
        <x-filament::button wire:click="startPipeline">冻结并启动离线提取</x-filament::button>
        <label>运行 ID <input wire:model="run"></label><x-filament::button wire:click="replayPipeline">执行离线阶段回放</x-filament::button>
        @if(ctype_digit($run))<a href="/admin/recovery-desk?novel={{ $novel }}&run={{ $run }}">查看恢复与请求证据</a>@endif
        <details><summary>提取输出契约（配置Prompt时使用）</summary><pre class="whitespace-pre-wrap">{{ json_encode(\App\Extraction\ExtractionContract::schema(), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details>
    </section>
    @if($novel !== '')
        <section class="space-y-3 rounded-xl border p-4">
            <h2>不可变提取历史</h2>
            @foreach($this->listing() as $row)<p>#{{ $row['id'] }} 正文 #{{ $row['chapter_version_id'] }} / {{ $row['body_hash'] }} / {{ $row['display_status'] }} / {{ $row['reason'] }}</p>@endforeach
            <label>提取 ID <input wire:model="selected"></label><x-filament::button wire:click="inspect">查看事件、补丁与冲突</x-filament::button><x-filament::button wire:click="prepareReextract">只重新提取此正文</x-filament::button>
        </section>
    @endif
    @if($body)<h2>只读原文 #{{ $body['id'] }}</h2><pre class="whitespace-pre-wrap">{{ $body['body'] }}</pre>@endif
    @if($detail)
        <h2>验证结果：{{ $detail['display_status'] }}；当前可用：{{ $detail['current_eligible'] ? '是（仍为候选）' : '否' }}</h2>
        <pre class="whitespace-pre-wrap">{{ json_encode($detail['report'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>
        <label>实体 ID <input wire:model.live="entity"></label><label>事实类别 <select wire:model.live="factKind"><option value="">全部</option>@foreach(\App\Services\StoryStateService::KINDS as $kind)<option>{{ $kind }}</option>@endforeach</select></label>
        <table class="w-full"><thead><tr><th>事件 / 主体</th><th>发生时间 / 叙述位置</th><th>变化 / 类别</th><th>原文证据</th></tr></thead><tbody>
            @foreach($this->events() as $e)<tr><td>{{ $e['id'] }} / {{ $e['entity_id'] }}</td><td>{{ json_encode($e['entry']['time'], JSON_UNESCAPED_UNICODE) }} / {{ $e['chapter_sequence'] }}:{{ $e['narrative_order'] }}</td><td>{{ $e['attribute'] }}={{ json_encode($e['value'], JSON_UNESCAPED_UNICODE) }} / {{ $e['fact_kind'] }} / {{ $e['action'] }}</td><td>{{ $e['evidence']['start_offset'] }}–{{ $e['evidence']['end_offset'] }} {{ $e['evidence']['quote'] }}</td></tr>@endforeach
        </tbody></table>
        <label>回放截至章 <input type="number" min="1" wire:model="atChapter"></label>
        <x-filament::button wire:click="replay">回放候选状态</x-filament::button><x-filament::button wire:click="replay(true)">重建候选投影</x-filament::button>
        <a href="/api/v1/novels/{{ $novel }}/extractions/{{ $selected }}" download>导出本次提取与审计来源JSON</a>
        @if($projection)<pre class="whitespace-pre-wrap">{{ json_encode($projection, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>@endif
        <h3>候选补丁及伏笔变化</h3><pre class="whitespace-pre-wrap">{{ json_encode(['patches'=>$detail['patches'],'foreshadows'=>$detail['candidate_foreshadows']], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>
    @endif
</x-filament-panels::page>
