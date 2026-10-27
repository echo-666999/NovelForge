<x-filament-panels::page x-data="{}" x-on:extraction-editor-focused.window="$nextTick(()=>{const row=document.getElementById($event.detail.id);row?.scrollIntoView({block:'start'});row?.focus({preventScroll:true})})">
    @include('filament.book-navigation', ['navigation' => $this->bookNavigation()])
    <p>从实际正文核对事实与原文证据。此页只生成和回放候选，不提交正式剧情。</p>
    @if($notice)<x-nf.state :state="$noticeState" title="操作结果">{{ $notice }}</x-nf.state>@endif
    @if($errors->any())<x-nf.state state="error" title="请核对字段，已填内容保留"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-nf.state>@endif
    <div wire:loading role="status">正在读取或验证，请稍候；不会自动提交正式状态…</div>
    @if($novel==='')
        <x-nf.state state="empty" title="先选择当前小说">使用上方小说选择器进入本书，再选择实际章节正文。</x-nf.state>
    @else
    @php
        $loadError=false;
        try { $selections=$this->selections(); $options=$this->options(); $history=$this->listing(); }
        catch (\Throwable $error) { report($error); $loadError=true; }
    @endphp
    @if($loadError)
        <x-nf.state state="error" title="提取资料读取失败">未将失败当作空列表，草稿仍保留。请重试。</x-nf.state>
        <x-filament::button wire:click="$refresh">重新读取资料</x-filament::button>
    @else
    <x-nf.section heading="1 · 选择章节与证据来源">
        <x-nf.form-grid>
            <x-nf.select id="extraction-version" label="章节正文版本" model="version" :value="$version" :options="[''=>'请选择实际正文']+$selections['versions']" />
            <x-nf.select id="extraction-baseline" label="紧邻前章有效候选" model="baseline" :value="$baseline" :options="[''=>'第1章可留空；后续章必须选择']+$selections['baselines']" />
            <x-nf.select id="extraction-parent" label="同正文重新提取来源" model="parent" :value="$parent" :options="[''=>'新提取，不替代历史']+$selections['parents']" />
            <x-nf.select id="extraction-rules" label="作者时间线与规则上下文" model="ruleContext" :value="$ruleContext" :options="[''=>'未选择作者规则']+$selections['contexts']" />
        </x-nf.form-grid>
        <x-filament::button wire:click="loadBody" wire:loading.attr="disabled" :disabled="$version===''">读取实际正文</x-filament::button>
        @if(!$selections['versions'])<x-nf.state state="empty" title="本书暂无章节正文">先在章节编辑与组装入口形成实际正文版本，再返回核对。</x-nf.state>@endif
        @if($body)
            <p>只读原文 #{{ $body['id'] }} · {{ $body['title'] }} · {{ $body['stale']?'来源已过期':'来源已读取，保存时仍会重验' }}</p>
            <p class="nf-break">原文指纹：{{ $body['body_hash'] }}</p>
            <div class="nf-source-reading" tabindex="0" aria-label="只读章节原文">{{ $body['body'] }}</div>
            <p>证据偏移从0开始，以Unicode码点计算，结束位置不包含；不修改原文制造证据。</p>
        @else<p>读取后在此核对正文。未选择正文时验证按钮禁用。</p>@endif
    </x-nf.section>
    <div id="nf-extraction-editor" tabindex="-1"><x-nf.section heading="2 · 编辑候选事实与关联">
        <p>每组按条目编辑；先填写稳定事件键，再选择本次或前章事件引用。规则上限只读作者上下文，不能由响应提供。</p>
        @include('filament.extraction-fields',['path'=>'trees.extraction','node'=>$trees['extraction']])
        <x-nf.input id="extraction-reason" label="本次提取或修正原因" wire:model.live.debounce.300ms="reason" :required="true" :error="$errors->first('reason')" />
        <div class="nf-config-actions">
            <x-filament::button wire:click="extract" wire:loading.attr="disabled" :disabled="!$body || $reason===''">验证并保存候选（不建模型请求）</x-filament::button>
            <x-filament::button color="gray" wire:click="exportResponseFields">导出当前候选草稿</x-filament::button>
        </div>
        <details><summary>高级响应导入</summary><p>只导入候选响应，不改变正文、小说或版本身份。错误不会覆盖当前编辑区。</p>
            <x-nf.field id="extraction-import" label="高级JSON响应"><x-nf.control tag="textarea" id="extraction-import" rows="5" wire:model="importResponse"></x-nf.control></x-nf.field>
            <x-filament::button color="gray" wire:click="importResponseFields">载入字段编辑区</x-filament::button>
        </details>
    </x-nf.section>
    </div>
    <div id="nf-extraction-result" tabindex="-1"><x-nf.section heading="3 · 候选验证结果与原文定位">
        @if(!$detail)<x-nf.state state="empty" title="尚未读取验证结果">先验证草稿，或从下方不可变历史选择一条记录。空结果不代表检查通过。</x-nf.state>
        @else
            <p>记录 #{{ $detail['id'] }} · {{ $detail['display_status'] }} · {{ $detail['current_eligible']?'当前有效候选，仍非正式剧情':'当前不可用，请核对问题或来源' }}</p>
            <p class="nf-break">正文 #{{ $detail['chapter_version_id'] }} · {{ $detail['body_hash'] }}</p>
            @forelse($detail['report']['issues']??[] as $issue)
                <x-nf.state state="error" :title="$issue['code']??'验证问题'">
                    @include('filament.value-table',['value'=>$issue,'label'=>'问题路径、来源与修正建议'])
                    @if(isset($issue['diagnostic']['event_key']))<x-filament::button color="gray" wire:click="focusExtractionIssue(@js($issue['diagnostic']['event_key']))">定位到事件编辑行</x-filament::button>@endif
                </x-nf.state>
            @empty<p>本次报告无问题条目；是否有效仍以上方来源与候选状态为准。</p>@endforelse
            <x-nf.form-grid>
                <x-nf.select id="event-entity" label="按实体筛选事件" model="entity" :value="$entity" :options="[''=>'全部实体']+$selections['allEntities']" />
                <x-nf.select id="event-kind" label="按事实类别筛选" model="factKind" :value="$factKind" :options="[''=>'全部类别']+array_combine(\App\Services\StoryStateService::KINDS,\App\Services\StoryStateService::KINDS)" />
            </x-nf.form-grid>
            @forelse($this->events() as $e)
                <x-nf.section :heading="'事件 #'.$e['id'].' · '.$e['attribute']">
                    <p>主体 #{{ $e['entity_id'] }} · 第{{ $e['chapter_sequence'] }}章 / 叙述顺序{{ $e['narrative_order'] }} · {{ $e['fact_kind'] }} / {{ $e['action'] }}</p>
                    @include('filament.value-table',['value'=>['发生时间'=>$e['entry']['time'],'事实值'=>$e['value']],'label'=>'只读事件字段'])
                    <blockquote>原文 {{ $e['evidence']['start_offset'] }}–{{ $e['evidence']['end_offset'] }}：{{ $e['evidence']['quote'] }}</blockquote>
                </x-nf.section>
            @empty<p>当前筛选下没有已登记事件；被阻止的响应可能没有生成事件。</p>@endforelse
            <details><summary>候选补丁与伏笔变化（只读）</summary>@include('filament.value-table',['value'=>['补丁'=>$detail['patches'],'伏笔变化'=>$detail['candidate_foreshadows']],'label'=>'候选补丁来源'])</details>
            <x-nf.input id="replay-chapter" type="number" label="候选回放截至章" wire:model="atChapter" min="1" />
            <div class="nf-config-actions">
                <x-filament::button color="gray" wire:click="replay" :disabled="!$detail['current_eligible']">回放候选状态</x-filament::button>
                <x-filament::button color="gray" wire:click="replay(true)" :disabled="!$detail['current_eligible']">重建候选投影</x-filament::button>
                <a href="/api/v1/novels/{{ $novel }}/extractions/{{ $detail['id'] }}" download>导出本次提取与审计来源</a>
            </div>
            @if($projection)@include('filament.value-table',['value'=>$projection,'label'=>'只读候选回放结果'])@endif
        @endif
    </x-nf.section>
    </div>
    <x-nf.section heading="不可变提取历史 · 最近50条">
        <x-nf.input id="extraction-history-query" label="在最近记录中搜索状态或原因" wire:model.live.debounce.300ms="historyQuery" />
        @php($filtered=array_values(array_filter($history,fn($r)=>$historyQuery===''||str_contains($r['reason'].' '.$r['display_status'].' '.$r['id'],$historyQuery))))
        @php($last=max(1,(int)ceil(count($filtered)/10)))
        <x-nf.table label="不可变候选提取历史"><table><thead><tr><th>记录与正文</th><th>候选状态</th><th>提取原因</th><th>动作</th></tr></thead><tbody>
        @forelse(array_slice($filtered,(min($historyPage,$last)-1)*10,10) as $row)
            <tr><td>第{{ $row['chapter_sequence'] }}章 · #{{ $row['id'] }}<br>正文 #{{ $row['chapter_version_id'] }}</td><td>{{ $row['display_status'] }} · {{ $row['current_eligible']?'有效候选':'不可用' }}</td><td>{{ $row['reason'] }}</td><td>
                <x-filament::button size="sm" color="gray" wire:click="inspectExtraction('{{ $row['id'] }}')">读取详情</x-filament::button>
                <x-filament::button size="sm" color="gray" wire:click="editExtraction('{{ $row['id'] }}')">复制响应重新提取</x-filament::button>
            </td></tr>
        @empty<tr><td colspan="4">{{ $history?'没有匹配记录，请调整搜索。':'尚未产生提取记录。' }}</td></tr>@endforelse
        </tbody></table></x-nf.table>
        <div class="nf-config-actions"><span>第{{ min($historyPage,$last) }}/{{ $last }}页 · 最近记录范围匹配{{ count($filtered) }}条</span>
        <x-filament::button color="gray" wire:click="$set('historyPage',{{ max(1,$historyPage-1) }})" :disabled="$historyPage<=1">上一页</x-filament::button>
        <x-filament::button color="gray" wire:click="$set('historyPage',{{ $historyPage+1 }})" :disabled="$historyPage>=$last">下一页</x-filament::button></div>
    </x-nf.section>
    <details><summary>离线流水线 · 路由、预算与运行证据</summary>
    <x-nf.section heading="冻结路由的离线提取阶段">
        <p>与上方纯验证分开：仅fake供应商与offline_fake路由，会产生持久预算及请求证据。</p>
        <x-nf.form-grid>
            <x-nf.select id="extraction-route" label="已发布提取路由" model="route" :value="$route" :options="[''=>'请选择实际路由']+collect($options['routes'])->mapWithKeys(fn($r)=>[(string)$r->id=>$r->key.' · #'.$r->id])->all()" />
            <x-nf.select id="extraction-policy" label="预算政策版本" model="policy" :value="$policy" :options="[''=>'请选择实际政策']+collect($options['policies'])->mapWithKeys(fn($r)=>[(string)$r->id=>$r->key.' · #'.$r->id])->all()" />
        </x-nf.form-grid>
        <fieldset><legend>报价版本 · 按所选路由模型填写</legend>
            @forelse($options['prices'] as $p)<label class="nf-price-choice"><input type="checkbox" wire:model.live="priceSelections" value="{{ $p->id }}">能力版本 #{{ $p->model_version_id }} · 报价 v{{ $p->version }} · {{ $p->currency }} · #{{ $p->id }}</label>@empty<p>请先选择路由；如仍为空，需先配置对应模型报价。未知费用不代表免费。</p>@endforelse
        </fieldset>
        <x-filament::button wire:click="startPipeline" wire:loading.attr="disabled" :disabled="!$body || $reason==='' || $route==='' || $policy==='' || !$priceSelections">冻结并启动离线提取</x-filament::button>
        <x-nf.select id="extraction-run" label="本书离线提取运行" model="run" :value="$run" :options="[''=>'请选择实际运行']+$selections['runs']" />
        <x-filament::button color="gray" wire:click="replayPipeline" :disabled="$run===''">执行离线阶段回放</x-filament::button>
        @if(ctype_digit($run))<a href="/admin/recovery-desk?novel={{ $novel }}&run={{ $run }}">查看恢复与请求证据</a>@endif
    </x-nf.section>
    </details>
    @endif
    @endif
</x-filament-panels::page>
