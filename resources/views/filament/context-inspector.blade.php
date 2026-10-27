<x-filament-panels::page class="nf-story-screen nf-production-screen"><div class="nf-production-layout">
    @include('filament.book-navigation', ['navigation' => $this->bookNavigation()])
<div wire:loading role="status">正在读取或保存，请稍候…</div>
@if($errors->any())<x-nf.state state="error" title="请核对字段，输入仍保留"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-nf.state>@endif
    <p>离线上下文检查。作者计划与隐藏真相不代表角色知情；硬约束不可移除。正式来源按当前活动基线读取，索引延迟时强制补齐状态与最近正文。</p>
    <div class="nf-story-toolbar">
        <select wire:model.live="step" aria-label="阶段"><option value="">选择阶段</option>@foreach($this->steps() as $s)<option value="{{ $s->id }}">{{ $s->title }} · {{ $s->stage }} · {{ $s->scope_key }}（{{ $s->id }}）</option>@endforeach</select>
        @include('filament.production-reference',['label'=>'清单编号','model'=>'manifest','kind'=>'manifest','multiple'=>false])
        <x-filament::button wire:loading.attr="disabled" wire:click="inspect">查看清单</x-filament::button>
        <x-filament::button wire:loading.attr="disabled" wire:click="reassemble">重新组装新清单</x-filament::button>
    </div>
    @if($notice)<p role="status">{{ $notice }}</p>@endif
    @if($detail)
        <p>状态：{{ $detail['current_status'] }}；章：{{ $detail['chapter'] }}；场景：{{ $detail['scene_id'] }}；POV：{{ $detail['pov_id'] }}；状态版本：{{ $detail['state_version'] }}；来源 {{ $detail['scope'] }}；记忆待处理 {{ ($detail['memory_pending'] ?? false) ? '是' : '否' }}</p>
        <p>估算输入 {{ $detail['budget']['input_tokens'] }} + 输出 {{ $detail['budget']['max_output_tokens'] }} + 单列推理 {{ $detail['budget']['separate_reasoning_tokens'] }} + 安全余量 {{ $detail['budget']['safety_margin'] }} / 容量 {{ $detail['budget']['context_tokens'] }}。按 UTF-8 字节保守估算，实际误差未知。</p>
        <p>阻塞原因：{{ implode('、', $detail['issues']) }}</p>
        <div class="nf-story-toolbar">
            <label class="nf-story-check"><input type="checkbox" wire:model.live="requiredOnly">仅强制条目</label>
            <select wire:model.live="category" aria-label="类别"><option value="">全部类别与记忆类型</option>@foreach(array_unique(array_column($detail['items'], 'category')) as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select>
            <select wire:model.live="channel" aria-label="检索通道"><option value="">全部通道</option>@foreach(['exact','alias','trgm','vector'] as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select>
            <select wire:model.live="trim" aria-label="裁剪原因"><option value="">全部裁剪原因</option><option value="duplicate">重复</option><option value="low_priority_capacity">容量不足</option></select>
        </div>
        <div><x-nf.table label="生产资料列表"><table class="w-full text-left"><thead><tr><th>顺序 / 类别</th><th>来源 / 版本</th><th>分区</th><th>Token 估算 / 分数</th><th>纳入与裁剪理由</th><th>原文</th></tr></thead><tbody>
        @foreach($this->items() as $i)<tr><td>{{ $i['final_order'] ?? '未入选' }} / {{ $i['category'] }}</td><td>{{ $i['source_type'] }} #{{ $i['source_id'] }} / {{ $i['source_version'] }}</td><td>{{ $i['zone'] }}</td><td>{{ $i['token_count'] }} / {{ $i['score'] ?? '强制读取' }}</td><td>{{ $i['inclusion_reason'] }} {{ $i['trim_reason'] }}</td><td><details wire:ignore.self><summary>定位冻结原文</summary>{{-- 结构化来源逐字段只读；正文/事实陈述仍逐字展示，不按外观猜类型。 --}}
@if(in_array($i['category'],['system_contract','hard_constraint','scene_plan','state','candidate_state','knowledge_relationships','foreshadow','ending','style'],true)||in_array($i['source_type'],['proposed_events','story_events','canonical_memories'],true))
@include('filament.production-text',['text'=>$i['text'],'label'=>'冻结来源字段只读'])
@else<div class="nf-manuscript" tabindex="0">{{ $i['text'] }}</div>@endif<p>{{ $i['source_hash'] }}</p></details></td></tr>@endforeach
        </tbody></table></x-nf.table></div>
        <details wire:ignore.self><summary>最终 Prompt 预览（不含供应商凭据）</summary><div class="nf-manuscript" tabindex="0">{{ $detail['final_prompt'] }}</div><p>{{ $detail['final_prompt_hash'] }}</p></details>
        <div>@include('filament.production-reference',['label'=>'比较清单编号','model'=>'comparison','kind'=>'manifest','multiple'=>false])<x-filament::button wire:loading.attr="disabled" wire:click="compare">比较两次组装</x-filament::button></div>
    @endif
    @if($difference)<p>最终文本{{ $difference['same_prompt'] ? '相同' : '不同' }}</p><div class="nf-story-fields">@foreach(['left' => '之前', 'right' => '之后'] as $k => $label)<div><h3>{{ $label }}</h3><div class="nf-manuscript" tabindex="0">{{ $difference[$k]['final_prompt'] }}</div></div>@endforeach</div>@endif
</div></x-filament-panels::page>
