<x-filament-panels::page>
    @php($inventory = $this->inventory())
    {{-- 沿用纸白、墨色、靛蓝与轻边界；首要任务是补齐配置，不展示虚构的运行进度。 --}}
    <x-nf.section heading="先准备一本书，再验收其中一章">
        <p>依次补齐下列内容，配置保存后回到本页刷新。小说的总章数不代表本次执行范围，单章验收也不代表整书通过。</p>
        {{-- 首个缺项是本页主动作；全部有记录时仍不生成“可启动”结论。 --}}
        @php($next = isset($inventory['error']) ? null : match(true) {
            $inventory['novels'] === 0 => ['下一步：录入小说要求', \App\Filament\Resources\NovelResource::getUrl('create')],
            $inventory['providers'] === 0 => ['下一步：配置供应商', \App\Filament\Pages\Providers::getUrl()],
            $inventory['models'] === 0 => ['下一步：配置模型', \App\Filament\Pages\Providers::getUrl()],
            $inventory['prices'] === 0 || $inventory['budgets'] === 0 => ['下一步：配置价格与预算', \App\Filament\Pages\Billing::getUrl()],
            $inventory['prompts'] === 0 => ['下一步：准备提示词', \App\Filament\Pages\PromptTemplates::getUrl()],
            $inventory['styles'] === 0 => ['下一步：准备文风策略', \App\Filament\Pages\StyleProfiles::getUrl()],
            $inventory['routes'] === 0 => ['下一步：准备路由草稿', \App\Filament\Pages\ModelRoutes::getUrl()],
            default => null,
        })
        <div class="nf-config-actions">
            @if($next)
                <x-filament::button tag="a" :href="$next[1]">{{ $next[0] }}</x-filament::button>
            @endif
            <x-filament::button color="gray" wire:click="$refresh" wire:loading.attr="disabled">刷新准备状态</x-filament::button>
        </div>
        <div wire:loading role="status">正在读取配置…</div>
    </x-nf.section>
    @if(isset($inventory['error']))
        <x-nf.state state="error" title="准备状态读取失败">{{ $inventory['error'] }}</x-nf.state>
    @else
        {{-- 配置状态与运行资格分开展示；不得要求启用供应商来通过准备步骤。 --}}
        <x-nf.state state="notice" title="配置录入与真实运行分别检查">
            <p>已配置供应商：{{ $inventory['providers'] }}，其中停用：{{ $inventory['disabled_providers'] }}；已登记模型：{{ $inventory['models'] }}。</p>
            <p>停用但已配置的供应商及模型可以继续填写价格和预算，无需启用。可真实运行：尚未具备，仍需协议接线、能力验证、预算及外发范围确认。</p>
        </x-nf.state>
        {{-- 数量只证明存在记录；历史版本、草稿与虚构报价均不能冒充真实可用配置。 --}}
        @php($steps = [
            ['title' => '1. 小说与原始要求', 'count' => $inventory['novels'], 'description' => '录入题材、原始创意、目标总章数，并明确单章字数上下限、背景和结局要求。未填写的信息保留待决定。这里只统计属于你的未归档小说。', 'links' => [['新建小说', \App\Filament\Resources\NovelResource::getUrl('create')], ['选择已有小说', \App\Filament\Resources\NovelResource::getUrl('index')]]],
            ['title' => '2. 供应商与密钥', 'count' => $inventory['providers'], 'description' => '填写供应商、完整 API 地址、域名白名单与接口类型。密钥仅在供应商表单录入，不要放入创意、提示词或备注。这里只统计最新版本有密钥且非 Fake 的供应商，停用配置也计入；JSON HTTP 配置不代表已适配该供应商协议。', 'links' => [['配置供应商', \App\Filament\Pages\Providers::getUrl()]]],
            ['title' => '3. 生成与独立评审模型', 'count' => $inventory['models'], 'description' => '分别登记生成模型与独立评审模型，核对模型标识、上下文、输出容量及结构化输出能力，并填写能力来源。声明能力仍需实测，存在两个模型也不代表评审独立性已验收。这里只统计上述已配置供应商下的模型档案，不因供应商停用而排除。', 'links' => [['配置模型档案', \App\Filament\Pages\Providers::getUrl()]]],
            ['title' => '4. 真实价格', 'count' => $inventory['prices'], 'description' => '按所选模型版本登记真实币种、输入输出及缓存或推理价格、有效时间和报价来源。这里包含历史价格，尚未检查是否覆盖所选模型，不能使用离线虚构报价启动真实请求。', 'links' => [['登记模型价格', \App\Filament\Pages\Billing::getUrl()]]],
            ['title' => '5. 预算上限', 'count' => $inventory['budgets'], 'description' => '明确单次请求、单章、单次运行、单书及每日上限和每日结算时区；预算币种须与价格一致。记录存在不代表已为本次验收选定预算。', 'links' => [['设置预算政策', \App\Filament\Pages\Billing::getUrl()]]],
            ['title' => '6. 提示词、文风与模型路由', 'count' => $inventory['routes'], 'description' => '为规划、正文、提取、评审及修订准备各阶段提示词与输出协议、文风策略和主备模型映射。当前仍仅支持离线路由发布；真实路由需在协议适配及执行接线完成后启用。', 'links' => [['提示词模板', \App\Filament\Pages\PromptTemplates::getUrl()], ['文风策略', \App\Filament\Pages\StyleProfiles::getUrl()], ['模型路由', \App\Filament\Pages\ModelRoutes::getUrl()]]],
        ])
        @foreach($steps as $step)
            <x-nf.section :heading="$step['title']">
                <p>{{ $step['description'] }}</p>
                <p>{{ $step['count'] === 0 ? '尚无符合上述口径的记录' : '已有记录：'.$step['count'].'；仍需核对本次验收配置' }}</p>
                <div class="nf-config-actions">
                    @foreach($step['links'] as [$label, $url])
                        <x-filament::button color="gray" tag="a" :href="$url">{{ $label }}</x-filament::button>
                    @endforeach
                </div>
            </x-nf.section>
        @endforeach
    @endif
    <x-nf.section heading="启动前仍需确认">
        <p>真实协议适配与生产流水线尚未接通，本页不能启动真实调用。上述配置保存后，还需核对模型实测能力、所选预算，以及允许向供应商发送的原始创意、规划、正文、提取、评审和修订上下文范围。</p>
        <p>真实请求、费用、正式提交、记忆与导出证据尚未形成。Fake 回放、评审通过和正式剧情提交分别记录；评审通过不能自动当作正式提交。</p>
    </x-nf.section>
</x-filament-panels::page>
