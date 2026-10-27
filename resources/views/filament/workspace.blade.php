<x-filament-panels::page class="nf-story-screen">
@include('filament.novel-navigation')
<div wire:loading role="status">正在读取或保存本书资料，请稍候…</div>
@if($errors->any())<x-nf.state state="error" title="请核对字段，输入仍保留"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-nf.state>@endif
<div wire:loading role="status">正在读取规划状态…</div>
<div class="nf-story-toolbar"><x-filament::button color="gray" wire:click="$refresh" wire:loading.attr="disabled">刷新状态</x-filament::button></div>
@php($data=$this->summary())
@if(isset($data['error']))
<x-filament::button wire:click="resetFilters">重置筛选</x-filament::button><x-nf.section heading="读取未完成"><p role="alert">{{ $data['error'] }}</p><x-filament::button wire:click="$refresh">重试</x-filament::button></x-nf.section>
@else
<x-nf.section heading="真实规划进度">
<p>{{ ['archived'=>'已归档','needs_decision'=>'存在待决定要求','planning_blocked'=>'规划尚有缺项','planning_frozen'=>'当前规划窗口已冻结'][$data['planning_status']] }}</p>
<p>章节骨架 {{ $data['planning_counts']['chapters'] }} / {{ $data['planning_counts']['target'] }} · 当前有效冻结 {{ $data['planning_counts']['valid_frozen_chapters'] }} 章 · 待决定要求 {{ $data['pending_decisions'] }} 项</p>
<p>每章字数策略：{{ $data['novel']['chapter_length_policy']['min'] }}—{{ $data['novel']['chapter_length_policy']['max'] }} 字</p>
<p>最近批准蓝图：{{ $data['blueprint'] ? 'v'.$data['blueprint']['version'] : '尚无批准记录' }}（当前可用性以计划门禁为准）</p>
<p>最新滚动计划：@if($data['plan']) v{{ $data['plan']['version'] }} · 第{{ $data['plan']['start_chapter'] }}—{{ $data['plan']['end_chapter'] }}章 · {{ $this::STATES[$data['plan']['status']] }} @else 尚未保存 @endif</p>
<p>正式章数、下一正式章、预算余额与终局准备度：未知，本页尚未读取这些生产指标。</p>
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
<p>本页未读取评审问题等级；以下问题数仅来自规划检查。</p>
<x-nf.table label="故事资料列表"><table class="w-full text-left"><thead><tr><th>章节</th><th>规划状态</th><th>规划问题</th><th>评审 / 正式版本</th></tr></thead><tbody>
@forelse($data['chapters']['items'] as $row)<tr><td>{{ $row['sequence'] }} · {{ $row['title'] }}</td><td>{{ $this::STATES[$row['planning_status']] }}</td><td>{{ $row['planning_issue_count'] }}</td><td>本页未读取</td></tr>@empty<tr><td colspan="4">@if(!$data['planning_counts']['chapters'])尚无章节骨架。<a href="{{ $data['navigation']['links']['structure']['url'] }}">建立卷、故事弧与章节</a>@else 没有符合筛选条件的章节，请调整筛选或页码。@endif</td></tr>@endforelse
</tbody></table></x-nf.table>
<p>第 {{ $data['chapters']['page'] }} / {{ $data['chapters']['last_page'] }} 页 · {{ $data['chapters']['total'] }} 章</p>
@if($page>1)<x-filament::button wire:click="$set('page',{{ $page-1 }})">上一页</x-filament::button>@endif
@if($page<$data['chapters']['last_page'])<x-filament::button wire:click="$set('page',{{ $page+1 }})">下一页</x-filament::button>@endif
</x-nf.section>
<x-nf.section heading="正文生产">
<p id="production-unavailable">{{ $data['novel']['status']==='archived'?$data['unavailable_reason']:'本页尚未提供生产操作与运行读模型，以下按钮保持禁用；请从本书导航进入对应页面核对实际能力。' }}</p>
<div class="nf-story-toolbar">@foreach(['生成下一章','生成连续范围','暂停','继续','打开阅读器','导出'] as $label)<button type="button" disabled aria-describedby="production-unavailable">{{ $label }}</button>@endforeach</div>
</x-nf.section>
@endif
</x-filament-panels::page>
