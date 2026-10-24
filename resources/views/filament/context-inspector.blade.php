<x-filament-panels::page>
    <p>离线上下文检查。作者计划与隐藏真相不代表角色知情；硬约束不可移除。正式来源尚未接入。</p>
    <div class="flex flex-wrap gap-3">
        <select wire:model.live="step" aria-label="阶段"><option value="">选择阶段</option>@foreach($this->steps() as $s)<option value="{{ $s->id }}">{{ $s->title }} · {{ $s->stage }} · {{ $s->scope_key }}（{{ $s->id }}）</option>@endforeach</select>
        <input wire:model="manifest" placeholder="清单编号，留空读取最新" aria-label="清单编号">
        <x-filament::button wire:click="inspect">查看清单</x-filament::button>
        <x-filament::button wire:click="reassemble">重新组装新清单</x-filament::button>
    </div>
    @if($notice)<p role="status">{{ $notice }}</p>@endif
    @if($detail)
        <p>状态：{{ $detail['current_status'] }}；章：{{ $detail['chapter'] }}；场景：{{ $detail['scene_id'] }}；POV：{{ $detail['pov_id'] }}；状态版本：{{ $detail['state_version'] }}</p>
        <p>估算输入 {{ $detail['budget']['input_tokens'] }} + 输出 {{ $detail['budget']['max_output_tokens'] }} + 单列推理 {{ $detail['budget']['separate_reasoning_tokens'] }} + 安全余量 {{ $detail['budget']['safety_margin'] }} / 容量 {{ $detail['budget']['context_tokens'] }}。按 UTF-8 字节保守估算，实际误差未知。</p>
        <p>阻塞原因：{{ implode('、', $detail['issues']) }}</p>
        <div class="flex flex-wrap gap-3">
            <label><input type="checkbox" wire:model.live="requiredOnly">仅强制条目</label>
            <select wire:model.live="category" aria-label="类别"><option value="">全部类别与记忆类型</option>@foreach(array_unique(array_column($detail['items'], 'category')) as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select>
            <select wire:model.live="channel" aria-label="检索通道"><option value="">全部通道</option>@foreach(['exact','alias','trgm','vector'] as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select>
            <select wire:model.live="trim" aria-label="裁剪原因"><option value="">全部裁剪原因</option><option value="duplicate">重复</option><option value="low_priority_capacity">容量不足</option></select>
        </div>
        <div class="overflow-x-auto"><table class="w-full text-left"><thead><tr><th>顺序 / 类别</th><th>来源 / 版本</th><th>分区</th><th>Token 估算 / 分数</th><th>纳入与裁剪理由</th><th>原文</th></tr></thead><tbody>
        @foreach($this->items() as $i)<tr><td>{{ $i['final_order'] ?? '未入选' }} / {{ $i['category'] }}</td><td>{{ $i['source_type'] }} #{{ $i['source_id'] }} / {{ $i['source_version'] }}</td><td>{{ $i['zone'] }}</td><td>{{ $i['token_count'] }} / {{ $i['score'] ?? '强制读取' }}</td><td>{{ $i['inclusion_reason'] }} {{ $i['trim_reason'] }}</td><td><details><summary>定位冻结原文</summary><pre class="whitespace-pre-wrap">{{ $i['text'] }}</pre><p>{{ $i['source_hash'] }}</p></details></td></tr>@endforeach
        </tbody></table></div>
        <details><summary>最终 Prompt 预览（不含供应商凭据）</summary><pre class="whitespace-pre-wrap">{{ $detail['final_prompt'] }}</pre><p>{{ $detail['final_prompt_hash'] }}</p></details>
        <div><input wire:model="comparison" placeholder="另一清单编号" aria-label="比较清单编号"><x-filament::button wire:click="compare">比较两次组装</x-filament::button></div>
    @endif
    @if($difference)<p>最终文本{{ $difference['same_prompt'] ? '相同' : '不同' }}</p><div class="grid gap-4 md:grid-cols-2">@foreach(['left' => '之前', 'right' => '之后'] as $k => $label)<div><h3>{{ $label }}</h3><pre class="whitespace-pre-wrap">{{ $difference[$k]['final_prompt'] }}</pre></div>@endforeach</div>@endif
</x-filament-panels::page>
