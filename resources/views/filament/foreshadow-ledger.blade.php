<x-filament-panels::page>
    {{-- 所有作者内容使用转义输出；不将隐藏含义送入角色上下文。 --}}
    <p>作者规划区。正式提交尚未接入，埋设、强化和回收证据均未成立；预期知情范围不代表人物已知。</p>
    <label>小说 <select wire:model.live="novel"><option value="">请选择小说</option>@foreach($this->books() as $book)<option value="{{ $book->id }}">{{ $book->title }}</option>@endforeach</select></label>
    @if($novel === '')
        <p>选择小说后查看伏笔承诺与主支线。暂无作品时，请先创建小说和蓝图。</p>
    @else
        @php($ledger = $this->ledger())
        @php($options = $this->options())
        <div class="grid gap-4 md:grid-cols-3">
            <label>调度预览章（非正式游标）<input type="number" min="1" max="100000" wire:model.live="chapter"></label>
            <label>提示筛选 <select wire:model.live="dueFilter"><option value="">全部</option><option value="due">到期</option><option value="overdue">逾期</option><option value="no_evidence">无正式证据</option></select></label>
            <label><input type="checkbox" wire:model.live="requiredOnly">仅必回收</label>
            <label>生命周期 <select wire:model.live="lifecycleFilter"><option value="">全部</option><option value="planned">计划</option><option value="abandoned">已批准放弃</option></select></label>
            <label>关联实体 <select wire:model.live="entityFilter"><option value="">全部</option>@foreach($options['entities'] as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>@endforeach</select></label>
            <label>回收窗口起 <input type="number" min="1" wire:model.live="from"></label><label>回收窗口止 <input type="number" min="1" wire:model.live="to"></label>
        </div>
        <div class="overflow-x-auto"><table class="w-full"><thead><tr><th>伏笔 / 版本</th><th>回收目标</th><th>规划状态</th><th>实际埋设 / 强化</th><th>正式证据数</th><th>提示</th><th>操作</th></tr></thead><tbody>
        @forelse($ledger['items'] as $item)
            <tr><td>{{ $item['name'] }} / V{{ $item['version'] }}</td><td>{{ $item['payoff_target'] }}（{{ $item['payoff_start'] }}–{{ $item['payoff_end'] }}）</td><td>{{ $item['lifecycle']==='abandoned'?'已批准放弃':'计划' }} {{ $item['frozen']?'已冻结':'' }}</td><td>无 / 无</td><td>{{ $item['evidence_count'] }}</td><td>{{ $item['overdue']?'逾期，缺回收证据':($item['due']?'到期':'') }} {{ $item['priority']==='high'?'高优先级':($item['priority']==='groundwork_check'?'检查铺垫':'') }}@foreach($item['suggestions'] as $s)<p>{{ $s }}</p>@endforeach</td><td><x-filament::button size="sm" wire:click="inspect('{{ $item['id'] }}')">查看版本与证据</x-filament::button></td></tr>
        @empty<tr><td colspan="7">暂无符合条件的伏笔计划。可以创建计划；不会生成正文或标记已埋设。</td></tr>@endforelse
        </tbody></table></div>
        <x-filament::section heading="主支线承诺与终局（批准蓝图）">
            <label>类型 <select wire:model.live="threadKind"><option value="">全部</option><option value="main">主线</option><option value="sub">支线</option></select></label>
            @forelse($ledger['threads'] as $thread)<div><strong>{{ $thread['kind']==='main'?'主线':'支线' }} #{{ $thread['thread_id'] }}</strong><p>目标：{{ $thread['content']['goal'] ?? '' }}；计划解决：{{ $thread['content']['resolution'] ?? '' }}</p><p>转折：{{ $thread['content']['turning_points'] ?? '' }}；人物弧：{{ $thread['content']['character_arc'] ?? '' }}</p><p>关联伏笔版本：{{ implode('、',$thread['linked_plan_ids']) ?: '未关联' }}；正式证据：0；闭合状态：尚未接入。</p></div>@empty<p>没有已批准蓝图的主支线承诺，请先完成蓝图批准。</p>@endforelse
            @if($ledger['ending'])<p>终局窗口：{{ $ledger['ending']['content']['start_chapter'] }}–{{ $ledger['ending']['content']['end_chapter'] }}；目标 {{ $ledger['ending']['content']['target_chapters'] }} 章</p><p>最终选择：{{ $ledger['ending']['content']['final_choice'] }}</p><p>必回收文本承诺：{{ implode('、',$ledger['ending']['content']['required_foreshadows']) }}</p><p>开放项：{{ implode('、',$ledger['ending']['content']['open_items']) }}</p><p>文本承诺未自动按名称绑定伏笔；终局完成门禁待后续任务接入。</p>@endif
        </x-filament::section>
        <x-filament::section heading="创建伏笔或编辑改期候选">
            @if(!$options['blueprints'])<p>缺少蓝图，请先保存并批准蓝图。</p>@endif
            <div class="grid gap-4 md:grid-cols-2">
            <label>蓝图版本 <select wire:model.live="form.blueprint_version_id"><option value="">请选择</option>@foreach($options['blueprints'] as $b)<option value="{{ $b->id }}">V{{ $b->version }} / #{{ $b->id }}</option>@endforeach</select></label>
            <label>主支线 <select wire:model="form.thread_id"><option value="">未关联</option>@foreach($options['threads'] as $t)<option value="{{ $t->thread_id }}">{{ $t->kind }} #{{ $t->thread_id }}</option>@endforeach</select></label>
            @foreach(['name'=>'伏笔名称','surface_clue'=>'表面线索','hidden_meaning'=>'隐藏含义','misdirection'=>'预期读者误导','no_early_reveal'=>'禁止提前揭示','causal_role'=>'终局因果作用','prerequisite_description'=>'前置条件说明'] as $field=>$label)<label>{{ $label }}<textarea wire:model="form.{{ $field }}" class="w-full"></textarea></label>@endforeach
            @foreach(['plant_start'=>'埋设起章','plant_end'=>'埋设止章','payoff_target'=>'回收目标章','payoff_start'=>'回收窗口起章','payoff_end'=>'回收窗口止章'] as $field=>$label)<label>{{ $label }}<input type="number" min="1" max="100000" wire:model="form.{{ $field }}"></label>@endforeach
            <label><input type="checkbox" wire:model="form.required">必须回收</label>
            <label>重要程度 <select wire:model="form.importance"><option value="major">重要</option><option value="minor">次要</option></select></label>
            <label>保密级别 <select wire:model="form.secrecy"><option value="author_only">仅作者</option><option value="reader_hint">读者可见线索</option><option value="character_limited">限定角色</option></select></label>
            @foreach(['entity_version_ids'=>'关联实体版本','knower_version_ids'=>'预期知情角色版本'] as $field=>$label)<label>{{ $label }}<select multiple wire:model="form.{{ $field }}">@foreach($options['entities'] as $e)@if($field!=='knower_version_ids'||$e->type==='character')<option value="{{ $e->approved_version_id }}">{{ $e->name }} / 定义 #{{ $e->approved_version_id }}</option>@endif@endforeach</select></label>@endforeach
            <label>前置候选事件（仍须正式证据）<select multiple wire:model="form.prerequisite_event_ids">@foreach($options['events'] as $e)<option value="{{ $e->id }}">#{{ $e->id }} / 第{{ $e->chapter_sequence }}章 {{ $e->attribute }}</option>@endforeach</select></label>
            </div>
            @foreach($form['reinforcements'] as $i=>$w)<div wire:key="reinforcement-{{ $i }}"><label>强化起章<input type="number" wire:model="form.reinforcements.{{ $i }}.start"></label><label>止章<input type="number" wire:model="form.reinforcements.{{ $i }}.end"></label><label>新增信息<textarea wire:model="form.reinforcements.{{ $i }}.new_information"></textarea></label><label>选择影响<textarea wire:model="form.reinforcements.{{ $i }}.choice_impact"></textarea></label><x-filament::button wire:click="removeReinforcement({{ $i }})">移除此强化候选</x-filament::button></div>@endforeach
            <x-filament::button wire:click="addReinforcement">增加强化窗口</x-filament::button>
            <label>操作理由<textarea wire:model="reason" class="w-full"></textarea></label><label>放弃后的替代安排<textarea wire:model="alternative" class="w-full"></textarea></label>
            <x-filament::button wire:click="createPlan">创建新伏笔</x-filament::button>
            @if($detail && $detail['novel_id']===$novel)<x-filament::button wire:click="freezePlan">冻结选中版本</x-filament::button><x-filament::button wire:click="propose('reschedule')">提出改期候选</x-filament::button><x-filament::button wire:click="propose('abandon')">提出放弃候选</x-filament::button>@endif
            <x-filament::button color="gray" wire:click="refreshVersion">刷新操作基线</x-filament::button><p>{{ $notice }}</p>
        </x-filament::section>
        @if($detail && $detail['novel_id']===$novel)
            <x-filament::section heading="历史版本、冻结承诺与作者决定">
                <p>当前选择：{{ $detail['name'] }} V{{ $detail['version'] }}；跨章正式证据为空。提及或解释均不能代替因果回收。</p>
                @foreach($detail['history'] as $h)<p><button wire:click="inspect('{{ $h['id'] }}')">V{{ $h['version'] }} / 回收 {{ $h['payoff_start'] }}–{{ $h['payoff_end'] }} / {{ $h['reason'] }}</button></p>@endforeach
                @foreach($detail['freezes'] as $f)<p>冻结版本 #{{ $f['plan_id'] }}；哈希 {{ $f['content_hash'] }}；理由 {{ $f['reason'] }}</p>@endforeach
                @foreach($detail['proposals'] as $q)<div><p>候选 #{{ $q['id'] }}：{{ $q['kind']==='abandon'?'放弃':'改期' }}；原版本 #{{ $q['plan_id'] }}；新窗口 {{ $q['payload']['payoff_start'] }}–{{ $q['payload']['payoff_end'] }}；理由 {{ $q['reason'] }}；替代安排 {{ $q['alternative'] }}</p>@if($q['decision'])<p>作者决定：{{ $q['decision']['decision']==='approve'?'批准':'拒绝' }} / {{ $q['decision']['reason'] }}</p>@else<x-filament::button wire:click="decide('{{ $q['id'] }}','approve')">批准候选</x-filament::button><x-filament::button color="gray" wire:click="decide('{{ $q['id'] }}','reject')">拒绝候选</x-filament::button>@endif</div>@endforeach
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
