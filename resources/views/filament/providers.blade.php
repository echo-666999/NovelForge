<x-filament-panels::page x-data="{}" x-on:provider-history-opened.window="$nextTick(()=>document.getElementById('nf-provider-history')?.scrollIntoView({block:'start'}))">
    @php($catalog=$this->catalog())
    @php($providerOptions=$this->providerOptions())
    @if(session('provider_notice'))<x-nf.state state="success" title="操作结果">{{ session('provider_notice') }}</x-nf.state>@endif
    @if(session('provider_error'))<x-nf.state state="error" title="未保存，字段已保留（密钥除外）">{{ session('provider_error') }}<ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-nf.state>@endif
    <p>保存只追加配置版本，不请求模型、不探测端点。能力声明与实测分开，密钥永不回显。</p>
    <x-nf.section heading="供应商配置">
        <x-nf.form-grid :filters="true">
            <x-nf.input id="provider-filter-name" label="供应商名称筛选" wire:model.live.debounce.300ms="q" />
            <x-nf.select id="provider-filter-interface" label="接口" model="interface" :value="$interface" :options="[''=>'全部','fake'=>'离线模拟','json_http'=>'通用JSON协议']" />
            <x-nf.select id="provider-filter-state" label="配置状态" model="enabled" :value="$enabled" :options="[''=>'全部','1'=>'启用','0'=>'停用']" />
        </x-nf.form-grid>
        @forelse($catalog['providers'] as $provider)
            <details class="nf-json-node" wire:key="provider-card-{{ $provider['id'] }}"
                x-on:toggle="if($el.open && $wire.providerEditor !== @js($provider['id'])) $wire.openProviderEditor(@js($provider['id']))"
                @if($providerEditor===$provider['id']) open @endif>
                <summary>{{ $provider['name'] }} · v{{ $provider['version'] }} · {{ $provider['enabled']?'启用配置':'停用' }} · 密钥{{ $provider['has_key']?'已配置':'未配置' }}</summary>
                @if($providerEditor===$provider['id'])
                <p>{{ $provider['endpoint'] }} · 能力未验证，费用未知。编辑仅追加配置版本，名称不可改。</p>
                <x-filament::button color="gray" wire:click="readConfigurationHistory('provider','{{ $provider['id'] }}')">只读配置历史与比较</x-filament::button>
                @include('filament.provider-form', ['provider'=>$provider])
                @else<p role="status">展开后读取配置字段…</p>@endif
            </details>
        @empty<x-nf.state state="empty" title="暂无符合条件的供应商">可调整筛选，或先创建配置。创建不会发起请求。</x-nf.state>@endforelse
        <div class="nf-config-actions"><span>共 {{ $catalog['total'] }} 个供应商 · 第 {{ $catalog['page'] }} 页</span>
            <x-filament::button color="gray" wire:click="$set('page',{{ max(1,$page-1) }})" :disabled="$page<=1">上一页</x-filament::button>
            <x-filament::button color="gray" wire:click="$set('page',{{ $page+1 }})" :disabled="$page*25 >= $catalog['total']">下一页</x-filament::button>
        </div>
    </x-nf.section>
    <x-nf.section heading="新增供应商">@include('filament.provider-form', ['provider'=>null])</x-nf.section>
    <x-nf.section heading="模型能力版本">
        <x-nf.select id="model-filter-structured" label="结构化能力声明" model="structured" :value="$structured" :options="[''=>'全部','unknown'=>'未知','supported'=>'声明支持','unsupported'=>'声明不支持']" />
        @forelse($catalog['models'] as $model)
            <details class="nf-json-node" wire:key="model-card-{{ $model['version_id'] }}"
                x-on:toggle="if($el.open && $wire.modelEditor !== @js($model['version_id'])) $wire.openModelEditor(@js($model['version_id']))"
                @if($modelEditor===$model['version_id']) open @endif>
                <summary>{{ $providerOptions[$model['provider_id']]??$model['provider_id'] }} / {{ $model['model'] }} · v{{ $model['version'] }} · 未验证</summary>
                @if($modelEditor===$model['version_id'])
                @include('filament.value-table',['value'=>$model['declared_capabilities'],'label'=>'能力声明逐字段只读'])
                <p>复制当前声明保存新版本；已有路由和旧运行仍引用原能力版本。</p>
                <x-filament::button color="gray" wire:click="readConfigurationHistory('model','{{ $model['version_id'] }}')">只读模型历史与比较</x-filament::button>
                @include('filament.model-form',['model'=>$model])
                @else<p role="status">展开后读取声明字段…</p>@endif
            </details>
        @empty<x-nf.state state="empty" title="暂无模型能力档案">下方先选择实际供应商，再填写声明。未知容量留空。</x-nf.state>@endforelse
        <p>展示当前筛选供应商下最新的最多100个模型档案；历史能力版本继续可由路由版本引用。此处不提供真实能力验证动作。</p>
    </x-nf.section>
    <x-nf.section heading="新增模型声明">@include('filament.model-form',['model'=>null])</x-nf.section>
    @if($historyVersions)
        <div id="nf-provider-history"><x-nf.section :heading="$historyTitle">
            <p>只读投影最近100个实际版本；模型声明始终未验证。密钥只比较“是否配置”，不展示或比较密钥内容与内部凭据标识。</p>
            @php($options=collect($historyVersions)->mapWithKeys(fn($v,$id)=>[(string)$id=>'v'.$v['version'].' · #'.$id])->all())
            <x-nf.form-grid>
                <x-nf.select id="provider-history-old" label="历史旧版本" model="historyLeft" :value="$historyLeft" :options="$options" />
                <x-nf.select id="provider-history-new" label="历史新版本" model="historyRight" :value="$historyRight" :options="$options" />
            </x-nf.form-grid>
            @if(isset($historyVersions[$historyLeft],$historyVersions[$historyRight]))
                @include('filament.value-comparison',['leftValue'=>$historyVersions[$historyLeft]['value'],'rightValue'=>$historyVersions[$historyRight]['value'],'comparisonLabel'=>'供应商模型历史版本比较'])
            @else<p role="alert">请选择本次读取到的实际版本。</p>@endif
        </x-nf.section></div>
    @endif
</x-filament-panels::page>
