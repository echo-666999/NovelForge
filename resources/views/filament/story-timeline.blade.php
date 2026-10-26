<x-filament-panels::page>
    @include('filament.book-navigation', ['navigation' => $this->bookNavigation()])
    {{-- 管理员候选工作区与 POV 结果分开呈现，不能把全知信息当作角色已知。 --}}
    @php
        $characters = $this->entities('character'); $entities = $this->entities(); $candidates = $this->candidates();
        $current = $detail && $detail['novel_id'] === $novel && $detail['id'] === $context;
        $labels = ['consistent' => '一致', 'ambiguous' => '待确认', 'conflict' => '冲突'];
    @endphp
    <p>候选时间线：检测通过不代表正式提交。未知时间保留未知；历史版本只读，修正会创建新版本。</p>
    @if($notice)<p role="status">{{ $notice }}</p>@endif
    <div class="grid gap-4 md:grid-cols-2">

        <label>历史版本（最近50个）<select wire:model="context"><option value="">请选择</option>@foreach($this->versions() as $v)<option value="{{ $v['id'] }}">版本 {{ $v['version'] }} · {{ $v['reason'] }}</option>@endforeach</select></label>
    </div>
    <x-filament::button wire:click="inspect">读取版本 / 重新查看检测结果</x-filament::button>
    <label>本次保存或修正原因<input wire:model="reason" maxlength="2000" /></label>
    <details><summary>从候选事件建立新时间线</summary>
        @forelse($candidates as $e)<label class="block"><input type="checkbox" wire:model="eventIds" value="{{ $e->id }}" />事件 {{ $e->id }} · 第{{ $e->chapter_sequence }}章 / {{ $e->narrative_order }} · {{ $e->attribute }} · {{ $e->fact_kind }}</label>@empty<p>尚无候选事件。D02 不会生成正文或自动提取事件。</p>@endforelse
        @if(count($candidates))<x-filament::button wire:click="createContext">以未知时间保存并检测</x-filament::button>@endif
    </details>
    @if($current)
        <p>版本 {{ $detail['version'] }} · {{ $labels[$detail['report']['status']] }} · 仅候选状态</p>
        <div class="grid gap-4 md:grid-cols-3">
            <label>人物<select wire:model.live="person"><option value="">全部</option>@foreach($characters as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select></label>
            <label>地点<select wire:model.live="place"><option value="">全部</option>@foreach($this->entities('location') as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select></label>
            <label>时间类型<select wire:model.live="timeFilter"><option value="">全部</option><option value="unknown">未知</option><option value="exact">精确</option><option value="range">区间</option></select></label>
            <label>筛选时钟<select wire:model.live="clockFilter"><option value="">全部（范围筛选须先选时钟）</option><option value="relative_seconds">相对秒序</option><option value="utc">UTC 秒序</option></select></label>
            <label>时间下界（所选时钟秒序）<input type="number" wire:model.live="fromTime" /></label><label>时间上界<input type="number" wire:model.live="toTime" /></label>
            <label><input type="checkbox" wire:model.live="issuesOnly" />仅有问题的事件</label><label><input type="checkbox" wire:model.live="secretsOnly" />仅隐藏真相（作者区）</label>
        </div>
        <div class="overflow-x-auto"><table class="w-full"><thead><tr><th>事件 / 章位</th><th>主体</th><th>实际时间</th><th>属性 / 值</th><th>来源证据</th></tr></thead><tbody>
            @forelse($this->visibleEvents() as $e)<tr><td>{{ $e['id'] }} / 第{{ $e['chapter_sequence'] }}章·{{ $e['narrative_order'] }}</td><td>{{ $e['entity_id'] }} · {{ $e['fact_kind'] }}</td><td>{{ $e['entry']['time']['kind'] === 'unknown' ? '未知' : $e['entry']['time']['clock'].'：'.$e['entry']['time']['start'].'～'.$e['entry']['time']['end'] }}</td><td>{{ $e['attribute'] }} / {{ json_encode($e['value'], JSON_UNESCAPED_UNICODE) }}</td><td><details><summary>查看原文</summary><p>{{ $e['evidence']['quote'] }}</p><p>来源 {{ $e['evidence']['source_version_id'] }}，码点 [{{ $e['evidence']['start_offset'] }}, {{ $e['evidence']['end_offset'] }})</p></details></td></tr>@empty<tr><td colspan="5">没有符合筛选条件的事件。</td></tr>@endforelse
        </tbody></table></div>
        @foreach($detail['report']['issues'] as $issue)<p>{{ $labels[$issue['status']] }} · {{ $issue['code'] }} · 事件 {{ implode('、', $issue['event_ids']) }}：{{ $issue['suggested_fix'] }}</p>@endforeach
        <details><summary>提议时间、地点或行动修正</summary>
            <label>事件<select wire:model.live="event"><option value="">请选择</option>@foreach($detail['events'] as $e)<option value="{{ $e['id'] }}">{{ $e['id'] }} · {{ $e['attribute'] }}</option>@endforeach</select></label>
            <label>时间类型<select wire:model="timeKind"><option value="unknown">未知</option><option value="exact">精确</option><option value="range">有界区间</option></select></label>
            <label>时钟<select wire:model="clock"><option value="relative_seconds">相对秒序（一天为86400秒）</option><option value="utc">绝对时间（带时区 ISO8601 或 UTC 秒）</option></select></label>
            <label>开始 / 精确时刻<input wire:model="start" /></label><label>结束<input wire:model="end" /></label>
            <label><input type="checkbox" wire:model="activity" />角色行动</label>
            <label>行动地点<select wire:model="location"><option value="">未说明</option>@foreach($this->entities('location') as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select></label>
            <label>使用能力<select wire:model="ability"><option value="">无</option>@foreach($this->entities('ability') as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select></label><label>能力单位<input type="number" min="1" wire:model="units" /></label>
            <label>交接前所有者<select wire:model="previousOwner"><option value="">未说明</option>@foreach($characters as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select></label>
            <label>复活规则<select wire:model="revivalRule"><option value="">无</option>@foreach($this->entities('rule') as $e)@if($e->approved_version_id)<option value="{{ $e->approved_version_id }}">{{ $e->name }} · 批准版本{{ $e->approved_version_id }}</option>@endif@endforeach</select></label>
            <x-filament::button wire:click="reviseTime">保存新版本并检测</x-filament::button>
        </details>
        <details><summary>事件依赖</summary>
            <label>前置 / 容器事件<input wire:model="predecessor" /></label><label>后续 / 被包含事件<input wire:model="successor" /></label>
            <label>关系<select wire:model="dependencyType"><option value="before">严格先于</option><option value="depends_on">依赖（允许同刻）</option><option value="contains">时间范围包含</option><option value="simultaneous">同时</option></select></label>
            <x-filament::button wire:click="addLink('dependencies')">追加依赖并检测</x-filament::button>
        </details>
        <details><summary>知识来源与章节 POV</summary>
            <label>知情角色<select wire:model="knower"><option value="">请选择</option>@foreach($characters as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select></label>
            <label>内容来源事件<input wire:model="fact" /></label><label>获得信息的事件<input wire:model="acquired" /></label>
            <label>途径<select wire:model="method"><option value="witness">目击</option><option value="told">他人转述</option><option value="inference">推测</option></select></label>
            <label>转述者<select wire:model="informant"><option value="">无</option>@foreach($characters as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select></label>
            <x-filament::button wire:click="addLink('knowledge')">追加知情途径并检测</x-filament::button>
            <label>查看第几章之后<input type="number" min="1" wire:model="chapter" /></label><x-filament::button wire:click="inspectPov">查看该角色可知内容</x-filament::button>
            @if($knowledge)@if(!$knowledge['usable'])<p>该章前缀存在冲突或待确认项，暂不提供可用知识。</p>@endif<p>角色 {{ $knowledge['knower_id'] }} · 第{{ $knowledge['chapter'] }}章 · 候选知识</p>@forelse($knowledge['knowledge'] as $k)<p>{{ $k['knowledge_kind'] }} · {{ $k['attribute'] }}：{{ json_encode($k['value'], JSON_UNESCAPED_UNICODE) }}；获得章{{ $k['introduced_at'] }}，{{ $k['method'] }}，证据「{{ $k['evidence']['quote'] }}」</p>@empty<p>没有已验证的知情途径；不展示作者隐藏真相。</p>@endforelse
            @foreach($knowledge['beliefs'] as $b)<p>角色说法 {{ $b['fact_kind'] }}：{{ json_encode($b['value'], JSON_UNESCAPED_UNICODE) }}</p>@endforeach @endif
        </details>
        <details><summary>人物关系变化</summary><label>驱动事件<input wire:model="event" /></label><label>关系主体<input wire:model="relationFrom" /></label><label>关系对象<input wire:model="relationTo" /></label><label>关系类型<input wire:model="relationType" /></label><label>变化后状态<input wire:model="relationState" /></label><x-filament::button wire:click="addLink('relationships')">追加关系变化并检测</x-filament::button></details>
        <details><summary>作者确认的结构化世界限制</summary><p>绑定批准的规则版本。数值与原文规则是否一致须由作者确认；系统不推断自然语言规则。</p>
            <label>批准规则<select wire:model="ruleVersion"><option value="">请选择</option>@foreach($this->entities('rule') as $e)@if($e->approved_version_id)<option value="{{ $e->approved_version_id }}">{{ $e->name }} · 版本{{ $e->approved_version_id }}</option>@endif@endforeach</select></label>
            <label>限制类型<select wire:model="ruleKind"><option value="travel">旅行最少秒数</option><option value="ability">角色能力最大单位</option><option value="revival">角色复活解释（数值为1）</option></select></label>
            <label>主体（出发地点或角色）<select wire:model="ruleSubject"><option value="">请选择</option>@foreach($entities as $e)<option value="{{ $e->id }}">{{ $e->name }} · {{ $e->type }}</option>@endforeach</select></label><label>目标（目的地或能力）<select wire:model="ruleTarget"><option value="">无</option>@foreach($entities as $e)<option value="{{ $e->id }}">{{ $e->name }} · {{ $e->type }}</option>@endforeach</select></label><label>限制数值<input type="number" min="0" wire:model="ruleLimit" /></label>
            <x-filament::button wire:click="addLink('rules')">确认限制并保存新版本</x-filament::button>
        </details>
        @foreach(['dependencies'=>'事件依赖','knowledge'=>'知情记录','relationships'=>'动态关系','rules'=>'结构化限制'] as $kind=>$title)<details><summary>{{ $title }}：{{ count($detail['payload'][$kind]) }}项</summary>@foreach($detail['payload'][$kind] as $i=>$row)<p>{{ implode(' · ', array_map(fn($v)=>$v ?? '无', $row)) }} <x-filament::button size="sm" color="gray" wire:click="removeLink('{{ $kind }}', {{ $i }})">在新版本移除此项</x-filament::button></p>@endforeach</details>@endforeach
    @else
        <p>请选择小说并读取候选版本。尚无时间线时，可选择已有候选事件建立版本。</p>
    @endif
</x-filament-panels::page>
