<x-filament-panels::page class="nf-story-screen">
@include('filament.novel-navigation')
<div wire:loading role="status">正在读取或保存本书资料，请稍候…</div>
@if($errors->any())<x-nf.state state="error" title="请核对字段，输入仍保留"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-nf.state>@endif

<div class="nf-story-toolbar"><x-filament::button color="gray" wire:click="$refresh" wire:loading.attr="disabled">刷新状态</x-filament::button></div>
@php($data=$this->summary())
@if(isset($data['error']))
<x-filament::button wire:click="resetFilters">重置筛选</x-filament::button><x-nf.section heading="读取未完成"><p role="alert">{{ $data['error'] }}</p><x-filament::button wire:click="$refresh">重试</x-filament::button></x-nf.section>
@else
{{-- UI14首屏先呈现活动正式基线；历史评审和离线费用均明确限定口径。 --}}
<x-nf.section heading="正式进度与生产状态">
@php($p=$data['production'])
@if($data['production_state']==='ready')
<p><strong>已正式提交 {{ $p['committed_chapters'] }} 章</strong> · 活动基线 v{{ $p['canonical_baseline']['version'] }}</p>
<p>下一正式章：{{ $p['next_chapter'] ? '第'.$p['next_chapter']['sequence'].'章 · '.$p['next_chapter']['title'] : '尚无下一章骨架' }}。此处章序不代表已取得生成资格。</p>
<p>正式提交所用历史评审保留 {{ $p['review_issues'] }} 条问题；这不是当前待解决问题数，也不代表作者成品认可。</p>
<p>正式记忆待派生 {{ $p['index_lag'] }} 项；派生完成不代表真实语义向量已就绪。</p>
@elseif($data['production_state']==='uninitialized')
<x-nf.state state="empty" title="尚未建立正式基线">正式章数与下一正式章未知；候选正文和规划章数不计入正式进度。</x-nf.state>
@else
<x-nf.state state="error" title="正式来源暂不可用">活动基线或连续提交映射不一致，请到正式提交页面核对；本页不回退其他基线或候选正文。</x-nf.state>
@endif
@if($p['recent_run'])
<p>本书最近历史运行：<a href="{{ $p['recent_run']['url'] }}">#{{ $p['recent_run']['id'] }} · {{ \App\Filament\Support\WorkspacePresentation::runStatus($p['recent_run']['status']) }}</a>。历史状态不代表当前基线可继续运行。</p>
@else<p>本书尚无运行记录。</p>@endif
<details wire:ignore.self><summary>费用与能力说明</summary>
<p>今日请求费用（{{ $p['today_cost']['day'] }}，UTC自然日）：按真实供应商/离线模拟来源及币种分别统计，包含本书历史运行。</p>
@forelse($p['today_cost']['rows'] as $cost)
<p>{{ $cost['evidence_kind'] === 'real_provider' ? '真实供应商' : '离线模拟' }} · {{ $cost['currency'] }} · 已核销 {{ $cost['settled_cost'] ?? '未知' }} · 未核销 {{ $cost['unknown_count'] }} 笔 · 风险预留 {{ $cost['risk_reserved'] }}。@if($cost['unknown_count'])完整实际费用未知。@endif</p>
@empty<p>今日无已登记预算的请求；未设置币种时不显示金额零。</p>@endforelse
@if($p['today_cost']['unbudgeted_requests'])<p role="status">另有 {{ $p['today_cost']['unbudgeted_requests'] }} 笔请求缺少预算记录，费用未知。</p>@endif
@if($p['budget_remaining'])
@php($budget=$p['budget_remaining'])
<p>最近请求 #{{ $budget['request_id'] }} 使用的政策 #{{ $budget['policy_id'] }} / v{{ $budget['policy_version'] }}：全书停止线 {{ $budget['stop_limit'] }} {{ $budget['currency'] }}，累计风险占用 {{ $budget['exposure'] }}，风险余量 {{ $budget['remaining'] }}。</p>
<p>这是该政策下的历史账本参考，不是新运行可用预算；日、章、运行及请求门禁仍由预算服务核验。@if($budget['reconciliation_required'])已有超预留费用，须先对账。@endif @if($budget['unbudgeted_requests'])另有 {{ $budget['unbudgeted_requests'] }} 笔历史请求缺少预算，完整风险占用未知。@endif</p>
@else<p>预算余额未知：尚无可追溯的全书预算预留。</p>@endif
<a href="/admin/billing?novel={{ $data['novel']['id'] }}">查看本书费用与预算</a>
<p>Worker心跳：未接入本页监测，不能推断在线。终局准备度：未接入，等待终局能力。</p>
</details>
</x-nf.section>
<x-nf.section heading="真实规划进度">
<p>{{ ['archived'=>'已归档','needs_decision'=>'存在待决定要求','planning_blocked'=>'规划尚有缺项','planning_frozen'=>'当前规划窗口已冻结'][$data['planning_status']] }}</p>
<p>章节骨架 {{ $data['planning_counts']['chapters'] }} / {{ $data['planning_counts']['target'] }} · 当前有效冻结 {{ $data['planning_counts']['valid_frozen_chapters'] }} 章 · 待决定要求 {{ $data['pending_decisions'] }} 项</p>
<p>每章字数策略：{{ $data['novel']['chapter_length_policy']['min'] }}—{{ $data['novel']['chapter_length_policy']['max'] }} 字</p>
<p>最近批准蓝图：{{ $data['blueprint'] ? 'v'.$data['blueprint']['version'] : '尚无批准记录' }}（当前可用性以计划门禁为准）</p>
<p>最新滚动计划：@if($data['plan']) v{{ $data['plan']['version'] }} · 第{{ $data['plan']['start_chapter'] }}—{{ $data['plan']['end_chapter'] }}章 · {{ $this::STATES[$data['plan']['status']] }} @else 尚未保存 @endif</p>

</x-nf.section>
<x-nf.section heading="下一步与规划缺项">
@forelse($data['blockers'] as $item)<div><p>{{ $item['message'] }} <a href="{{ $item['url'] }}">前往处理</a></p><details wire:ignore.self><summary>详情</summary>{{ $item['code'] }} {{ $item['path']??'' }}</details></div>@empty<p>当前没有规划门禁阻塞。计划冻结不代表已生成正文，也不代表文学验收通过。</p>@endforelse
</x-nf.section>
<x-nf.section heading="近期章节规划">
<div class="nf-story-toolbar">
<x-nf.field id="ui06-workspace-1-{{ md5('from') }}" label="起始章" :error="$errors->first('from')"><x-nf.control id="ui06-workspace-1-{{ md5('from') }}" type="number" min="1" wire:model.live="from"  /></x-nf.field><x-nf.field id="ui06-workspace-2-{{ md5('until') }}" label="结束章" :error="$errors->first('until')"><x-nf.control id="ui06-workspace-2-{{ md5('until') }}" type="number" min="1" wire:model.live="until"  /></x-nf.field>
<x-nf.story-select id="ui06-workspace-3-{{ md5('status') }}" label="规划状态" model="status" :value="data_get($__livewire,'status')" ><option value="">全部</option>@foreach($this::STATES as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</x-nf.story-select>
<label class="nf-story-check"><input type="checkbox" wire:model.live="issuesOnly" />仅有规划问题</label>
<x-nf.story-select id="ui06-workspace-4-{{ md5('limit') }}" label="每页" model="limit" :value="data_get($__livewire,'limit')" ><option>10</option><option>25</option><option>50</option><option>100</option></x-nf.story-select>
</div>
<p>问题数仅来自规划检查。评审列只展示活动基线正式提交所用历史评审，候选评审请进入评审中心查看。</p>
<x-nf.table label="故事资料列表"><table class="w-full text-left"><thead><tr><th>章节</th><th>规划状态</th><th>规划问题</th><th>评审 / 正式版本</th></tr></thead><tbody>
@forelse($data['chapters']['items'] as $row)<tr><td>{{ $row['sequence'] }} · {{ $row['title'] }}</td><td>{{ $this::STATES[$row['planning_status']] }}</td><td>{{ $row['planning_issue_count'] }}</td><td>@if($row['formal_version'])正式 v{{ $row['formal_version']['version'] }} · 提交 #{{ $row['formal_version']['commit_id'] }}<br>提交时评审 #{{ $row['review']['id'] }} · {{ \App\Filament\Support\WorkspacePresentation::verdict($row['review']['verdict']) }}@else{{ $data['production_state']==='unavailable' ? '正式来源暂不可用' : '未正式提交' }}@endif</td></tr>@empty<tr><td colspan="4">@if(!$data['planning_counts']['chapters'])尚无章节骨架。<a href="{{ $data['navigation']['links']['structure']['url'] }}">建立卷、故事弧与章节</a>@else 没有符合筛选条件的章节，请调整筛选或页码。@endif</td></tr>@endforelse
</tbody></table></x-nf.table>
<p>第 {{ $data['chapters']['page'] }} / {{ $data['chapters']['last_page'] }} 页 · {{ $data['chapters']['total'] }} 章</p>
@if($page>1)<x-filament::button wire:click="$set('page',{{ $page-1 }})">上一页</x-filament::button>@endif
@if($page<$data['chapters']['last_page'])<x-filament::button wire:click="$set('page',{{ $page+1 }})">下一页</x-filament::button>@endif
</x-nf.section>
<x-nf.section heading="正文生产">
<p id="production-unavailable">{{ $data['unavailable_reason'] }} 阅读、导出与查看入口可打开现有页面，实际操作仍需目标服务核验。</p>
<div class="nf-story-toolbar">@foreach($data['capabilities'] as $entry)
@if($entry['available'])<x-filament::button tag="a" color="gray" :href="$entry['url']">{{ $entry['label'] }}</x-filament::button>
@else<x-filament::button color="gray" type="button" disabled aria-describedby="production-unavailable">{{ $entry['label'] }}</x-filament::button>@endif
@endforeach</div>
</x-nf.section>
@endif
</x-filament-panels::page>
