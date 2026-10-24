<x-filament-panels::page>
@include('filament.novel-navigation')
<p>当前仅回放明确提供的离线响应，用于检查规划流程；不是实际 AI 创作。候选不会自动批准或推进正式剧情。</p>
@php($options = $this->options())
<x-filament::section heading="准备离线规划">
<label>阶段<select wire:model.live="phase">@foreach($this::LABELS as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
@if(in_array($phase,['chapter_plan','scene_plan']))<label>起章<input type="number" min="1" wire:model="startChapter"></label><label>止章（每次最多十章）<input type="number" min="1" wire:model="endChapter"></label>@endif
<label>前阶段<select wire:model="parent"><option value="">无（输入提炼）</option>@foreach($this->listing() as $s)<option value="{{ $s['id'] }}">{{ $this::LABELS[$s['phase']] }} · {{ $s['id'] }}</option>@endforeach</select></label>
<label>已发布离线路由<select wire:model="route"><option value="">请选择</option>@foreach($options['routes'] as $r)<option value="{{ $r->id }}">{{ $r->key }} · {{ $r->id }}</option>@endforeach</select></label>
<label>预算政策<select wire:model="policy"><option value="">请选择</option>@foreach($options['policies'] as $p)<option value="{{ $p->id }}">{{ $p->key }} · {{ $p->id }}</option>@endforeach</select></label>
<label>价格版本（主备模型均须包含，逗号分隔）<input wire:model="prices"></label>
<details><summary>离线验收资料与固定结构（开发验证用）</summary><p>供应商配置必须为 fake，路由必须为 offline_fake，模板变量只含 context。未知或缺失价格、容量和结构契约均不能发送。</p><pre class="whitespace-pre-wrap">{{ $this->schemaPreview() }}</pre><textarea wire:model="offlineOutput" rows="12" class="w-full" aria-label="离线响应资料"></textarea></details>
<label>说明<textarea wire:model="reason" class="w-full"></textarea></label>
<x-filament::button wire:click="startCandidate">保存离线任务</x-filament::button>
</x-filament::section>
@if($notice)<p role="status">{{ $notice }}</p>@endif
<div class="flex gap-3"><label>当前候选<select wire:model="selected"><option value="">选择候选</option>@foreach($this->listing() as $s)<option value="{{ $s['id'] }}">{{ $this::LABELS[$s['phase']] }} · {{ $s['id'] }}</option>@endforeach</select></label><label>对比候选<select wire:model="comparison"><option value="">不对比</option>@foreach($this->listing() as $s)<option value="{{ $s['id'] }}">{{ $this::LABELS[$s['phase']] }} · {{ $s['id'] }}</option>@endforeach</select></label><x-filament::button wire:click="inspect">查看 / 比较</x-filament::button><x-filament::button wire:click="replay">执行离线回放</x-filament::button><x-filament::button wire:click="adopt">确认采纳为草稿</x-filament::button></div>
<div class="grid gap-4 md:grid-cols-2">@foreach(['当前'=>$detail,'对比'=>$previous] as $label=>$d)@if($d)<x-filament::section heading="{{ $label }}候选"><p>{{ $this::LABELS[$d['phase']] }} · {{ $d['data']['run']['status'] }} · {{ $d['adoption']?'已采纳':'未采纳' }}</p>@foreach($d['data']['steps'] as $step)<p>尝试{{ $step['attempt_no'] }} · {{ $step['status'] }} · {{ $step['error_code'] }}</p>@endforeach @foreach($d['data']['artifacts'] as $a)<details open><summary>{{ $a['type'] }} · {{ $a['content_hash'] }}</summary><pre class="whitespace-pre-wrap">{{ json_encode($a['content'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details>@endforeach @if($d['adoption'])<details><summary>采纳映射与覆盖建议</summary><pre class="whitespace-pre-wrap">{{ json_encode($d['adoption'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details>@endif<a href="/admin/recovery-desk?novel={{ $novelId }}&run={{ $d['data']['run']['id'] }}">恢复预览与重试决定</a></x-filament::section>@endif @endforeach</div>
</x-filament-panels::page>
