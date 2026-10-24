<x-filament-panels::page>
    {{-- 所有配置正文均转义展示，模板不能作为 Blade 或 HTML 执行。 --}}
    @php
        $kind=$this->configurationKind(); $catalog=$this->catalog(); $comparison=$this->comparison();
        $samples=\App\Support\ConfigurationExamples::all();
        $encode=fn($value)=>json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    @endphp
    <p>配置草稿 → 离线回归 → 发布 → 退役。编辑总是创建新版本；旧运行与旧评分策略保持不变。</p>
    <p>当前仅验证离线夹具。真实模型能力未验证、价格未知；不能据此开启真实调用。评分样章使用人工标注分数，不是模型评审。</p>
    @if(session('configuration_error')) <p role="alert">{{session('configuration_error')}}</p> @endif
    @if(session('configuration_notice')) <p role="status">{{session('configuration_notice')}}</p> @endif
    <div class="grid gap-3 md:grid-cols-4">
        <label>配置键 <input wire:model.live.debounce.400ms="q" class="w-full rounded border p-2"></label>
        <label>阶段 <input wire:model.live.debounce.400ms="stage" class="w-full rounded border p-2"></label>
        <label>语言 <input wire:model.live.debounce.400ms="language" class="w-full rounded border p-2"></label>
        <label>状态 <select wire:model.live="status" class="w-full rounded border p-2"><option value="">全部</option>@foreach(['draft','tested','published','retired'] as $state)<option>{{$state}}</option>@endforeach</select></label>
    </div>
    <details><summary>新建配置（按示例填写字段）</summary>
        <form method="POST" action="/api/v1/configurations/{{$kind}}/save">@csrf
            <input type="hidden" name="command_key" value="{{Str::uuid()}}">
            <label>键、阶段、语言、版本说明与完整正文 JSON<textarea name="payload_json" rows="22" class="w-full rounded border p-3 font-mono" required>{{$encode($samples[$kind])}}</textarea></label>
            <x-filament::button type="submit">保存草稿</x-filament::button>
        </form>
        <p>Prompt 变量仅支持 @{{json:变量名}}，禁止未转义占位符。路由引用需填写真实版本标识，备用模型须明确批准。文风包含视角、语气、节奏、描写、禁用套路、授权样例、评分维度、阈值及修订轮次。</p>
    </details>
    <div class="overflow-x-auto"><table class="w-full text-left"><thead><tr><th>配置 / 版本</th><th>阶段 / 语言</th><th>状态 / 默认</th><th>回归</th><th>引用运行数</th></tr></thead><tbody>
        @forelse($catalog['items'] as $v)
            <tr><td>{{$v['key']}} #{{$v['version']}}（ID {{$v['id']}}）</td><td>{{$v['stage']}} / {{$v['language']}}</td><td>{{$v['status']}} {{$v['default_version_id']===$v['id']?'· 默认':''}}</td><td>{{$v['regression']===null?'未测试':($v['regression']['passed']?'离线通过':'失败')}}</td><td>{{$v['run_count']}}</td></tr>
            <tr><td colspan="5"><details><summary>详情、复制编辑与版本操作</summary>
                <p>变更说明：{{$v['reason']}}；摘要：{{$v['content_hash']}}</p>
                <pre class="overflow-x-auto whitespace-pre-wrap">{{$encode($v['content'])}}</pre>
                <pre class="overflow-x-auto whitespace-pre-wrap">{{$encode($v['regression'])}}</pre>
                @foreach(['test'=>'draft','publish'=>'tested','retire'=>'published'] as $action=>$state)
                    @if($v['status']===$state)
                    <form method="POST" action="/api/v1/configurations/{{$kind}}/{{$action}}" class="inline-block">@csrf
                        <input type="hidden" name="command_key" value="{{Str::uuid()}}"><input type="hidden" name="version_id" value="{{$v['id']}}">
                        <x-filament::button type="submit">{{['test'=>'验证变量与离线回归','publish'=>'发布为新运行默认版本','retire'=>'退役（旧运行保留）'][$action]}}</x-filament::button>
                    </form>
                    @endif
                @endforeach
                <details><summary>复制并编辑为新版本</summary><form method="POST" action="/api/v1/configurations/{{$kind}}/save">@csrf
                    <input type="hidden" name="command_key" value="{{Str::uuid()}}">
                    <textarea aria-label="新版本配置" name="payload_json" rows="20" class="w-full rounded border p-3 font-mono">{{$encode(['key'=>$v['key'],'stage'=>$v['stage'],'language'=>$v['language'],'expected_version'=>$v['version'],'reason'=>'请说明本次变更，降低评分阈值须说明原因','content'=>$v['content']])}}</textarea>
                    <p>expected_version 填写此配置当前最新版本号；过期编辑会被拒绝。</p><x-filament::button type="submit">保存新草稿</x-filament::button>
                </form></details>
            </details></td></tr>
        @empty <tr><td colspan="5">暂无配置版本。先创建 Prompt 与文风并通过回归发布，再创建引用它们的路由。</td></tr> @endforelse
    </tbody></table></div>
    <div>共 {{$catalog['total']}} 个版本，第 {{$catalog['page']}} 页
        @if($this->page>1)<x-filament::button wire:click="$set('page', {{$this->page-1}})">上一页</x-filament::button>@endif
        @if($this->page*20<$catalog['total'])<x-filament::button wire:click="$set('page', {{$this->page+1}})">下一页</x-filament::button>@endif
    </div>
    <details><summary>比较两个版本（正文、阈值和离线结果）</summary>
        <label>旧版本 ID <input wire:model.live.debounce.400ms="compareLeft" class="rounded border p-2"></label>
        <label>新版本 ID <input wire:model.live.debounce.400ms="compareRight" class="rounded border p-2"></label>
        @if($comparison)<pre class="overflow-x-auto whitespace-pre-wrap">{{$encode($comparison)}}</pre>@endif
        <p>Token 实测与文学评审尚未执行；回归报告中的 actual_tokens 为空，不能解读为零成本。</p>
    </details>
</x-filament-panels::page>
