<x-filament-panels::page x-data="{}" x-on:configuration-result.window="$el.scrollIntoView({block:'start'})"
    x-on:configuration-editor-opened.window="$nextTick(()=>{const form=document.getElementById('nf-configuration-editor');form?.scrollIntoView({block:'start'});form?.querySelector('input:not([disabled]):not([readonly]):not([type=hidden]),textarea:not([disabled])')?.focus({preventScroll:true})})"
    x-on:configuration-detail-opened.window="$nextTick(()=>document.getElementById('nf-configuration-detail')?.scrollIntoView({block:'start'}))">
    {{-- 固定字段使用Filament Schema，递归结构仅处理Schema和样例；发布历史始终只读。 --}}
    @php($kind=$this->configurationKind())
    @php($catalog=$this->catalog())
    <p>编辑创建新草稿，离线回归、发布和退役分别执行；旧运行继续引用原版本。</p>
    @if($notice)<x-nf.state state="success" title="操作结果">{{ $notice }}</x-nf.state>@endif
    @if($errors->any())<x-nf.state state="error" title="尚未保存，请核对字段">
        <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </x-nf.state>@endif
    @if(session('configuration_error'))<x-nf.state state="error" title="操作失败">{{ session('configuration_error') }}</x-nf.state>@endif
    <x-filament::button wire:click="beginConfiguration" wire:loading.attr="disabled" :disabled="$editorOpen">新建{{ ['prompt'=>' Prompt 模板','route'=>'模型路由','style'=>'文风策略'][$kind] }}</x-filament::button>

    @if($editorOpen)
        <form id="nf-configuration-editor" wire:submit="saveConfiguration" class="nf-config-editor">
            {{ $this->configurationForm }}
            @foreach($trees as $treeKey=>$treeNode)
                <x-nf.section :heading="['variable_schema'=>'变量结构','output_schema'=>'输出结构','examples'=>$kind==='style'?'样章与人工评分':'离线样例','rubric'=>'评分维度与阈值'][$treeKey]">
                    @if(str_contains($treeKey,'schema'))<p>递归声明属性、类型、必填名列表及长度上限。对象须禁止额外属性；当前服务不支持 enum、oneOf、$ref 或类型联合，导入时保留这些字段但阻止保存。</p>@endif
                    @if($treeKey==='examples')<x-filament::button color="gray" wire:click="addConfigurationExample">添加样例（可编辑副本）</x-filament::button><p>逐项填写样例；分数是人工标注，回归不调用模型，也不证明文学质量。</p>@endif
                    @if($treeKey==='rubric')<x-filament::button color="gray" wire:click="addRubricDimension">添加评分维度</x-filament::button><p>维度使用稳定英文键；新增维度须填写说明和阈值，样例评分应覆盖每个维度。</p>@endif
                    @if(str_contains($treeKey,'schema'))
                        @error('trees.'.$treeKey)<p role="alert">{{ $message }}</p>@enderror
                        @include('filament.schema-fields',['node'=>$treeNode,'path'=>'trees.'.$treeKey,'label'=>'结构定义'])
                        <x-filament::button color="gray" wire:click="toggleAdvancedTree('{{ $treeKey }}')">其他关键词与类型字段（保留未知键）</x-filament::button>
                        @if($advancedTrees[$treeKey]??false)
                            @include('filament.json-field',['node'=>$treeNode,'path'=>'trees.'.$treeKey,'label'=>'完整结构字段'])
                        @endif
                    @else
                        @include('filament.json-field',['node'=>$treeNode,'path'=>'trees.'.$treeKey,'label'=>$treeKey==='examples'?'有序样例':'维度名称'])
                    @endif
                </x-nf.section>
            @endforeach
            @if($this->configurationExtras())
                <x-nf.section heading="未映射扩展字段（保留，只读）">
                    <p>这些字段未被当前表单和服务契约接纳，不会静默丢弃。可先导出；保存仍由原服务拒绝未知字段。</p>
                    @include('filament.value-table',['value'=>$this->configurationExtras(),'label'=>'保留的扩展字段'])
                </x-nf.section>
            @endif
            <div class="nf-config-actions"><x-filament::button type="submit" wire:loading.attr="disabled">保存新草稿</x-filament::button>
                <x-filament::button color="gray" wire:click="cancelConfiguration">取消编辑</x-filament::button>
                <x-filament::button color="gray" wire:click="exportConfigurationContent">导出当前内容</x-filament::button>
                <span wire:loading wire:target="saveConfiguration">正在校验与保存…</span>
            </div>
            <details><summary>高级导入（仅内容，不改变身份；错误不覆盖表单）</summary>
                <x-nf.field id="nf-config-import" label="导入内容 JSON" :error="$errors->first('importJson')"><textarea id="nf-config-import" rows="6" wire:model="importJson"></textarea></x-nf.field>
                <x-filament::button color="gray" wire:click="importConfigurationContent">校验后导入编辑区</x-filament::button>
            </details>
        </form>
    @endif

    <x-nf.section heading="配置版本">
        <x-nf.form-grid :filters="true">
            <x-nf.input id="config-filter-key" label="配置键" wire:model.live.debounce.400ms="q" />
            <x-nf.input id="config-filter-stage" label="阶段" wire:model.live.debounce.400ms="stage" />
            <x-nf.input id="config-filter-language" label="语言" wire:model.live.debounce.400ms="language" />
            <x-nf.select id="config-filter-status" label="状态" model="status" :value="$status" :options="[''=>'全部','draft'=>'草稿','tested'=>'离线通过','published'=>'已发布','retired'=>'已退役']" />
        </x-nf.form-grid>
        <x-nf.table label="配置版本列表"><table><thead><tr><th>配置 / 版本</th><th>阶段 / 语言</th><th>状态 / 默认</th><th>离线回归</th><th>引用运行</th><th>操作</th></tr></thead><tbody>
            @forelse($catalog['items'] as $v)<tr>
                <td>{{ $v['key'] }} · v{{ $v['version'] }} · #{{ $v['id'] }}</td><td>{{ $v['stage'] }} / {{ $v['language'] }}</td>
                <td>{{ ['draft'=>'草稿','tested'=>'离线通过','published'=>'已发布','retired'=>'已退役'][$v['status']] }}{{ $v['default_version_id']===$v['id']?' · 默认':'' }}</td>
                <td>{{ $v['regression']===null?'未测试':($v['regression']['passed']?'离线通过':'离线失败') }}</td><td>{{ $v['run_count'] }}</td>
                <td><div class="nf-config-actions"><x-filament::button size="sm" color="gray" wire:click="showConfiguration('{{ $v['id'] }}')">只读详情</x-filament::button>
                    <x-filament::button size="sm" color="gray" wire:click="beginConfiguration('{{ $v['id'] }}')">复制为新版本</x-filament::button>
                    @foreach(['test'=>'draft','publish'=>'tested','retire'=>'published'] as $action=>$state)
                        @if($v['status']===$state)<x-filament::button size="sm" color="gray" wire:click="mountAction('configurationLifecycle', {operation: '{{ $action }}', version: '{{ $v['id'] }}'})" wire:loading.attr="disabled">{{ ['test'=>'离线回归','publish'=>'发布','retire'=>'退役'][$action] }}</x-filament::button>@endif
                    @endforeach
                </div></td>
            </tr>@empty<tr><td colspan="6">暂无配置版本。可先建立 Prompt 与文风，离线回归发布后再配置引用它们的路由。</td></tr>@endforelse
        </tbody></table></x-nf.table>
        <div class="nf-config-actions"><span>共 {{ $catalog['total'] }} 个版本 · 第 {{ $catalog['page'] }} 页</span>
            <x-filament::button color="gray" size="sm" wire:click="$set('page', {{ max(1,$page-1) }})" :disabled="$page<=1">上一页</x-filament::button>
            <x-filament::button color="gray" size="sm" wire:click="$set('page', {{ $page+1 }})" :disabled="$page*20 >= $catalog['total']">下一页</x-filament::button>
        </div>
    </x-nf.section>
    @if($configurationDetail)
        <div id="nf-configuration-detail"><x-nf.section :heading="'只读版本 · '.$configurationDetail['key'].' v'.$configurationDetail['version']">
            <p>说明：{{ $configurationDetail['reason'] }} · 状态 {{ $configurationDetail['status'] }} · 摘要 {{ $configurationDetail['content_hash'] }}</p>
            @include('filament.value-table',['value'=>$configurationDetail['content'],'label'=>'版本逐字段只读内容'])
            @if($configurationDetail['regression'])@include('filament.value-table',['value'=>$configurationDetail['regression'],'label'=>'离线回归逐字段结果'])@else<p>尚未进行离线回归。</p>@endif
        </x-nf.section></div>
    @endif
    <x-nf.section heading="同一配置的版本比较">
        <x-nf.form-grid>
            <x-nf.select id="compare-old" label="旧版本" model="compareLeft" :value="$compareLeft" :options="[''=>'请选择']+$this->configurationOptions($kind)" />
            <x-nf.select id="compare-new" label="新版本" model="compareRight" :value="$compareRight" :options="[''=>'请选择']+$this->configurationOptions($kind)" />
        </x-nf.form-grid>
        @php($comparison=$this->comparison())
        @if(isset($comparison['error']))<p role="alert">{{ $comparison['error'] }}</p>
        @elseif($comparison)
            @include('filament.value-comparison',['leftValue'=>['content'=>$comparison['left']['content'],'regression'=>$comparison['left']['regression']],'rightValue'=>['content'=>$comparison['right']['content'],'regression'=>$comparison['right']['regression']],'comparisonLabel'=>'版本字段比较'])
        @else<p>选择两个真实版本；仅允许同一配置身份。未知 Token 仍显示空值，不代表零成本。</p>@endif
    </x-nf.section>
</x-filament-panels::page>
