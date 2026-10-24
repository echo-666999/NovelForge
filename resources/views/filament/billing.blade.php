<x-filament-panels::page>
    {{-- 金额按十进制字符串展示，不在浏览器用浮点数汇总。 --}}
    @php
        $data=$this->catalog(); $json=fn($v)=>json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $examples=[
            'price'=>['model_version_id'=>'填写能力版本ID','expected_version'=>0,'currency'=>'填写三位币种','unit_prices'=>array_fill_keys(['input','output','cache_read','cache_write','reasoning'],'填写十进制单价'),'cache_mode'=>'none','reasoning_mode'=>'none','effective_at'=>'2026-10-24T00:00:00+00:00','evidence_ref'=>'填写可核验价目凭证，测试价格须明确标注'],
            'policy'=>['key'=>'my-budget','expected_version'=>0,'currency'=>'填写三位币种','limits'=>array_fill_keys(['request','chapter','run','book','day'],'填写十进制金额'),'warning_percent'=>80,'stop_percent'=>100,'timezone'=>'UTC','reason'=>'填写预算批准及变更原因'],
            'reconcile'=>['request_id'=>'填写待对账请求ID','external_charge_id'=>'填写供应商请求或账单计费ID','usage'=>['input_tokens'=>0,'output_tokens'=>0],'evidence_ref'=>'必须填写真实可核验凭证说明；不要凭猜测按零费用结算'],
        ];
    @endphp
    <p>当前为离线模拟账本，真实调用保持关闭。金额保留原币，不自动换汇；日预算按 UTC 切日。</p>
    <p>新版本只影响显式采用它的新运行。未知实际费用保持空值并保留风险预留；HTTP失败不等于免费。超预算不发请求。</p>
    @if(session('billing_error'))<p role="alert">{{session('billing_error')}}</p>@endif
    @if(session('billing_notice'))<p role="status">{{session('billing_notice')}}</p>@endif
    <div class="grid gap-3 md:grid-cols-4">
        <label>小说ID <input wire:model.live.debounce.400ms="novel" class="w-full rounded border p-2"></label>
        <label>币种 <input wire:model.live.debounce.400ms="currency" class="w-full rounded border p-2"></label>
        <label>费用状态 <select wire:model.live="status" class="w-full rounded border p-2"><option value="">全部</option>@foreach(['reserved','unknown','settled'] as $s)<option>{{$s}}</option>@endforeach</select></label>
        <label>用途 <select wire:model.live="category" class="w-full rounded border p-2"><option value="">全部</option>@foreach(['generation','review','revision','summary','embedding','planning'] as $s)<option>{{$s}}</option>@endforeach</select></label>
    </div>
    <div class="grid gap-3 md:grid-cols-3">
        <label>运行ID <input wire:model.live.debounce.400ms="run" class="w-full rounded border p-2"></label>
        <label>模型能力版本ID <input wire:model.live.debounce.400ms="model" class="w-full rounded border p-2"></label>
        <label>请求状态 <select wire:model.live="requestStatus" class="w-full rounded border p-2"><option value="">全部</option>@foreach(['pending','succeeded','failed','unknown','quarantined'] as $s)<option>{{$s}}</option>@endforeach</select></label>
        <label>UTC开始日期 <input type="date" wire:model.live="dateFrom" class="rounded border p-2"></label>
        <label>UTC结束日期 <input type="date" wire:model.live="dateTo" class="rounded border p-2"></label>
    </div>
    @foreach($data['totals'] as $t)<p>{{$t['currency']}} · 已核销 {{$t['settled_cost']}} · 风险预留 {{$t['risk_reserved']}} · 未核销 {{$t['unknown_count']}} 笔。{{(int)$t['unknown_count']>0?'总实际费用未知':'全部已核销（模拟）'}}</p>@endforeach
    @foreach($examples as $action=>$example)
        <details><summary>{{['price'=>'新增价格版本（每百万Token）','policy'=>'新增预算政策版本','reconcile'=>'凭证对账或追加核销更正'][$action]}}</summary>
            <form method="POST" action="/api/v1/billing/{{$action}}">@csrf<input type="hidden" name="command_key" value="{{Str::uuid()}}">
                <label>完整配置 JSON<textarea name="payload_json" rows="17" class="w-full rounded border p-3 font-mono" required>{{$json($example)}}</textarea></label>
                <x-filament::button type="submit">保存版本或凭证记录</x-filament::button>
            </form>
            <p>若账单只有金额，可传 usage: null 并提供 amounts 的 input/output/cache_read/cache_write/reasoning 五个十进制金额，Token保持未知。Token必须为非负整数；金额必须为字符串。缓存规则 included 表示输入包含缓存，separate 表示独立；推理 included 表示已按输出计费，不能再加一次。适用的缓存与独立推理字段缺失会保留未知。</p>
        </details>
    @endforeach
    <div class="overflow-x-auto"><table class="w-full text-left"><thead><tr><th>请求 / 用途</th><th>币种 / 价格版本</th><th>状态</th><th>最坏预留</th><th>实际费用</th><th>风险预留</th></tr></thead><tbody>
        @forelse($data['requests'] as $r)
            <tr><td>{{$r['request_id']}} / {{$r['category']}}</td><td>{{$r['currency']}} / {{$r['price_version_id']}}</td><td>{{$r['status']}} {{$r['issue_code']}}</td><td>{{$r['amount']}}</td><td>{{$r['actual_cost']??'未知，待对账'}}</td><td>{{$r['risk_reserved']}}</td></tr>
            <tr><td colspan="6"><details><summary>预算桶、计费分类与不可变对账证据</summary><pre class="whitespace-pre-wrap overflow-x-auto">{{$json($r)}}</pre></details></td></tr>
        @empty <tr><td colspan="6">暂无费用记录。先录入有凭证的价格和预算版本；配置保存不会调用模型。</td></tr>@endforelse
    </tbody></table></div>
    <p>共{{$data['total']}}笔，第{{$data['page']}}页。</p>
    <a href="/api/v1/billing/export?{{http_build_query(array_filter(['novel_id'=>$this->novel,'status'=>$this->status,'currency'=>$this->currency,'category'=>$this->category,'run_id'=>$this->run,'model_profile_version_id'=>$this->model,'request_status'=>$this->requestStatus,'date_from'=>$this->dateFrom,'date_to'=>$this->dateTo]))}}">导出当前筛选费用摘要 CSV（最多一万笔）</a>
    @if($this->page>1)<x-filament::button wire:click="$set('page',{{$this->page-1}})">上一页</x-filament::button>@endif
    @if($this->page*25<$data['total'])<x-filament::button wire:click="$set('page',{{$this->page+1}})">下一页</x-filament::button>@endif
    <details><summary>最近100个价格与预算版本（只读）</summary><pre class="whitespace-pre-wrap overflow-x-auto">{{$json(['prices'=>$data['prices'],'policies'=>$data['policies']])}}</pre></details>
</x-filament-panels::page>
