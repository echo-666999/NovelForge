<x-filament-panels::page class="nf-story-screen nf-production-screen"><div class="nf-production-layout">
@php($pageRead=$this->productionReadFrame(['catalog']))
@if(!$pageRead['ok'])<x-nf.state state="error" title="资料读取失败"><p>{{ $pageRead['message'] }}</p><div class="nf-story-toolbar"><x-filament::button color="gray" wire:click="$refresh">重新读取</x-filament::button><x-filament::button color="gray" wire:click="resetProductionFilters">重置筛选</x-filament::button></div></x-nf.state>
@else
<div wire:loading role="status">正在读取或保存，请稍候…</div>
@if($errors->any())<x-nf.state state="error" title="请核对字段，输入仍保留"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-nf.state>@endif
    {{-- 跨页只传同书运行标识，详情页再次鉴权。 --}}
    @if($novel !== '' && $run !== '')<a href="{{ '/admin/run-center?novel='.$novel.'&run='.$run }}">返回运行、请求与审计详情</a>@endif
    {{-- 金额按十进制字符串展示，不在浏览器用浮点数汇总。 --}}
    @php($data=$pageRead['data']['catalog'])
    <p>当前为离线模拟账本，真实调用保持关闭。金额保留原币，不自动换汇；日预算按 UTC 切日。</p>
    <p>新版本只影响显式采用它的新运行。未知实际费用保持空值并保留风险预留；HTTP失败不等于免费。超预算不发请求。</p>
    @if(session('billing_error'))<p role="alert">{{session('billing_error')}}</p>@endif
    @if(session('billing_notice'))<p role="status">{{session('billing_notice')}}</p>@endif
    <div class="nf-story-fields">
        <x-nf.field id="ui07-billing-1-{{ md5('novel') }}" label="小说ID" :error="$errors->first('novel')"><input id="ui07-billing-1-{{ md5('novel') }}" wire:model.live.debounce.400ms="novel" class="w-full rounded border p-2"></x-nf.field>
        <x-nf.field id="ui07-billing-2-{{ md5('currency') }}" label="币种" :error="$errors->first('currency')"><input id="ui07-billing-2-{{ md5('currency') }}" wire:model.live.debounce.400ms="currency" class="w-full rounded border p-2"></x-nf.field>
        <x-nf.select id="billing-status" label="费用状态" model="status" :value="$status" :options="[''=>'全部','reserved'=>'已预留','unknown'=>'费用未知','settled'=>'已核销']" />
        <x-nf.select id="billing-category" label="用途" model="category" :value="$category" :options="[''=>'全部','generation'=>'正文生成','review'=>'评审','revision'=>'修订','summary'=>'摘要','embedding'=>'嵌入','planning'=>'规划']" />
    </div>
    <div class="nf-story-fields">
        <x-nf.field id="ui07-billing-3-{{ md5('run') }}" label="运行ID" :error="$errors->first('run')"><input id="ui07-billing-3-{{ md5('run') }}" wire:model.live.debounce.400ms="run" class="w-full rounded border p-2"></x-nf.field>
        <x-nf.field id="ui07-billing-4-{{ md5('model') }}" label="模型能力版本ID" :error="$errors->first('model')"><input id="ui07-billing-4-{{ md5('model') }}" wire:model.live.debounce.400ms="model" class="w-full rounded border p-2"></x-nf.field>
        <x-nf.select id="billing-request-status" label="请求状态" model="requestStatus" :value="$requestStatus" :options="[''=>'全部','pending'=>'等待处理','succeeded'=>'成功','failed'=>'失败','unknown'=>'结果未知','quarantined'=>'已隔离']" />
        <x-nf.field id="ui07-billing-5-{{ md5('dateFrom') }}" label="UTC开始日期" :error="$errors->first('dateFrom')"><input id="ui07-billing-5-{{ md5('dateFrom') }}" type="date" wire:model.live="dateFrom" class="rounded border p-2"></x-nf.field>
        <x-nf.field id="ui07-billing-6-{{ md5('dateTo') }}" label="UTC结束日期" :error="$errors->first('dateTo')"><input id="ui07-billing-6-{{ md5('dateTo') }}" type="date" wire:model.live="dateTo" class="rounded border p-2"></x-nf.field>
    </div>
    @foreach($data['totals'] as $t)<p>{{$t['currency']}} · 已核销 {{$t['settled_cost']}} · 风险预留 {{$t['risk_reserved']}} · 未核销 {{$t['unknown_count']}} 笔。{{(int)$t['unknown_count']>0?'总实际费用未知':'全部已核销（模拟）'}}</p>@endforeach
    @if($billingNotice)<p role="status">{{ $billingNotice }}</p>@endif
    @foreach(['price'=>'新增价格版本（每百万Token）','policy'=>'新增预算政策版本','reconcile'=>'凭证对账或追加核销更正'] as $action=>$title)
        <details wire:ignore.self><summary>{{ $title }}</summary>
            @include('filament.production-editor',['property'=>$action.'Payload','label'=>$title])
            <x-filament::button wire:loading.attr="disabled" wire:click="saveBilling('{{ $action }}')">保存版本或凭证记录</x-filament::button>
            <p>金额按十进制文本填写，未知Token明确使用空值；只有实际凭证才能对账。相同输入重试保留原幂等键。</p>
        </details>
    @endforeach
    <div><x-nf.table label="生产资料列表"><table class="w-full text-left"><thead><tr><th>请求 / 用途</th><th>币种 / 价格版本</th><th>状态</th><th>最坏预留</th><th>实际费用</th><th>风险预留</th></tr></thead><tbody>
        @forelse($data['requests'] as $r)
            <tr><td>{{$r['request_id']}} / {{$r['category']}}</td><td>{{$r['currency']}} / {{$r['price_version_id']}}</td><td>{{$r['status']}} {{$r['issue_code']}}</td><td>{{$r['amount']}}</td><td>{{$r['actual_cost']??'未知，待对账'}}</td><td>{{$r['risk_reserved']}}</td></tr>
            <tr><td colspan="6"><details wire:ignore.self><summary>预算桶、计费分类与不可变对账证据</summary>@include('filament.value-table',['value'=>$r,'label'=>'只读账本证据'])</details></td></tr>
        @empty <tr><td colspan="6">暂无费用记录。先录入有凭证的价格和预算版本；配置保存不会调用模型。</td></tr>@endforelse
    </tbody></table></x-nf.table></div>
    <p>共{{$data['total']}}笔，第{{$data['page']}}页。</p>
    <a href="/api/v1/billing/export?{{http_build_query(array_filter(['novel_id'=>$this->novel,'status'=>$this->status,'currency'=>$this->currency,'category'=>$this->category,'run_id'=>$this->run,'model_profile_version_id'=>$this->model,'request_status'=>$this->requestStatus,'date_from'=>$this->dateFrom,'date_to'=>$this->dateTo]))}}">导出当前筛选费用摘要 CSV（最多一万笔）</a>
    @if($this->page>1)<x-filament::button wire:loading.attr="disabled" wire:click="$set('page',{{$this->page-1}})">上一页</x-filament::button>@endif
    @if($this->page*25<$data['total'])<x-filament::button wire:loading.attr="disabled" wire:click="$set('page',{{$this->page+1}})">下一页</x-filament::button>@endif
    <details wire:ignore.self><summary>最近100个价格与预算版本（只读）</summary>@include('filament.value-table',['value'=>['prices'=>$data['prices'],'policies'=>$data['policies']],'label'=>'只读账本证据'])</details>
@endif
</div></x-filament-panels::page>
