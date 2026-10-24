<x-filament-panels::page>
@include('filament.novel-navigation')
@php($list=$this->listing())
<x-filament::section heading="世界实体与人物设定">
<p>候选设定与已批准版本分别保存。正式存亡、位置、知识、经历及关系变化尚未接入，不由本页编辑。</p>
<div class="flex flex-wrap gap-3">{{ $this->definitionAction }} {{ $this->policyAction }} {{ $this->acceptMinorAction }}</div>
</x-filament::section>
<x-filament::section heading="实体库 / 人物卡">
<div class="flex flex-wrap gap-3">
<label>类型<select wire:model.live="type"><option value="">全部</option>@foreach($this::TYPES as $k=>$v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></label>
<label>状态<select wire:model.live="status"><option value="">全部</option>@foreach($this::STATUS as $k=>$v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></label>
<label>名称 / 当前别名<input wire:model.live.debounce.300ms="q" /></label>
<label>角色<select wire:model.live="role"><option value="">全部</option><option value="primary">主角</option><option value="supporting">配角</option><option value="extra">路人</option></select></label>
<label>阵营<input wire:model.live.debounce.300ms="faction" /></label>
<label><input type="checkbox" wire:model.live="unreferenced"/>无初始关系引用</label>
</div>
<div class="overflow-x-auto"><table class="w-full text-left"><thead><tr><th>名称 / 类型</th><th>角色 / 阵营</th><th>版本 / 批准</th><th>引用 / 动态状态</th><th>操作</th></tr></thead><tbody>
@forelse($list['items'] as $e)
@php($d=$e['current_definition']['definition'])
<tr wire:key="entity-{{ $e['id'] }}"><td>{{ $e['name'] }} · {{ $this::TYPES[$e['type']] }}<p>{{ implode('、',$d['aliases']) }}</p></td><td>{{ $d['profile']['role']??'—' }} / {{ $d['profile']['faction']??'—' }}<p>计划引入第{{ $d['introduced_chapter'] }}章</p></td><td>{{ $this::STATUS[$e['display_status']] }}<p>当前v{{ $e['current_definition']['version'] }}；批准版本ID {{ $e['approved_version_id']??'无' }}</p></td><td>初始关系引用 {{ $e['initial_reference_count'] }}<p>正式引用数 / 存亡 / 位置：未接入</p></td><td><x-filament::button size="sm" wire:click="$set('entityId','{{ $e['id'] }}')">版本 / 初始关系 / 引用</x-filament::button>
@if($this->editable()&&!$e['retired'])<x-filament::button size="sm" wire:click="mountAction('definition',{entity:'{{ $e['id'] }}'})">修订候选</x-filament::button><x-filament::button size="sm" wire:click="mountAction('approve',{definition:'{{ $e['current_version_id'] }}'})">批准</x-filament::button><x-filament::button size="sm" wire:click="mountAction('mergeAliases',{entity:'{{ $e['id'] }}'})">别名候选</x-filament::button><x-filament::button size="sm" wire:click="mountAction('retire',{entity:'{{ $e['id'] }}'})">停用</x-filament::button>@endif</td></tr>
@empty<tr><td colspan="5">暂无匹配实体。</td></tr>@endforelse
</tbody></table></div>
@if($cursor)<x-filament::button wire:click="$set('cursor','')">回到首页</x-filament::button>@endif
@if($list['next_cursor'])<x-filament::button wire:click="$set('cursor','{{ $list['next_cursor'] }}')">下一页</x-filament::button>@endif
</x-filament::section>
@if($entityId)
@php($detail=$this->detail()) @php($selected=$this->selected())
<x-filament::section heading="设定历史 / 人物声音与边界">
<label>查看版本<select wire:model.live="versionId"><option value="">最新候选</option>@foreach($detail['versions'] as $v)<option value="{{ $v['id'] }}">v{{ $v['version'] }} · {{ $v['reason'] }}</option>@endforeach</select></label>
<p>哈希 {{ $selected['content_hash'] }}；批准方式 {{ $selected['approval']['mode']??'未批准' }}；来源 #{{ $selected['source_version_id'] }}：{{ $selected['source_quote'] }}</p>
<dl>@foreach($selected['definition']['profile'] as $k=>$v)<dt>{{ $this::PROFILE[$k]??$k }}</dt><dd>{{ $v }}</dd>@endforeach</dl>
<p>别名合并来源版本：{{ $selected['alias_origin_version_id']??'无' }}</p><details><summary>引用此实体的别名合并历史</summary><pre>{{ json_encode($detail['alias_merge_references'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details>
<p>世界规则：{{ $selected['definition']['rule'] }}；适用范围：{{ $selected['definition']['scope'] }}</p>
<p>待决冲突：{{ $selected['definition']['conflict']?:'无已登记冲突（不等于语义校验已通过）' }}</p>
<details><summary>完整设定与初始关系</summary><pre class="whitespace-pre-wrap">{{ json_encode($selected['definition'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details>
<details><summary>引用此实体历史版本的初始关系</summary><pre class="whitespace-pre-wrap">{{ json_encode($detail['references'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details>
<label>比较版本<select wire:model.live="compareId"><option value="">不比较</option>@foreach($detail['versions'] as $v)<option value="{{ $v['id'] }}">v{{ $v['version'] }}</option>@endforeach</select></label>
@if($compareId)@php($before=$this->comparison())<p>所选比较版本 v{{ $before['version'] }} → 当前查看 v{{ $selected['version'] }}；{{ $before['content_hash']===$selected['content_hash']?'内容相同':'内容不同' }}</p><pre class="whitespace-pre-wrap">{{ json_encode($before['definition'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>@endif
<p>正式动态状态、知识与经历：未建立，不能从初始设定推断。</p>
{{-- D02 候选知识使用独立版本，不改写此页批准的人物卡或正式状态。 --}}
<a href="{{ \App\Filament\Pages\StoryTimeline::getUrl(['novel' => $novelId]) }}">查看候选时间线、人物知识与关系变化</a>
</x-filament::section>
@endif
<x-filament::section heading="冻结的引入策略历史">
<p>无策略时自动接纳关闭；仅最新已批准策略用于新接纳。旧策略及批准证据永久保留。</p>
@forelse($list['policies'] as $p)<p>v{{ $p['version'] }} / {{ $p['schema_version'] }} · {{ $p['enabled']?'开启':'关闭' }} · 第{{ $p['start_chapter'] }}—{{ $p['end_chapter'] }}章 · 此版本最多{{ $p['max_acceptances'] }}次 · {{ $p['reason'] }}</p>@empty<p>尚未配置；自动接纳关闭。</p>@endforelse
</x-filament::section>
</x-filament-panels::page>
