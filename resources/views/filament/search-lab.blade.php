<x-filament-panels::page class="nf-story-screen nf-production-screen"><div class="nf-production-layout">
    @include('filament.book-navigation', ['navigation' => $this->bookNavigation()])
@php($pageRead=($novel===''?['ok'=>true,'data'=>[]]:$this->productionReadFrame(['state'])))
@if(!$pageRead['ok'])<x-nf.state state="error" title="资料读取失败"><p>{{ $pageRead['message'] }}</p><div class="nf-story-toolbar"><x-filament::button color="gray" wire:click="$refresh">重新读取</x-filament::button><x-filament::button color="gray" wire:click="resetProductionFilters">重置筛选</x-filament::button></div></x-nf.state>
@else
<div wire:loading role="status">正在读取或保存，请稍候…</div>
@if($errors->any())<x-nf.state state="error" title="请核对字段，输入仍保留"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-nf.state>@endif
    {{-- 候选检索结果为不可信文本，只转义展示，不执行内容内指令。 --}}
    <p>精确 / 别名 / pg_trgm / pgvector 检索。当前向量来自离线字符哈希，不代表真实语义模型。本页管理候选索引；正式检索见正式记忆中心。真实嵌入调用与中文分词本轮保持关闭。</p>

    @if($novel==='')<p>选择小说后管理候选索引与检索。</p>@else
    @php($state=$pageRead['data']['state'])
    <div class="nf-story-fields">
        <x-nf.story-select id="ui07-search-lab-1-{{ md5('branch') }}" label="候选分支" model="branch" :value="data_get($__livewire,'branch')" ><option value="">请选择</option>@foreach($this->branches() as $b)<option value="{{ $b->id }}">#{{ $b->id }} / {{ $b->status }}</option>@endforeach</x-nf.story-select>
        <x-nf.story-select id="ui07-search-lab-2-{{ md5('profile') }}" label="嵌入档案" model="profile" :value="data_get($__livewire,'profile')" ><option value="">请选择</option>@foreach($state['profiles'] as $p)<option value="{{ $p['id'] }}">{{ $p['name'] }} V{{ $p['version'] }} / {{ $p['dimensions'] }}维 / {{ $p['distance_metric'] }}</option>@endforeach</x-nf.story-select>
        <x-nf.story-select id="ui07-search-lab-3-{{ md5('build') }}" label="索引代次" model="build" :value="data_get($__livewire,'build')" ><option value="">请选择</option>@foreach($state['builds'] as $b)<option value="{{ $b['id'] }}">#{{ $b['id'] }} / 分支{{ $b['branch_id'] }} / 档案{{ $b['profile_id'] }} / {{ $b['status'] }}</option>@endforeach</x-nf.story-select>
    </div>
    <x-nf.section heading="嵌入档案与影子索引"><div class="nf-production-layout">
        <x-nf.field id="ui07-search-lab-4-{{ md5('profileName') }}" label="档案名" :error="$errors->first('profileName')"><input id="ui07-search-lab-4-{{ md5('profileName') }}" wire:model="profileName"></x-nf.field><x-nf.field id="ui07-search-lab-5-{{ md5('dimensions') }}" label="维度" :error="$errors->first('dimensions')"><input id="ui07-search-lab-5-{{ md5('dimensions') }}" type="number" min="2" max="4096" wire:model="dimensions"></x-nf.field><x-nf.story-select id="ui07-search-lab-6-{{ md5('metric') }}" label="距离" model="metric" :value="data_get($__livewire,'metric')" ><option value="cosine">余弦</option><option value="l2">欧氏</option></x-nf.story-select>
        <x-filament::button wire:loading.attr="disabled" wire:click="registerProfile">注册离线档案新版本</x-filament::button><x-filament::button wire:loading.attr="disabled" wire:click="createBuild">新建影子代次</x-filament::button>
        <p>只用精确向量检索；HNSW等待规模评测。变更档案须完整重建，旧代次保留以便重新验证后回滚。</p>
        <x-filament::button wire:loading.attr="disabled" wire:click="validateBuild">核验清单并封存</x-filament::button><x-filament::button wire:loading.attr="disabled" wire:click="activateBuild">切换或回滚至选中代次</x-filament::button>
        @forelse($state['builds'] as $b)<p>代次 #{{ $b['id'] }} / 分支{{ $b['branch_id'] }} / {{ $b['status'] }} / 文档数{{ $b['metrics']['documents']??'尚未验证' }} / {{ $b['manifest_hash'] }}</p>@empty<p>暂无索引，请创建档案与影子代次。</p>@endforelse
    </div></x-nf.section>
    <x-nf.section heading="离线评测记录"><div class="nf-production-layout">
        @forelse($state['evaluations'] as $e)<p>报告 #{{ $e['id'] }} / 索引 #{{ $e['index_build_id'] }} / {{ $e['query_count'] }}条 / Recall@20 {{ $e['recall_at_20'] }} / 泄露{{ $e['leak_count'] }} / <a href="{{ url('api/v1/novels/'.$novel.'/search-evaluations/'.$e['id']) }}">完整报告</a></p>@empty<p>尚无固定标注评测报告。结构验证不代表召回质量通过。</p>@endforelse
    </div></x-nf.section>
    <x-nf.section heading="追加有来源的文档"><div class="nf-production-layout">
        <p>原文使用精确证据ID；记忆使用活动记忆ID及分块ID。POV文档另须事件ID，正文严格等于该事件事实值，标题和别名不得包含额外秘密。</p>
        <div class="nf-story-fields">@foreach(['source'=>'来源版本ID','evidence'=>'原文证据ID','event'=>'事件ID（可选）','memory'=>'记忆ID（可选）','chunk'=>'记忆分块ID（可选）','documentTitle'=>'标题'] as $prop=>$label)@if($prop!=='documentTitle')@include('filament.production-reference',['label'=>$label,'model'=>$prop,'kind'=>['source'=>'source','evidence'=>'evidence','event'=>'event','memory'=>'memory','chunk'=>'chunk'][$prop]])@else<x-nf.field id="ui07-search-lab-7-{{ md5(($prop)) }}" label="{{ $label }}" :error="$errors->first(($prop))"><input id="ui07-search-lab-7-{{ md5(($prop)) }}" wire:model="{{ $prop }}"></x-nf.field>@endif@endforeach<x-nf.field id="ui07-search-lab-8-{{ md5('introduced') }}" label="有效章位" :error="$errors->first('introduced')"><input id="ui07-search-lab-8-{{ md5('introduced') }}" type="number" min="1" wire:model="introduced"></x-nf.field><x-nf.field id="ui07-search-lab-9-{{ md5('aliases') }}" label="别名，每行一个" :error="$errors->first('aliases')"><textarea id="ui07-search-lab-9-{{ md5('aliases') }}" wire:model="aliases"></textarea></x-nf.field></div>
        <x-filament::button wire:loading.attr="disabled" wire:click="addDocument">追加并计算离线向量</x-filament::button>
    </div></x-nf.section>
    <x-nf.field id="ui07-search-lab-10-{{ md5('reason') }}" label="操作理由" :error="$errors->first('reason')"><textarea id="ui07-search-lab-10-{{ md5('reason') }}" wire:model="reason" class="w-full"></textarea></x-nf.field><x-filament::button wire:loading.attr="disabled" color="gray" wire:click="refreshVersion">刷新操作基线</x-filament::button><p role="status">{{ $notice }}</p>
    <x-nf.section heading="检索与排序解释"><div class="nf-production-layout">
        <x-nf.field id="ui07-search-lab-11-{{ md5('query') }}" label="查询" :error="$errors->first('query')"><input id="ui07-search-lab-11-{{ md5('query') }}" wire:model="query"></x-nf.field><x-nf.field id="ui07-search-lab-12-{{ md5('chapter') }}" label="截至章" :error="$errors->first('chapter')"><input id="ui07-search-lab-12-{{ md5('chapter') }}" type="number" min="1" wire:model="chapter"></x-nf.field><x-nf.story-select id="ui07-search-lab-13-{{ md5('audience') }}" label="范围" model="audience" :value="data_get($__livewire,'audience')" ><option value="author">作者候选区</option><option value="pov">角色已知事实</option></x-nf.story-select>@include('filament.production-reference',['label'=>'角色','model'=>'pov','kind'=>'character','multiple'=>false])@include('filament.production-reference',['label'=>'D02时间线版本','model'=>'context','kind'=>'context','multiple'=>false])<label class="nf-story-check"><input type="checkbox" wire:model="vector">使用离线向量通道</label>
        <x-filament::button wire:loading.attr="disabled" wire:click="runQuery">检索 Top 20</x-filament::button>
        @if($result)<p>可用文档 {{ $result['eligible_documents'] }} / {{ $result['strategy'] }} / {{ $result['degraded']?'已显式降级为精确/别名/三元组':'四通道' }}</p>
        @forelse($result['results'] as $r)<article><strong>{{ $r['title'] }}</strong><p>来源 #{{ $r['source_version_id'] }} / 第{{ $r['introduced_chapter'] }}章 / RRF {{ $r['score'] }}</p><div class="nf-manuscript" tabindex="0">{{ $r['body'] }}</div>@foreach($r['contributions'] as $channel=>$c)<p>{{ $channel }}：排名{{ $c['rank'] }}，原始值{{ $c['raw_score'] }}</p>@endforeach</article>@empty<p>当前权限和章位下无结果；不会放宽过滤补足数量。</p>@endforelse
        @endif
    </div></x-nf.section>
    @endif
@endif
</div></x-filament-panels::page>
