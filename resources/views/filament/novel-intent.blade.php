<x-filament-panels::page class="nf-story-screen">
@include('filament.novel-navigation')
<div wire:loading role="status">正在读取或保存本书资料，请稍候…</div>
@if($errors->any())<x-nf.state state="error" title="请核对字段，输入仍保留"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-nf.state>@endif
    @php($snapshot = $this->snapshot())
    <x-nf.section heading="当前规划与版本">
        <h2>{{ $snapshot['novel']['title'] }}</h2>
        <p>当前状态：{{ ['draft'=>'草稿','planning'=>'规划中','ready'=>'就绪','generating'=>'生成中','paused'=>'暂停','completed'=>'完成','archived'=>'已归档'][$snapshot['novel']['status']]??$snapshot['novel']['status'] }} · 正式基线以上方小说上下文为准</p>
        <p>规划冻结条件：{{ $snapshot['freeze_gate']['allowed']?'已满足':'尚未满足' }}。此门禁只表示规划冻结条件；生成与正式提交以对应页面实际校验为准。</p>
        <p>本页管理创作意图；可从上方导航查看工作台与承接计划。</p>
        <div class="nf-story-toolbar">{{ $this->sourceAction }} {{ $this->requirementAction }}</div>
    </x-nf.section>
    <x-nf.section heading="原始输入（历史只读）">
        @foreach ($snapshot['sources'] as $source)
            <details wire:key="source-{{ $source['id'] }}"><summary>原始输入 v{{ $source['version'] }} · {{ $source['reason'] }}</summary>
            @foreach ($source['input'] as $field => $value)
                <div><strong>{{ ['title'=>'书名','genre'=>'题材','background'=>'背景','premise_raw'=>'剧情概述','target_reader'=>'目标读者','selling_point'=>'核心卖点','forbidden_content'=>'禁用内容','target_chapters'=>'目标章节','length_policy'=>'章字数范围','style'=>'文风','ending_preference'=>'结局偏好','budget_note'=>'预算意向'][$field] ?? $field }}</strong>@if(is_array($value))@include('filament.value-table',['value'=>$value,'label'=>'原始输入逐字段只读'])@else<p class="nf-story-text">{{ $value }}</p>@endif</div>
            @endforeach
            </details>
        @endforeach
    </x-nf.section>
    <x-nf.section heading="要求与覆盖矩阵">
        <div class="nf-story-toolbar">
            <x-nf.story-select id="ui06-novel-intent-1-{{ md5('strength') }}" label="强度" model="strength" :value="data_get($__livewire,'strength')" ><option value="">全部</option><option value="hard">硬约束</option><option value="preference">偏好</option><option value="undecided">待决定</option></x-nf.story-select>
            <x-nf.story-select id="ui06-novel-intent-2-{{ md5('decision') }}" label="决定" model="decision" :value="data_get($__livewire,'decision')" ><option value="">全部</option><option value="unresolved">待决定</option><option value="accepted">已接受</option><option value="conflict">冲突</option><option value="waived">不适用</option></x-nf.story-select>
            <x-nf.story-select id="ui06-novel-intent-3-{{ md5('coverage') }}" label="覆盖" model="coverage" :value="data_get($__livewire,'coverage')" ><option value="">全部</option><option value="unresolved">未覆盖</option><option value="covered">已覆盖</option><option value="stale">覆盖已过期</option><option value="conflict">冲突</option><option value="waived">不适用</option></x-nf.story-select>
            <x-nf.story-select id="ui06-novel-intent-4-{{ md5('source') }}" label="来源" model="source" :value="data_get($__livewire,'source')" ><option value="">全部</option>@foreach($snapshot['sources'] as $source)<option value="{{ $source['id'] }}">v{{ $source['version'] }}</option>@endforeach</x-nf.story-select>
        </div>
        @php($rows = $this->rows())
        <x-nf.story-select id="ui06-novel-intent-5-{{ md5('perPage') }}" label="每页" model="perPage" :value="data_get($__livewire,'perPage')" ><option>10</option><option>25</option><option>50</option><option>100</option></x-nf.story-select>
        <x-nf.table label="故事资料列表"><table class="w-full text-left"><thead><tr><th>要求原句 / 提炼</th><th>归类 / 强度</th><th>冲突 / 作者决定</th><th>承接计划 / 覆盖证据</th><th>操作</th></tr></thead><tbody>
        @forelse ($rows as $row)
            <tr wire:key="requirement-{{ $row['id'] }}"><td><p class="whitespace-pre-wrap">{{ $row['source_quote'] }}</p><p>{{ $row['content'] }}</p><small>要求 v{{ $row['version'] }}</small></td><td>{{ $row['category'] }} / {{ ['hard'=>'硬约束','preference'=>'偏好','undecided'=>'待决定'][$row['strength']] }}</td><td>{{ $row['conflict'] }}<br>{{ $row['author_decision'] }}<br>{{ ['unresolved'=>'待决定','accepted'=>'已接受','conflict'=>'冲突','waived'=>'不适用'][$row['decision_status']] }}</td><td>{{ ['unresolved'=>'未覆盖','conflict'=>'冲突','waived'=>'不适用','covered'=>'已覆盖','stale'=>'覆盖已过期'][$row['coverage_status']] }}<br>@if($row['plan_url'])<a href="{{ $row['plan_url'] }}">查看承接计划与证据</a>@else 无覆盖证据 @endif</td><td>@if($snapshot['novel']['status'] !== 'archived')<x-filament::button size="sm" wire:click="mountAction('requirement', { requirement: '{{ $row['requirement_id'] }}' })">修正 / 决定</x-filament::button>@endif</td></tr>
        @empty <tr><td colspan="5">没有符合筛选条件的要求。</td></tr> @endforelse
        </tbody></table></x-nf.table>
        {{ $rows->links() }}
    </x-nf.section>
    <x-nf.section heading="要求版本记录">
        @foreach($this->history() as $row)
            <details wire:key="history-{{ $row['id'] }}"><summary>要求 #{{ $row['requirement_id'] }} · v{{ $row['version'] }} · {{ $row['reason'] }}</summary><p>{{ $row['content'] }}</p><p>{{ $row['conflict'] }}</p><p>{{ $row['author_decision'] }}</p></details>
        @endforeach
    </x-nf.section>
</x-filament-panels::page>
