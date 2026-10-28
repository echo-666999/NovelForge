<x-filament-panels::page x-data="{ providerTrigger: null, modelTrigger: null }"
    x-on:provider-editor-opened.window="$nextTick(()=>{const kind=$event.detail.kind; (kind==='provider'?providerTrigger:modelTrigger)?.focus({preventScroll:true}); $dispatch('open-modal',{id:'nf-'+kind+'-editor-modal'});})"
    x-on:provider-history-opened.window="$nextTick(()=>$dispatch('open-modal',{id:'nf-provider-history-modal'}))"
    x-on:modal-closed.window="if ($event.detail.id==='nf-provider-editor-modal') { if ($wire.providerEditor !== '') $wire.closeProviderEditor('provider'); $nextTick(()=>(providerTrigger?.isConnected ? providerTrigger : document.getElementById('nf-provider-create'))?.focus({preventScroll:true})); } else if ($event.detail.id==='nf-model-editor-modal') { if ($wire.modelEditor !== '') $wire.closeProviderEditor('model'); $nextTick(()=>(modelTrigger?.isConnected ? modelTrigger : document.getElementById('nf-model-create'))?.focus({preventScroll:true})); }">
    {{-- UI23：默认只展示安全列表；新增、复制编辑及历史均按需进入原生弹窗。 --}}
    @php($catalog=$this->catalog())
    @php($providerOptions=$this->providerOptions())
    @if(session('provider_notice'))<x-nf.state state="success" title="操作结果">{{ session('provider_notice') }}</x-nf.state>@endif
    <p>保存只追加配置版本，不请求模型、不探测端点。能力声明与实测分开，密钥永不回显。</p>
    <div class="nf-config-actions">
        <x-filament::button id="nf-provider-create" x-on:click="providerTrigger=$el" wire:click="beginProviderCreation('provider')" wire:loading.attr="disabled">新建供应商</x-filament::button>
        <x-filament::button id="nf-model-create" color="gray" x-on:click="modelTrigger=$el" wire:click="beginProviderCreation('model')" wire:loading.attr="disabled">新建模型声明</x-filament::button>
    </div>
    <x-nf.section heading="供应商配置">
        <x-nf.form-grid :filters="true">
            <x-nf.input id="provider-filter-name" label="供应商名称筛选" wire:model.live.debounce.300ms="q" />
            <x-nf.select id="provider-filter-interface" label="接口" model="interface" :value="$interface" :options="[''=>'全部','fake'=>'离线模拟','json_http'=>'通用JSON协议']" />
            <x-nf.select id="provider-filter-state" label="配置状态" model="enabled" :value="$enabled" :options="[''=>'全部','1'=>'启用','0'=>'停用']" />
        </x-nf.form-grid>
        <x-nf.table label="供应商配置列表"><table><thead><tr><th>供应商 / 版本</th><th>配置状态</th><th>密钥状态</th><th>操作</th></tr></thead><tbody>
        @forelse($catalog['providers'] as $provider)
            <tr wire:key="provider-card-{{ $provider['id'] }}"><td>{{ $provider['name'] }} · v{{ $provider['version'] }}</td><td>{{ $provider['enabled']?'启用配置':'停用' }} · 能力未验证</td><td>{{ $provider['has_key']?'已配置':'未配置' }}</td><td><div class="nf-config-actions">
                <x-filament::button color="gray" x-on:click="providerTrigger=$el" wire:click="openProviderEditor('{{ $provider['id'] }}')">编辑新版本</x-filament::button>
                <x-filament::button color="gray" wire:click="readConfigurationHistory('provider','{{ $provider['id'] }}')">只读配置历史与比较</x-filament::button>
            </div></td></tr>
        @empty<tr><td colspan="4"><x-nf.state state="empty" title="暂无符合条件的供应商">可调整筛选，或先创建配置。创建不会发起请求。</x-nf.state></td></tr>@endforelse
        </tbody></table></x-nf.table>
        <div class="nf-config-actions"><span>共 {{ $catalog['total'] }} 个供应商 · 第 {{ $catalog['page'] }} 页</span>
            <x-filament::button color="gray" wire:click="$set('page',{{ max(1,$page-1) }})" :disabled="$page<=1">上一页</x-filament::button>
            <x-filament::button color="gray" wire:click="$set('page',{{ $page+1 }})" :disabled="$page*25 >= $catalog['total']">下一页</x-filament::button>
        </div>
    </x-nf.section>
    <x-nf.section heading="模型能力版本">
        <x-nf.select id="model-filter-structured" label="结构化能力声明" model="structured" :value="$structured" :options="[''=>'全部','unknown'=>'未知','supported'=>'声明支持','unsupported'=>'声明不支持']" />
        <div class="nf-catalog-results"><x-nf.table label="模型能力版本列表"><table><thead><tr><th>供应商 / 模型</th><th>版本</th><th>验证状态</th><th>操作</th></tr></thead><tbody>
        @forelse($catalog['models'] as $model)
            <tr wire:key="model-card-{{ $model['version_id'] }}"><td>{{ $providerOptions[$model['provider_id']]??$model['provider_id'] }} / {{ $model['model'] }}</td><td>v{{ $model['version'] }}</td><td>未验证</td><td><div class="nf-config-actions">
                <x-filament::button color="gray" x-on:click="modelTrigger=$el" wire:click="openModelEditor('{{ $model['version_id'] }}')">复制声明新版本</x-filament::button>
                <x-filament::button color="gray" wire:click="readConfigurationHistory('model','{{ $model['version_id'] }}')">只读模型历史与比较</x-filament::button>
            </div></td></tr>
        @empty<tr><td colspan="4"><x-nf.state state="empty" title="暂无模型能力档案">点击“新建模型声明”后选择实际供应商；未知容量留空。</x-nf.state></td></tr>@endforelse
        </tbody></table></x-nf.table></div>
        <p>展示当前筛选供应商下最新的最多100个模型档案；历史能力版本继续可由路由版本引用。此处不提供真实能力验证动作。</p>
    </x-nf.section>
    <x-nf.editor-modal id="nf-provider-editor-modal" :heading="$providerEditor === 'new' ? '新建供应商' : '供应商新版本'"
        x-init="if ({{ str_starts_with(session('provider_form',''), 'provider-') ? 'true' : 'false' }}) $nextTick(()=>$dispatch('open-modal',{id:'nf-provider-editor-modal'}))">
        @if($providerEditor !== '')
            @php($editingProvider=$providerEditor==='new'?null:collect($catalog['providers'])->firstWhere('id',$providerEditor))
            @if($providerEditor==='new' || $editingProvider)
                @include('filament.provider-form',['provider'=>$editingProvider])
            @else<x-nf.state state="empty" title="当前筛选下无法读取供应商">请关闭后从列表重新选择。</x-nf.state>@endif
        @endif
    </x-nf.editor-modal>
    <x-nf.editor-modal id="nf-model-editor-modal" :heading="$modelEditor === 'new' ? '新建模型声明' : '模型声明新版本'"
        x-init="if ({{ str_starts_with(session('provider_form',''), 'model-') ? 'true' : 'false' }}) $nextTick(()=>$dispatch('open-modal',{id:'nf-model-editor-modal'}))">
        @if($modelEditor !== '')
            @php($editingModel=$modelEditor==='new'?null:collect($catalog['models'])->firstWhere('version_id',$modelEditor))
            @if($modelEditor==='new' || $editingModel)
                @if($editingModel)@include('filament.value-table',['value'=>$editingModel['declared_capabilities'],'label'=>'能力声明逐字段只读'])@endif
                @include('filament.model-form',['model'=>$editingModel])
            @else<x-nf.state state="empty" title="当前筛选下无法读取模型声明">请关闭后从列表重新选择。</x-nf.state>@endif
        @endif
    </x-nf.editor-modal>
    <x-nf.editor-modal id="nf-provider-history-modal" heading="配置历史与版本比较">
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

    </x-nf.editor-modal>
</x-filament-panels::page>
