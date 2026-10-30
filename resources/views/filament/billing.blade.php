<x-filament-panels::page class="nf-story-screen nf-production-screen" x-data="{ billingTrigger: null }"
    x-on:billing-editor-opened.window="$nextTick(()=>{billingTrigger?.focus({preventScroll:true});$dispatch('open-modal',{id:'nf-billing-editor-modal'})})"
    x-on:modal-closed.window="if($event.detail.id==='nf-billing-editor-modal'){if($wire.billingEditor!=='')$wire.closeBillingEditor();$nextTick(()=>billingTrigger?.focus({preventScroll:true}));}"><div class="nf-production-layout">
@php($pageRead=$this->productionReadFrame(['catalog']))
@if(!$pageRead['ok'])<x-nf.state state="error" title="资料读取失败"><p>{{ $pageRead['message'] }}</p><div class="nf-story-toolbar"><x-filament::button color="gray" wire:click="$refresh">重新读取</x-filament::button><x-filament::button color="gray" wire:click="resetProductionFilters">重置筛选</x-filament::button></div></x-nf.state>
@else
<div wire:loading role="status">正在读取或保存，请稍候…</div>
@if($errors->any() && $billingEditor === '')<x-nf.state state="error" title="请核对字段，输入仍保留"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-nf.state>@endif
    {{-- 跨页只传同书运行标识，详情页再次鉴权。 --}}
    @if($novel !== '' && $run !== '')<a href="{{ '/admin/run-center?novel='.$novel.'&run='.$run }}">返回运行、请求与审计详情</a>@endif
    {{-- 金额按十进制字符串展示，不在浏览器用浮点数汇总。 --}}
    @php($data=$pageRead['data']['catalog'])
    <p>当前为离线模拟账本，真实调用保持关闭。金额保留原币，不自动换汇；日预算按 UTC 切日。</p>
    <p>新版本只影响显式采用它的新运行。未知实际费用保持空值并保留风险预留；HTTP失败不等于免费。超预算不发请求。</p>
    @if(session('billing_error'))<p role="alert">{{session('billing_error')}}</p>@endif
    @if(session('billing_notice'))<p role="status">{{session('billing_notice')}}</p>@endif
    {{-- UI24：默认只有目录与账本，字段树仅在选择相应新增入口后渲染。 --}}
    <div class="nf-config-actions">
        <x-filament::button id="nf-price-create" x-on:click="billingTrigger=$el" wire:click="beginBillingEditor('price')">新建价格版本</x-filament::button>
        <x-filament::button id="nf-policy-create" color="gray" x-on:click="billingTrigger=$el" wire:click="beginBillingEditor('policy')">新建预算政策</x-filament::button>
        <x-filament::button id="nf-reconcile-create" color="gray" x-on:click="billingTrigger=$el" wire:click="beginBillingEditor('reconcile')">录入对账凭证</x-filament::button>
    </div>
    @foreach($data['totals'] as $t)<p>{{$t['currency']}} · 已核销 {{$t['settled_cost']}} · 风险预留 {{$t['risk_reserved']}} · 未核销 {{$t['unknown_count']}} 笔。{{(int)$t['unknown_count']>0?'总实际费用未知':'全部已核销（模拟）'}}</p>@endforeach
    @if($billingNotice && $billingEditor === '')<p role="{{ $billingSaveFailed ? 'alert' : 'status' }}">{{ $billingNotice }}</p>@endif
    <x-nf.section heading="价格版本列表">
        <p>最近100个版本，只读；范围沿用现有目录，不代表费用筛选后的完整历史。</p>
        <x-nf.table label="价格版本列表"><table><thead><tr><th>版本ID / 模型名称</th><th>版本 / 币种</th><th>生效时间</th><th>操作</th></tr></thead><tbody>
        @forelse($data['prices'] as $price)<tr><td>#{{ $price['id'] }} / {{ $data['model_names'][$price['model_version_id']] ?? '模型名称不可用' }}</td><td>v{{ $price['version'] }} / {{ $price['currency'] }}</td><td>{{ $price['effective_at'] }}</td><td><x-filament::button color="gray" wire:click="inspectBilling('price','{{ $price['id'] }}')">查看价格详情</x-filament::button></td></tr>
        @empty<tr><td colspan="4">暂无价格版本。</td></tr>@endforelse
        </tbody></table></x-nf.table>
    </x-nf.section>
    <x-nf.section heading="预算政策列表">
        <p>最近100个版本；币种、UTC时区和限额完整值见只读详情。</p>
        <x-nf.table label="预算政策列表"><table><thead><tr><th>政策键 / 版本</th><th>币种 / 时区</th><th>预警 / 停止阈值</th><th>操作</th></tr></thead><tbody>
        @forelse($data['policies'] as $policy)<tr><td>{{ $policy['key'] }} / v{{ $policy['version'] }}</td><td>{{ $policy['currency'] }} / {{ $policy['timezone'] }}</td><td>{{ $policy['warning_percent'] }}% / {{ $policy['stop_percent'] }}%</td><td><x-filament::button color="gray" wire:click="inspectBilling('policy','{{ $policy['id'] }}')">查看预算详情</x-filament::button></td></tr>
        @empty<tr><td colspan="4">暂无预算政策。</td></tr>@endforelse
        </tbody></table></x-nf.table>
    </x-nf.section>
    <x-nf.section heading="费用账本">
        <details wire:ignore.self><summary>筛选费用账本</summary>
    <div class="nf-story-fields">
        <x-nf.field id="ui07-billing-1-{{ md5('novel') }}" label="小说ID" :error="$errors->first('novel')"><x-nf.control id="ui07-billing-1-{{ md5('novel') }}" wire:model.live.debounce.400ms="novel" class="w-full rounded border p-2" /></x-nf.field>
        <x-nf.field id="ui07-billing-2-{{ md5('currency') }}" label="币种" :error="$errors->first('currency')"><x-nf.control id="ui07-billing-2-{{ md5('currency') }}" wire:model.live.debounce.400ms="currency" class="w-full rounded border p-2" /></x-nf.field>
        <x-nf.select id="billing-status" label="费用状态" model="status" :value="$status" :options="[''=>'全部','reserved'=>'已预留','unknown'=>'费用未知','settled'=>'已核销']" />
        <x-nf.select id="billing-category" label="用途" model="category" :value="$category" :options="[''=>'全部','generation'=>'正文生成','review'=>'评审','revision'=>'修订','summary'=>'摘要','embedding'=>'嵌入','planning'=>'规划']" />
    </div>
    <div class="nf-story-fields">
        <x-nf.field id="ui07-billing-3-{{ md5('run') }}" label="运行ID" :error="$errors->first('run')"><x-nf.control id="ui07-billing-3-{{ md5('run') }}" wire:model.live.debounce.400ms="run" class="w-full rounded border p-2" /></x-nf.field>
        <x-nf.field id="ui07-billing-4-{{ md5('model') }}" label="模型能力版本ID" :error="$errors->first('model')"><x-nf.control id="ui07-billing-4-{{ md5('model') }}" wire:model.live.debounce.400ms="model" class="w-full rounded border p-2" /></x-nf.field>
        <x-nf.select id="billing-request-status" label="请求状态" model="requestStatus" :value="$requestStatus" :options="[''=>'全部','pending'=>'等待处理','succeeded'=>'成功','failed'=>'失败','unknown'=>'结果未知','quarantined'=>'已隔离']" />
        <x-nf.field id="ui07-billing-5-{{ md5('dateFrom') }}" label="UTC开始日期" :error="$errors->first('dateFrom')"><x-nf.control id="ui07-billing-5-{{ md5('dateFrom') }}" type="date" wire:model.live="dateFrom" class="rounded border p-2" /></x-nf.field>
        <x-nf.field id="ui07-billing-6-{{ md5('dateTo') }}" label="UTC结束日期" :error="$errors->first('dateTo')"><x-nf.control id="ui07-billing-6-{{ md5('dateTo') }}" type="date" wire:model.live="dateTo" class="rounded border p-2" /></x-nf.field>
    </div>
        </details>
    <div><x-nf.table label="生产资料列表"><table class="w-full text-left"><thead><tr><th>请求 / 用途</th><th>币种 / 价格版本</th><th>状态</th><th>最坏预留</th><th>实际费用</th><th>风险预留</th><th>详情</th></tr></thead><tbody>
        @forelse($data['requests'] as $r)
            <tr><td>{{$r['request_id']}} / {{$r['category']}}</td><td>{{$r['currency']}} / {{$r['price_version_id']}}</td><td>{{$r['status']}} {{$r['issue_code']}}</td><td>{{$r['amount']}}</td><td>{{$r['actual_cost']??'未知，待对账'}}</td><td>{{$r['risk_reserved']}}</td><td><x-filament::button color="gray" wire:click="inspectBilling('request','{{ $r['request_id'] }}')">查看账本证据</x-filament::button></td></tr>

        @empty <tr><td colspan="7">暂无费用记录。先录入有凭证的价格和预算版本；配置保存不会调用模型。</td></tr>@endforelse
    </tbody></table></x-nf.table></div>
    <p>共{{$data['total']}}笔，第{{$data['page']}}页。</p>
    <a href="/api/v1/billing/export?{{http_build_query(array_filter(['novel_id'=>$this->novel,'status'=>$this->status,'currency'=>$this->currency,'category'=>$this->category,'run_id'=>$this->run,'model_profile_version_id'=>$this->model,'request_status'=>$this->requestStatus,'date_from'=>$this->dateFrom,'date_to'=>$this->dateTo]))}}">导出当前筛选费用摘要 CSV（最多一万笔）</a>
    @if($this->page>1)<x-filament::button wire:loading.attr="disabled" wire:click="$set('page',{{$this->page-1}})">上一页</x-filament::button>@endif
    @if($this->page*25<$data['total'])<x-filament::button wire:loading.attr="disabled" wire:click="$set('page',{{$this->page+1}})">下一页</x-filament::button>@endif
    </x-nf.section>
    <x-nf.editor-modal id="nf-billing-editor-modal" :heading="['price'=>'新建价格版本','policy'=>'新建预算政策','reconcile'=>'录入对账凭证'][$billingEditor] ?? '配置编辑'"
        x-init="if ({{ $billingEditor !== '' ? 'true' : 'false' }}) $nextTick(()=>$dispatch('open-modal',{id:'nf-billing-editor-modal'}))">
        @if($billingEditor !== '')
        <form id="nf-billing-editor-form" class="nf-config-editor" wire:submit="saveBilling('{{ $billingEditor }}')"
            x-on:submit="const error=$el.querySelector('[role=alert]');if(error)error.focus({preventScroll:true});">
            @if($billingSaveFailed || $errors->any())
            <x-nf.state state="error" title="尚未保存，字段仍保留" tabindex="-1" wire:key="billing-modal-errors-{{ md5(json_encode([$errors->all(),$billingNotice])) }}"
                x-init="$nextTick(()=>requestAnimationFrame(()=>{const target=$el.closest('[role=dialog]')?.querySelector('[aria-invalid=true]:not([disabled])')??$el;target.focus({preventScroll:true});target.scrollIntoView({block:'nearest'});} ))">
                @if($errors->any())<ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>@else{{ $billingNotice }}@endif
            </x-nf.state>
            @endif
            @include('filament.production-editor',['property'=>$billingEditor.'Payload','label'=>['price'=>'价格版本（每百万Token）','policy'=>'预算政策','reconcile'=>'凭证对账或核销更正'][$billingEditor]])
            <p>金额按十进制文本填写，未知Token明确使用空值；只有实际凭证才能对账。相同输入重试保留原幂等键；关闭不写入并保留当前字段供重试。</p>
            <div class="nf-config-actions"><x-filament::button type="submit" wire:loading.attr="disabled">保存版本或凭证记录</x-filament::button><x-filament::button type="button" color="gray" wire:click="closeBillingEditor">取消编辑</x-filament::button></div>
        </form>
        @endif
    </x-nf.editor-modal>
    <x-nf.editor-modal id="nf-billing-detail-modal" heading="配置与账本只读详情">
        @if($billingDetail)@include('filament.value-table',['value'=>$billingDetail['record'],'label'=>'只读账本证据','billingLabels'=>true])@endif
    </x-nf.editor-modal>
@endif
</div></x-filament-panels::page>
