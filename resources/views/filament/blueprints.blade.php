<x-filament-panels::page>
@php($list=$this->listing())
<x-filament::section heading="蓝图与终局契约规划">
<p>先手工规划、检查，再批准冻结。历史内容始终只读；批准记录不等于当前依赖仍有效，生成前仍需后续门禁。</p>
<p>前十章场景细节、字数预算及生成资格由B05及后续任务接入。终局承诺目前仅为规划，不显示虚构回收率。</p>{{ $this->saveAction }}
<label>状态筛选<select wire:model.live="status"><option value="">全部</option>@foreach($this::STATUS as $k=>$v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></label>
<table class="w-full text-left"><thead><tr><th>版本 / 来源</th><th>一句话 / 原因</th><th>状态 / 检查</th><th>动作</th></tr></thead><tbody>
@foreach($list['items'] as $v)@if(!$status||$status===$v['display_status'])<tr wire:key="blueprint-{{ $v['id'] }}"><td>v{{ $v['version'] }} · 手工<p>输入#{{ $v['source_version_id'] }} / 结构#{{ $v['outline_version_id'] }}</p><p>{{ $v['created_at'] }}</p></td><td>{{ $v['content']['body']['logline'] }}<p>{{ $v['reason'] }}</p></td><td>{{ $this::STATUS[$v['display_status']] }}<p>{{ $v['validation']?($v['validation']['report']['passed']?'检查通过（历史记录）':'存在阻塞'):'尚未检查' }}</p></td><td><x-filament::button wire:click="$set('selectedId','{{ $v['id'] }}')">详情 / 影响</x-filament::button>@if($this->editable())<x-filament::button wire:click="mountAction('save',{blueprint:'{{ $v['id'] }}'})">复制修改</x-filament::button><x-filament::button wire:click="mountAction('validate',{blueprint:'{{ $v['id'] }}'})">检查</x-filament::button>@endif</td></tr>@endif@endforeach
</tbody></table>
@if($cursor)<x-filament::button wire:click="$set('cursor','')">返回首页</x-filament::button>@endif @if($list['next_cursor'])<x-filament::button wire:click="$set('cursor','{{ $list['next_cursor'] }}')">下一页</x-filament::button>@endif
</x-filament::section>
@if($selectedId)@php($v=$this->selected())
<x-filament::section heading="蓝图内容与终局承诺">
<p>蓝图v{{ $v['version'] }} / 终局契约v{{ $v['ending_contract']['version'] }}；{{ $this::STATUS[$v['display_status']] }}</p>
@foreach($this::FIELDS as $k=>$label)<details><summary>{{ $label }}</summary><p class="whitespace-pre-wrap">{{ $v['content']['body'][$k] }}</p></details>@endforeach
<p>结束窗口：第{{ $v['content']['ending']['start_chapter'] }}—{{ $v['content']['ending']['end_chapter'] }}章；目标{{ $v['content']['ending']['target_chapters'] }}章</p>
@foreach($this::ENDING as $k=>$label)<p>{{ $label }}：{{ $v['content']['ending'][$k] }}</p>@endforeach
@foreach($v['content']['ending']['outcomes'] as $o)<p>人物设定#{{ $o['entity_definition_version_id'] }}结局：{{ $o['outcome'] }}</p>@endforeach
<p>必须回收：{{ implode('；',$v['content']['ending']['required_foreshadows']) }}</p><p>允许开放：{{ implode('；',$v['content']['ending']['open_items']) }}</p>
@foreach($v['content']['threads'] as $t)<p>{{ $t['kind']==='main'?'主线':'支线' }} {{ $t['identity_snapshot']['title'] }}：{{ $t['goal'] }} → {{ $t['resolution'] }}</p>@endforeach
<details><summary>要求覆盖证据 / 作者检查</summary><pre class="whitespace-pre-wrap">{{ json_encode(['coverages'=>$v['content']['coverages'],'author_checks'=>$v['content']['author_checks']],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details>
<h3>变更影响（保守范围）</h3><pre class="whitespace-pre-wrap">{{ json_encode($v['impact'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>
@if($v['validation'])<h3>最近检查</h3>@forelse($v['validation']['report']['blockers'] as $b)<p>{{ $b['code'] }} / {{ $b['field'] }}：{{ $b['message'] }}</p>@empty<p>该次检查无阻塞；批准时将重新检查。</p>@endforelse @else<p>请先运行检查。</p>@endif
@if($this->editable()){{ $this->validateAction }} @if($v['validation']&&$v['validation']['report']['passed']&&!$v['approval']){{ $this->approveAction }}@endif @endif
<label>比较版本<select wire:model.live="compareId"><option value="">不比较</option>@foreach($this->options() as $id=>$label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select></label>
@if($compareId)@php($old=$this->comparison())<p>v{{ $old['version'] }} → v{{ $v['version'] }}；{{ $old['content_hash']===$v['content_hash']?'内容相同':'内容不同' }}</p><details><summary>比较版本的完整内容</summary><pre class="whitespace-pre-wrap">{{ json_encode($old['content'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details>@endif
</x-filament::section>@endif
</x-filament-panels::page>
