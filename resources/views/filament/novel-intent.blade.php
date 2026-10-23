<x-filament-panels::page>
    @php($snapshot = $this->snapshot())
    <x-filament::section>
        <h2>{{ $snapshot['novel']['title'] }}</h2>
        <p>当前状态：{{ $snapshot['novel']['status'] }} · 正式基线：尚未建立</p>
        <p>{{ $snapshot['freeze_gate']['message'] }}</p>
        <p>本页只管理创作意图。规划工作台尚未接入。</p>
        <div class="flex gap-3">{{ $this->sourceAction }} {{ $this->requirementAction }}</div>
    </x-filament::section>
    <x-filament::section heading="原始输入（历史只读）">
        @foreach ($snapshot['sources'] as $source)
            <details wire:key="source-{{ $source['id'] }}"><summary>原始输入 v{{ $source['version'] }} · {{ $source['reason'] }}</summary>
            @foreach ($source['input'] as $field => $value)
                <div><strong>{{ ['title'=>'书名','genre'=>'题材','background'=>'背景','premise_raw'=>'剧情概述','target_reader'=>'目标读者','selling_point'=>'核心卖点','forbidden_content'=>'禁用内容','target_chapters'=>'目标章节','length_policy'=>'章字数范围','style'=>'文风','ending_preference'=>'结局偏好','budget_note'=>'预算意向'][$field] ?? $field }}</strong><pre class="whitespace-pre-wrap break-words">{{ is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value }}</pre></div>
            @endforeach
            </details>
        @endforeach
    </x-filament::section>
    <x-filament::section heading="要求与覆盖矩阵">
        <div class="flex flex-wrap gap-3">
            <label>强度 <select wire:model.live="strength"><option value="">全部</option><option value="hard">硬约束</option><option value="preference">偏好</option><option value="undecided">待决定</option></select></label>
            <label>决定 <select wire:model.live="decision"><option value="">全部</option><option value="unresolved">待决定</option><option value="accepted">已接受</option><option value="conflict">冲突</option><option value="waived">不适用</option></select></label>
            <label>覆盖 <select wire:model.live="coverage"><option value="">全部</option><option value="unresolved">未覆盖</option><option value="conflict">冲突</option><option value="waived">不适用</option></select></label>
            <label>来源 <select wire:model.live="source"><option value="">全部</option>@foreach($snapshot['sources'] as $source)<option value="{{ $source['id'] }}">v{{ $source['version'] }}</option>@endforeach</select></label>
        </div>
        @php($rows = $this->rows())
        <label>每页 <select wire:model.live="perPage"><option>10</option><option>25</option><option>50</option><option>100</option></select></label>
        <div class="overflow-x-auto"><table class="w-full text-left"><thead><tr><th>要求原句 / 提炼</th><th>归类 / 强度</th><th>冲突 / 作者决定</th><th>承接计划 / 覆盖证据</th><th>操作</th></tr></thead><tbody>
        @forelse ($rows as $row)
            <tr wire:key="requirement-{{ $row['id'] }}"><td><p class="whitespace-pre-wrap">{{ $row['source_quote'] }}</p><p>{{ $row['content'] }}</p><small>要求 v{{ $row['version'] }}</small></td><td>{{ $row['category'] }} / {{ ['hard'=>'硬约束','preference'=>'偏好','undecided'=>'待决定'][$row['strength']] }}</td><td>{{ $row['conflict'] }}<br>{{ $row['author_decision'] }}<br>{{ ['unresolved'=>'待决定','accepted'=>'已接受','conflict'=>'冲突','waived'=>'不适用'][$row['decision_status']] }}</td><td>{{ ['unresolved'=>'未覆盖','conflict'=>'冲突','waived'=>'不适用'][$row['coverage_status']] }}<br>计划模块尚未接入，无覆盖证据</td><td>@if($snapshot['novel']['status'] !== 'archived')<x-filament::button size="sm" wire:click="mountAction('requirement', { requirement: '{{ $row['requirement_id'] }}' })">修正 / 决定</x-filament::button>@endif</td></tr>
        @empty <tr><td colspan="5">没有符合筛选条件的要求。</td></tr> @endforelse
        </tbody></table></div>
        {{ $rows->links() }}
    </x-filament::section>
    <x-filament::section heading="要求版本记录">
        @foreach($this->history() as $row)
            <details wire:key="history-{{ $row['id'] }}"><summary>要求 #{{ $row['requirement_id'] }} · v{{ $row['version'] }} · {{ $row['reason'] }}</summary><p>{{ $row['content'] }}</p><p>{{ $row['conflict'] }}</p><p>{{ $row['author_decision'] }}</p></details>
        @endforeach
    </x-filament::section>
</x-filament-panels::page>
