<x-filament-panels::page class="nf-story-screen">
@include('filament.novel-navigation')
<div wire:loading role="status">正在读取或保存本书资料，请稍候…</div>
@if($errors->any())<x-nf.state state="error" title="请核对字段，输入仍保留"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-nf.state>@endif
@php($list=$this->listing())
<x-nf.section heading="蓝图与终局契约规划">
<p>先手工规划、检查，再批准冻结。历史内容始终只读；批准记录不等于当前依赖仍有效，生成前仍需后续门禁。</p>
<p>前十章场景细节、字数预算及冻结资格请到章节场景计划核对。终局承诺目前仅为规划，不显示虚构回收率。</p>{{ $this->saveAction }}
<x-nf.story-select id="ui06-blueprints-1-{{ md5('status') }}" label="状态筛选" model="status" :value="data_get($__livewire,'status')" ><option value="">全部</option>@foreach($this::STATUS as $k=>$v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</x-nf.story-select>
<x-nf.table label="故事资料列表"><table class="w-full text-left"><thead><tr><th>版本 / 来源</th><th>一句话 / 原因</th><th>状态 / 检查</th><th>动作</th></tr></thead><tbody>
@foreach($list['items'] as $v)@if(!$status||$status===$v['display_status'])<tr wire:key="blueprint-{{ $v['id'] }}"><td>v{{ $v['version'] }} · {{ $v['origin'] === 'offline_planning_candidate' ? '离线候选采纳' : '作者编辑' }}<p>输入#{{ $v['source_version_id'] }} / 结构#{{ $v['outline_version_id'] }}</p><p>{{ $v['created_at'] }}</p></td><td>{{ $v['content']['body']['logline'] }}<p>{{ $v['reason'] }}</p></td><td>{{ $this::STATUS[$v['display_status']] }}<p>{{ $v['validation']?($v['validation']['report']['passed']?'检查通过（历史记录）':'存在阻塞'):'尚未检查' }}</p></td><td><x-filament::button wire:click="$set('selectedId','{{ $v['id'] }}')">详情 / 影响</x-filament::button>@if($this->editable())<x-filament::button wire:click="mountAction('save',{blueprint:'{{ $v['id'] }}'})">复制修改</x-filament::button><x-filament::button wire:click="mountAction('validate',{blueprint:'{{ $v['id'] }}'})">检查</x-filament::button>@endif</td></tr>@endif@endforeach
</tbody></table></x-nf.table>
@if($cursor)<x-filament::button wire:click="$set('cursor','')">返回首页</x-filament::button>@endif @if($list['next_cursor'])<x-filament::button wire:click="$set('cursor','{{ $list['next_cursor'] }}')">下一页</x-filament::button>@endif
</x-nf.section>
@if($selectedId)@php($v=$this->selected())
<x-nf.section heading="蓝图内容与终局承诺">
<p>蓝图v{{ $v['version'] }} / 终局契约v{{ $v['ending_contract']['version'] }}；{{ $this::STATUS[$v['display_status']] }}</p>
@foreach($this::FIELDS as $k=>$label)<details wire:ignore.self><summary>{{ $label }}</summary><p class="whitespace-pre-wrap">{{ $v['content']['body'][$k] }}</p></details>@endforeach
<p>结束窗口：第{{ $v['content']['ending']['start_chapter'] }}—{{ $v['content']['ending']['end_chapter'] }}章；目标{{ $v['content']['ending']['target_chapters'] }}章</p>
@foreach($this::ENDING as $k=>$label)<p>{{ $label }}：{{ $v['content']['ending'][$k] }}</p>@endforeach
@foreach($v['content']['ending']['outcomes'] as $o)<p>人物设定#{{ $o['entity_definition_version_id'] }}结局：{{ $o['outcome'] }}</p>@endforeach
<p>必须回收：{{ implode('；',$v['content']['ending']['required_foreshadows']) }}</p><p>允许开放：{{ implode('；',$v['content']['ending']['open_items']) }}</p>
@foreach($v['content']['threads'] as $t)<p>{{ $t['kind']==='main'?'主线':'支线' }} {{ $t['identity_snapshot']['title'] }}：{{ $t['goal'] }} → {{ $t['resolution'] }}</p>@endforeach
<details wire:ignore.self><summary>要求覆盖证据 / 作者检查</summary>@include('filament.value-table',['value'=>['coverages'=>$v['content']['coverages'],'author_checks'=>$v['content']['author_checks']],'label'=>'逐字段只读资料'])</details>
<h3>变更影响（保守范围）</h3>@include('filament.value-table',['value'=>$v['impact'],'label'=>'逐字段只读资料'])
@if($v['validation'])<h3>最近检查</h3>@forelse($v['validation']['report']['blockers'] as $b)<p>{{ $b['code'] }} / {{ $b['field'] }}：{{ $b['message'] }}</p>@empty<p>该次检查无阻塞；批准时将重新检查。</p>@endforelse @else<p>请先运行检查。</p>@endif
@if($this->editable()){{ $this->validateAction }} @if($v['validation']&&$v['validation']['report']['passed']&&!$v['approval']){{ $this->approveAction }}@endif @endif
<x-nf.story-select id="ui06-blueprints-2-{{ md5('compareId') }}" label="比较版本" model="compareId" :value="data_get($__livewire,'compareId')" ><option value="">不比较</option>@foreach($this->options() as $id=>$label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</x-nf.story-select>
@if($compareId)@php($old=$this->comparison())<p>v{{ $old['version'] }} → v{{ $v['version'] }}；{{ $old['content_hash']===$v['content_hash']?'内容相同':'内容不同' }}</p>@include('filament.value-comparison',['leftValue'=>$old['content'],'rightValue'=>$v['content'],'comparisonLabel'=>'蓝图与终局版本逐字段比较'])@endif
</x-nf.section>@endif
</x-filament-panels::page>
