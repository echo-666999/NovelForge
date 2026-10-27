<x-filament-panels::page class="nf-story-screen">
{{-- 折叠区只保留自身展开状态；Livewire继续更新子字段，避免选择后中断编辑。 --}}
    @include('filament.book-navigation', ['navigation' => $this->bookNavigation()])
<div wire:loading role="status">正在读取或保存本书资料，请稍候…</div>
@if($errors->any())<x-nf.state state="error" title="请核对字段，输入仍保留"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-nf.state>@endif
    {{-- 管理员候选工作区与 POV 结果分开呈现，不能把全知信息当作角色已知。 --}}
    @php
        $characters = $this->entities('character'); $entities = $this->entities(); $candidates = $this->candidates();
        $current = $detail && $detail['novel_id'] === $novel && $detail['id'] === $context;
        $labels = ['consistent' => '一致', 'ambiguous' => '待确认', 'conflict' => '冲突'];
    @endphp
    <x-nf.section heading="时间线版本与来源">
    <p>候选时间线：检测通过不代表正式提交。未知时间保留未知；历史版本只读，修正会创建新版本。</p>
    @if($notice)<p role="status">{{ $notice }}</p>@endif
    <div class="nf-story-fields">

        <x-nf.story-select id="ui06-story-timeline-1-{{ md5('context') }}" label="历史版本（最近50个）" model="context" :value="data_get($__livewire,'context')" ><option value="">请选择</option>@foreach($this->versions() as $v)<option value="{{ $v['id'] }}">版本 {{ $v['version'] }} · {{ $v['reason'] }}</option>@endforeach</x-nf.story-select>
    </div>
    <x-filament::button wire:click="inspect">读取版本 / 重新查看检测结果</x-filament::button>
    <x-nf.field id="ui06-story-timeline-2-{{ md5('reason') }}" label="本次保存或修正原因" :error="$errors->first('reason')"><x-nf.control id="ui06-story-timeline-2-{{ md5('reason') }}" wire:model="reason" maxlength="2000"  /></x-nf.field>
    <details wire:ignore.self><summary>从候选事件建立新时间线</summary>
        @forelse($candidates as $e)<label class="nf-story-check"><input type="checkbox" wire:model="eventIds" value="{{ $e->id }}" />事件 {{ $e->id }} · 第{{ $e->chapter_sequence }}章 / {{ $e->narrative_order }} · {{ $e->attribute }} · {{ $e->fact_kind }}</label>@empty<p>尚无候选事件。D02 不会生成正文或自动提取事件。</p>@endforelse
        @if(count($candidates))<x-filament::button wire:click="createContext">以未知时间保存并检测</x-filament::button>@endif
    </details>
    </x-nf.section>
    @if($current)
        <x-nf.section heading="候选事件、来源与冲突">
        <p>版本 {{ $detail['version'] }} · {{ $labels[$detail['report']['status']] }} · 仅候选状态</p>
        <div class="nf-story-fields">
            <x-nf.story-select id="ui06-story-timeline-3-{{ md5('person') }}" label="人物" model="person" :value="data_get($__livewire,'person')" ><option value="">全部</option>@foreach($characters as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</x-nf.story-select>
            <x-nf.story-select id="ui06-story-timeline-4-{{ md5('place') }}" label="地点" model="place" :value="data_get($__livewire,'place')" ><option value="">全部</option>@foreach($this->entities('location') as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</x-nf.story-select>
            <x-nf.story-select id="ui06-story-timeline-5-{{ md5('timeFilter') }}" label="时间类型" model="timeFilter" :value="data_get($__livewire,'timeFilter')" ><option value="">全部</option><option value="unknown">未知</option><option value="exact">精确</option><option value="range">区间</option></x-nf.story-select>
            <x-nf.story-select id="ui06-story-timeline-6-{{ md5('clockFilter') }}" label="筛选时钟" model="clockFilter" :value="data_get($__livewire,'clockFilter')" ><option value="">全部（范围筛选须先选时钟）</option><option value="relative_seconds">相对秒序</option><option value="utc">UTC 秒序</option></x-nf.story-select>
            <x-nf.field id="ui06-story-timeline-7-{{ md5('fromTime') }}" label="时间下界（所选时钟秒序）" :error="$errors->first('fromTime')"><x-nf.control id="ui06-story-timeline-7-{{ md5('fromTime') }}" type="number" wire:model.live="fromTime"  /></x-nf.field><x-nf.field id="ui06-story-timeline-8-{{ md5('toTime') }}" label="时间上界" :error="$errors->first('toTime')"><x-nf.control id="ui06-story-timeline-8-{{ md5('toTime') }}" type="number" wire:model.live="toTime"  /></x-nf.field>
            <label class="nf-story-check"><input type="checkbox" wire:model.live="issuesOnly" />仅有问题的事件</label><label class="nf-story-check"><input type="checkbox" wire:model.live="secretsOnly" />仅隐藏真相（作者区）</label>
        </div>
        <x-nf.table label="故事资料列表"><table class="w-full"><thead><tr><th>事件 / 章位</th><th>主体</th><th>实际时间</th><th>属性 / 值</th><th>来源证据</th></tr></thead><tbody>
            @forelse($this->visibleEvents() as $e)<tr><td>{{ $e['id'] }} / 第{{ $e['chapter_sequence'] }}章·{{ $e['narrative_order'] }}</td><td>{{ $e['entity_id'] }} · {{ $e['fact_kind'] }}</td><td>{{ $e['entry']['time']['kind'] === 'unknown' ? '未知' : $e['entry']['time']['clock'].'：'.$e['entry']['time']['start'].'～'.$e['entry']['time']['end'] }}</td><td>{{ $e['attribute'] }} / @include('filament.value-table',['value'=>$e['value'],'label'=>'事件值只读'])</td><td><details wire:ignore.self><summary>查看原文</summary><p>{{ $e['evidence']['quote'] }}</p><p>来源 {{ $e['evidence']['source_version_id'] }}，码点 [{{ $e['evidence']['start_offset'] }}, {{ $e['evidence']['end_offset'] }})</p></details></td></tr>@empty<tr><td colspan="5">没有符合筛选条件的事件。</td></tr>@endforelse
        </tbody></table></x-nf.table>
        @foreach($detail['report']['issues'] as $issue)<p>{{ $labels[$issue['status']] }} · {{ $issue['code'] }} · 事件 {{ implode('、', $issue['event_ids']) }}：{{ $issue['suggested_fix'] }}</p>@endforeach
        </x-nf.section>
        <x-nf.section heading="时间与事件依赖修正">
        <details wire:ignore.self><summary>提议时间、地点或行动修正</summary>
            <x-nf.story-select id="ui06-story-timeline-9-{{ md5('event') }}" label="事件" model="event" :value="data_get($__livewire,'event')" ><option value="">请选择</option>@foreach($detail['events'] as $e)<option value="{{ $e['id'] }}">{{ $e['id'] }} · {{ $e['attribute'] }}</option>@endforeach</x-nf.story-select>
            <x-nf.story-select id="ui06-story-timeline-10-{{ md5('timeKind') }}" label="时间类型" model="timeKind" :value="data_get($__livewire,'timeKind')" ><option value="unknown">未知</option><option value="exact">精确</option><option value="range">有界区间</option></x-nf.story-select>
            <x-nf.story-select id="ui06-story-timeline-11-{{ md5('clock') }}" label="时钟" model="clock" :value="data_get($__livewire,'clock')" ><option value="relative_seconds">相对秒序（一天为86400秒）</option><option value="utc">绝对时间（带时区 ISO8601 或 UTC 秒）</option></x-nf.story-select>
            <x-nf.field id="ui06-story-timeline-12-{{ md5('start') }}" label="开始 / 精确时刻" :error="$errors->first('start')"><x-nf.control id="ui06-story-timeline-12-{{ md5('start') }}" wire:model="start"  /></x-nf.field><x-nf.field id="ui06-story-timeline-13-{{ md5('end') }}" label="结束" :error="$errors->first('end')"><x-nf.control id="ui06-story-timeline-13-{{ md5('end') }}" wire:model="end"  /></x-nf.field>
            <label class="nf-story-check"><input type="checkbox" wire:model="activity" />角色行动</label>
            <x-nf.story-select id="ui06-story-timeline-14-{{ md5('location') }}" label="行动地点" model="location" :value="data_get($__livewire,'location')" ><option value="">未说明</option>@foreach($this->entities('location') as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</x-nf.story-select>
            <x-nf.story-select id="ui06-story-timeline-15-{{ md5('ability') }}" label="使用能力" model="ability" :value="data_get($__livewire,'ability')" ><option value="">无</option>@foreach($this->entities('ability') as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</x-nf.story-select><x-nf.field id="ui06-story-timeline-16-{{ md5('units') }}" label="能力单位" :error="$errors->first('units')"><x-nf.control id="ui06-story-timeline-16-{{ md5('units') }}" type="number" min="1" wire:model="units"  /></x-nf.field>
            <x-nf.story-select id="ui06-story-timeline-17-{{ md5('previousOwner') }}" label="交接前所有者" model="previousOwner" :value="data_get($__livewire,'previousOwner')" ><option value="">未说明</option>@foreach($characters as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</x-nf.story-select>
            <x-nf.story-select id="ui06-story-timeline-18-{{ md5('revivalRule') }}" label="复活规则" model="revivalRule" :value="data_get($__livewire,'revivalRule')" ><option value="">无</option>@foreach($this->entities('rule') as $e)@if($e->approved_version_id)<option value="{{ $e->approved_version_id }}">{{ $e->name }} · 批准版本{{ $e->approved_version_id }}</option>@endif@endforeach</x-nf.story-select>
            <x-filament::button wire:click="reviseTime">保存新版本并检测</x-filament::button>
        </details>
        <details wire:ignore.self><summary>事件依赖</summary>
            <x-nf.story-select id="ui06-ref-predecessor" label="前置 / 容器事件" model="predecessor" :value="$predecessor"><option value="">请选择实际记录</option>@foreach($detail['events'] as $item)<option value="{{ $item['id'] }}">{{ $item['attribute'] }} · #{{ $item['id'] }}</option>@endforeach</x-nf.story-select><x-nf.story-select id="ui06-ref-successor" label="后续 / 被包含事件" model="successor" :value="$successor"><option value="">请选择实际记录</option>@foreach($detail['events'] as $item)<option value="{{ $item['id'] }}">{{ $item['attribute'] }} · #{{ $item['id'] }}</option>@endforeach</x-nf.story-select>
            <x-nf.story-select id="ui06-story-timeline-21-{{ md5('dependencyType') }}" label="关系" model="dependencyType" :value="data_get($__livewire,'dependencyType')" ><option value="before">严格先于</option><option value="depends_on">依赖（允许同刻）</option><option value="contains">时间范围包含</option><option value="simultaneous">同时</option></x-nf.story-select>
            <x-filament::button wire:click="addLink('dependencies')">追加依赖并检测</x-filament::button>
        </details>
        </x-nf.section>
        <x-nf.section heading="人物知识与关系">
        <details wire:ignore.self><summary>知识来源与章节 POV</summary>
            <x-nf.story-select id="ui06-story-timeline-22-{{ md5('knower') }}" label="知情角色" model="knower" :value="data_get($__livewire,'knower')" ><option value="">请选择</option>@foreach($characters as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</x-nf.story-select>
            <x-nf.story-select id="ui06-ref-fact" label="内容来源事件" model="fact" :value="$fact"><option value="">请选择实际记录</option>@foreach($detail['events'] as $item)<option value="{{ $item['id'] }}">{{ $item['attribute'] }} · #{{ $item['id'] }}</option>@endforeach</x-nf.story-select><x-nf.story-select id="ui06-ref-acquired" label="获得信息的事件" model="acquired" :value="$acquired"><option value="">请选择实际记录</option>@foreach($detail['events'] as $item)<option value="{{ $item['id'] }}">{{ $item['attribute'] }} · #{{ $item['id'] }}</option>@endforeach</x-nf.story-select>
            <x-nf.story-select id="ui06-story-timeline-25-{{ md5('method') }}" label="途径" model="method" :value="data_get($__livewire,'method')" ><option value="witness">目击</option><option value="told">他人转述</option><option value="inference">推测</option></x-nf.story-select>
            <x-nf.story-select id="ui06-story-timeline-26-{{ md5('informant') }}" label="转述者" model="informant" :value="data_get($__livewire,'informant')" ><option value="">无</option>@foreach($characters as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</x-nf.story-select>
            <x-filament::button wire:click="addLink('knowledge')">追加知情途径并检测</x-filament::button>
            <x-nf.field id="ui06-story-timeline-27-{{ md5('chapter') }}" label="查看第几章之后" :error="$errors->first('chapter')"><x-nf.control id="ui06-story-timeline-27-{{ md5('chapter') }}" type="number" min="1" wire:model="chapter"  /></x-nf.field><x-filament::button wire:click="inspectPov">查看该角色可知内容</x-filament::button>
            @if($knowledge)@if(!$knowledge['usable'])<p>该章前缀存在冲突或待确认项，暂不提供可用知识。</p>@endif<p>角色 {{ $knowledge['knower_id'] }} · 第{{ $knowledge['chapter'] }}章 · 候选知识</p>@forelse($knowledge['knowledge'] as $k)<div class="nf-story-record">{{ $k['knowledge_kind'] }} · {{ $k['attribute'] }}：{{ is_scalar($k['value'])||$k['value']===null ? var_export($k['value'],true) : '结构化知情值（见下方）' }}@if(is_array($k['value']))@include('filament.value-table',['value'=>$k['value'],'label'=>'知情值逐字段'])@endif；获得章{{ $k['introduced_at'] }}，{{ $k['method'] }}，证据「{{ $k['evidence']['quote'] }}」</div>@empty<p>没有已验证的知情途径；不展示作者隐藏真相。</p>@endforelse
            @foreach($knowledge['beliefs'] as $b)<div class="nf-story-record">角色说法 {{ $b['fact_kind'] }}：@include('filament.value-table',['value'=>$b['value'],'label'=>'角色知情值只读'])</div>@endforeach @endif
        </details>
        <details wire:ignore.self><summary>人物关系变化</summary><x-nf.story-select id="ui06-ref-event" label="驱动事件" model="event" :value="$event"><option value="">请选择实际记录</option>@foreach($detail['events'] as $item)<option value="{{ $item['id'] }}">{{ $item['attribute'] }} · #{{ $item['id'] }}</option>@endforeach</x-nf.story-select><x-nf.story-select id="ui06-ref-relationFrom" label="关系主体" model="relationFrom" :value="$relationFrom"><option value="">请选择实际记录</option>@foreach($entities as $item)<option value="{{ $item->id }}">{{ $item->name }} · #{{ $item->id }}</option>@endforeach</x-nf.story-select><x-nf.story-select id="ui06-ref-relationTo" label="关系对象" model="relationTo" :value="$relationTo"><option value="">请选择实际记录</option>@foreach($entities as $item)<option value="{{ $item->id }}">{{ $item->name }} · #{{ $item->id }}</option>@endforeach</x-nf.story-select><x-nf.field id="ui06-story-timeline-31-{{ md5('relationType') }}" label="关系类型" :error="$errors->first('relationType')"><x-nf.control id="ui06-story-timeline-31-{{ md5('relationType') }}" wire:model="relationType"  /></x-nf.field><x-nf.field id="ui06-story-timeline-32-{{ md5('relationState') }}" label="变化后状态" :error="$errors->first('relationState')"><x-nf.control id="ui06-story-timeline-32-{{ md5('relationState') }}" wire:model="relationState"  /></x-nf.field><x-filament::button wire:click="addLink('relationships')">追加关系变化并检测</x-filament::button></details>
        </x-nf.section>
        <x-nf.section heading="世界限制与关联历史">
        <details wire:ignore.self><summary>作者确认的结构化世界限制</summary><p>绑定批准的规则版本。数值与原文规则是否一致须由作者确认；系统不推断自然语言规则。</p>
            <x-nf.story-select id="ui06-story-timeline-33-{{ md5('ruleVersion') }}" label="批准规则" model="ruleVersion" :value="data_get($__livewire,'ruleVersion')" ><option value="">请选择</option>@foreach($this->entities('rule') as $e)@if($e->approved_version_id)<option value="{{ $e->approved_version_id }}">{{ $e->name }} · 版本{{ $e->approved_version_id }}</option>@endif@endforeach</x-nf.story-select>
            <x-nf.story-select id="ui06-story-timeline-34-{{ md5('ruleKind') }}" label="限制类型" model="ruleKind" :value="data_get($__livewire,'ruleKind')" ><option value="travel">旅行最少秒数</option><option value="ability">角色能力最大单位</option><option value="revival">角色复活解释（数值为1）</option></x-nf.story-select>
            <x-nf.story-select id="ui06-story-timeline-35-{{ md5('ruleSubject') }}" label="主体（出发地点或角色）" model="ruleSubject" :value="data_get($__livewire,'ruleSubject')" ><option value="">请选择</option>@foreach($entities as $e)<option value="{{ $e->id }}">{{ $e->name }} · {{ $e->type }}</option>@endforeach</x-nf.story-select><x-nf.story-select id="ui06-story-timeline-36-{{ md5('ruleTarget') }}" label="目标（目的地或能力）" model="ruleTarget" :value="data_get($__livewire,'ruleTarget')" ><option value="">无</option>@foreach($entities as $e)<option value="{{ $e->id }}">{{ $e->name }} · {{ $e->type }}</option>@endforeach</x-nf.story-select><x-nf.field id="ui06-story-timeline-37-{{ md5('ruleLimit') }}" label="限制数值" :error="$errors->first('ruleLimit')"><x-nf.control id="ui06-story-timeline-37-{{ md5('ruleLimit') }}" type="number" min="0" wire:model="ruleLimit"  /></x-nf.field>
            <x-filament::button wire:click="addLink('rules')">确认限制并保存新版本</x-filament::button>
        </details>
        @foreach(['dependencies'=>'事件依赖','knowledge'=>'知情记录','relationships'=>'动态关系','rules'=>'结构化限制'] as $kind=>$title)<details wire:ignore.self><summary>{{ $title }}：{{ count($detail['payload'][$kind]) }}项</summary>@foreach($detail['payload'][$kind] as $i=>$row)<p>{{ implode(' · ', array_map(fn($v)=>$v ?? '无', $row)) }} <x-filament::button size="sm" color="gray" wire:click="removeLink('{{ $kind }}', {{ $i }})">在新版本移除此项</x-filament::button></p>@endforeach</details>@endforeach
        </x-nf.section>
    @else
        <p>请选择小说并读取候选版本。尚无时间线时，可选择已有候选事件建立版本。</p>
    @endif
</x-filament-panels::page>
