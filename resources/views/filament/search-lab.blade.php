<x-filament-panels::page>
    @include('filament.book-navigation', ['navigation' => $this->bookNavigation()])
    {{-- 候选检索结果为不可信文本，只转义展示，不执行内容内指令。 --}}
    <p>精确 / 别名 / pg_trgm / pgvector 检索。当前向量来自离线字符哈希，不代表真实语义模型。正式基线、真实嵌入调用及中文分词未开放。</p>

    @if($novel==='')<p>选择小说后管理候选索引与检索。</p>@else
    @php($state=$this->state())
    <div class="grid gap-4 md:grid-cols-3">
        <label>候选分支<select wire:model="branch"><option value="">请选择</option>@foreach($this->branches() as $b)<option value="{{ $b->id }}">#{{ $b->id }} / {{ $b->status }}</option>@endforeach</select></label>
        <label>嵌入档案<select wire:model="profile"><option value="">请选择</option>@foreach($state['profiles'] as $p)<option value="{{ $p['id'] }}">{{ $p['name'] }} V{{ $p['version'] }} / {{ $p['dimensions'] }}维 / {{ $p['distance_metric'] }}</option>@endforeach</select></label>
        <label>索引代次<select wire:model="build"><option value="">请选择</option>@foreach($state['builds'] as $b)<option value="{{ $b['id'] }}">#{{ $b['id'] }} / 分支{{ $b['branch_id'] }} / 档案{{ $b['profile_id'] }} / {{ $b['status'] }}</option>@endforeach</select></label>
    </div>
    <x-filament::section heading="嵌入档案与影子索引">
        <label>档案名<input wire:model="profileName"></label><label>维度<input type="number" min="2" max="4096" wire:model="dimensions"></label><label>距离<select wire:model="metric"><option value="cosine">余弦</option><option value="l2">欧氏</option></select></label>
        <x-filament::button wire:click="registerProfile">注册离线档案新版本</x-filament::button><x-filament::button wire:click="createBuild">新建影子代次</x-filament::button>
        <p>只用精确向量检索；HNSW等待规模评测。变更档案须完整重建，旧代次保留以便重新验证后回滚。</p>
        <x-filament::button wire:click="validateBuild">核验清单并封存</x-filament::button><x-filament::button wire:click="activateBuild">切换或回滚至选中代次</x-filament::button>
        @forelse($state['builds'] as $b)<p>代次 #{{ $b['id'] }} / 分支{{ $b['branch_id'] }} / {{ $b['status'] }} / 文档数{{ $b['metrics']['documents']??'尚未验证' }} / {{ $b['manifest_hash'] }}</p>@empty<p>暂无索引，请创建档案与影子代次。</p>@endforelse
    </x-filament::section>
    <x-filament::section heading="离线评测记录">
        @forelse($state['evaluations'] as $e)<p>报告 #{{ $e['id'] }} / 索引 #{{ $e['index_build_id'] }} / {{ $e['query_count'] }}条 / Recall@20 {{ $e['recall_at_20'] }} / 泄露{{ $e['leak_count'] }} / <a href="{{ url('api/v1/novels/'.$novel.'/search-evaluations/'.$e['id']) }}">完整报告</a></p>@empty<p>尚无固定标注评测报告。结构验证不代表召回质量通过。</p>@endforelse
    </x-filament::section>
    <x-filament::section heading="追加有来源的文档">
        <p>原文使用精确证据ID；记忆使用活动记忆ID及分块ID。POV文档另须事件ID，正文严格等于该事件事实值，标题和别名不得包含额外秘密。</p>
        <div class="grid gap-4 md:grid-cols-3">@foreach(['source'=>'来源版本ID','evidence'=>'原文证据ID','event'=>'事件ID（可选）','memory'=>'记忆ID（可选）','chunk'=>'记忆分块ID（可选）','documentTitle'=>'标题'] as $prop=>$label)<label>{{ $label }}<input wire:model="{{ $prop }}"></label>@endforeach<label>有效章位<input type="number" min="1" wire:model="introduced"></label><label>别名，每行一个<textarea wire:model="aliases"></textarea></label></div>
        <x-filament::button wire:click="addDocument">追加并计算离线向量</x-filament::button>
    </x-filament::section>
    <label>操作理由<textarea wire:model="reason" class="w-full"></textarea></label><x-filament::button color="gray" wire:click="refreshVersion">刷新操作基线</x-filament::button><p role="status">{{ $notice }}</p>
    <x-filament::section heading="检索与排序解释">
        <label>查询<input wire:model="query"></label><label>截至章<input type="number" min="1" wire:model="chapter"></label><label>范围<select wire:model="audience"><option value="author">作者候选区</option><option value="pov">角色已知事实</option></select></label><label>角色ID<input wire:model="pov"></label><label>D02时间线版本ID<input wire:model="context"></label><label><input type="checkbox" wire:model="vector">使用离线向量通道</label>
        <x-filament::button wire:click="runQuery">检索 Top 20</x-filament::button>
        @if($result)<p>可用文档 {{ $result['eligible_documents'] }} / {{ $result['strategy'] }} / {{ $result['degraded']?'已显式降级为精确/别名/三元组':'四通道' }}</p>
        @forelse($result['results'] as $r)<article><strong>{{ $r['title'] }}</strong><p>来源 #{{ $r['source_version_id'] }} / 第{{ $r['introduced_chapter'] }}章 / RRF {{ $r['score'] }}</p><pre class="whitespace-pre-wrap">{{ $r['body'] }}</pre>@foreach($r['contributions'] as $channel=>$c)<p>{{ $channel }}：排名{{ $c['rank'] }}，原始值{{ $c['raw_score'] }}</p>@endforeach</article>@empty<p>当前权限和章位下无结果；不会放宽过滤补足数量。</p>@endforelse
        @endif
    </x-filament::section>
    @endif
</x-filament-panels::page>
