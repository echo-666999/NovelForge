<x-filament-panels::page>
    @include('filament.novel-navigation', ['novelId' => $novelId])
    <p>候选正文工作区。正式正文只读区：尚未实施正式提交，无正式版本。规则检查不等于评审或人工验收。</p>
    @if($notice)<p role="status">{{ $notice }}</p>@endif
    <section class="space-y-3 rounded-xl border p-4">
        <h2>章节与场景</h2>
        <label>状态 <select wire:model.live="status"><option value="">全部</option>@foreach(['planned','drafting','revision_required','stale'] as $value)<option>{{ $value }}</option>@endforeach</select></label>
        <label><input type="checkbox" wire:model.live="missing"> 缺章</label>
        <label><input type="checkbox" wire:model.live="wordIssue"> 字数不达标</label>
        <table class="w-full"><thead><tr><th>章序 / 标题</th><th>候选版本 / 字数</th><th>状态</th><th>正式 / 评审 / 人工验收</th></tr></thead><tbody>
        @foreach($this->listing()['items'] as $ch)
            <tr><td>{{ $ch['sequence'] }} / {{ $ch['title'] }}</td><td>{{ $ch['candidate']['id'] ?? '无' }} / {{ $ch['candidate']['character_count'] ?? 0 }}</td><td>{{ $ch['status'] }}</td><td>无 / 未实施 / 未验收</td></tr>
            @foreach($ch['scenes'] as $sc)<tr><td>场景 {{ $sc['id'] }} {{ $sc['title'] }}</td><td>{{ $sc['candidate']['id'] ?? '无' }} / {{ $sc['candidate']['character_count'] ?? 0 }}</td><td>{{ $sc['candidate']['display_status'] ?? 'planned' }}</td><td></td></tr>@endforeach
        @endforeach
        </tbody></table>
    </section>
    <section class="space-y-3 rounded-xl border p-4">
        <h2>编辑与比较</h2>
        <label>类型 <select wire:model="kind"><option value="chapter">章节</option><option value="scene">场景</option></select></label>
        <label>版本 ID <input wire:model="selected"></label><label>比较旧版本 ID <input wire:model="comparison"></label>
        <x-filament::button wire:click="inspect">读取版本</x-filament::button>
        @if($detail)
            <p>版本历史：@foreach($detail['history'] as $v) #{{ $v['id'] }}（v{{ $v['version'] }}） @endforeach</p>
            <div x-data="{ dirty: false }" x-on:input="dirty = true" x-on:draft-saved.window="dirty = false" x-on:beforeunload.window="if (dirty) { $event.preventDefault(); $event.returnValue = ''; }" wire:poll.15s="autoSave">
                <label class="block">具体标题 <input wire:model.live.debounce.500ms="bodyTitle"></label>
                <label class="block">候选正文 <textarea class="w-full" rows="18" wire:model.live.debounce.1000ms="body"></textarea></label>
                <p>可见码点：{{ $this->count() }}；{{ ($body !== $savedBody || $bodyTitle !== $savedTitle) ? '尚未保存（每15秒尝试自动保存）' : '候选已保存' }}</p>
                <p>哈希 {{ $detail['body_hash'] }}；来源 {{ $detail['parent_version_id'] ?? '初始' }}；{{ implode('、', $detail['report']['issues']) ?: '规则检查无问题，仍未评审' }}</p>
                <x-filament::button wire:click="save">保存为新候选版本</x-filament::button>
            </div>
            @if($previous)<h3>旧版本 v{{ $previous['version'] }}（与上方完整当前稿比较）</h3><pre class="whitespace-pre-wrap">{{ $previous['body'] }}</pre>@endif
        @endif
    </section>
    @php($options = $this->options())
    <section class="space-y-3 rounded-xl border p-4">
        <h2>场景离线生成验收</h2>
        <p>显式回放提供的离线正文，不调用真实模型。每次只处理一个场景；前场景未完成时不能跳过。</p>
        <label>冻结计划 <select wire:model="plan"><option value="">请选择</option>@foreach($options['plans'] as $v)<option value="{{ $v->id }}">{{ $v->id }} / v{{ $v->version }}</option>@endforeach</select></label>
        <label>场景 ID <input wire:model="scene"></label>
        <label>POV <select wire:model="pov"><option value="">请选择</option>@foreach($options['povs'] as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@endforeach</select></label>
        <label>离线路由 <select wire:model="route"><option value="">请选择</option>@foreach($options['routes'] as $v)<option value="{{ $v->id }}">{{ $v->key }}</option>@endforeach</select></label>
        <label>预算政策 <select wire:model="policy"><option value="">请选择</option>@foreach($options['policies'] as $v)<option value="{{ $v->id }}">{{ $v->key }}</option>@endforeach</select></label>
        <label>报价版本 ID（逗号分隔）<input wire:model="prices"></label>
        <label class="block">离线完整场景正文 <textarea class="w-full" rows="8" wire:model="offlineBody"></textarea></label>
        <label>备注 / 原因 <input wire:model="reason"></label>
        <x-filament::button wire:click="startScene">冻结并启动离线场景</x-filament::button>
        <label>运行 ID <input wire:model="run"></label><x-filament::button wire:click="replay">执行离线回放</x-filament::button>
        @if(ctype_digit($run))<a href="/admin/run-recovery?novel={{ $novelId }}&run={{ $run }}">查看恢复与证据</a>@endif
        <h3>章节组装</h3><label>章节 ID <input wire:model="chapter"></label><label>具体章名 <input wire:model="assemblyTitle"></label>
        <x-filament::button wire:click="assemble">按当前场景版本完整组装候选章</x-filament::button>
    </section>
</x-filament-panels::page>
