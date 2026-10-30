<x-filament-panels::page>
    <div class="nf-config-actions">
        <x-filament::button tag="a" href="/admin/novels/create">创建小说</x-filament::button>
        <x-filament::button color="gray" tag="a" href="/admin/novels">小说库 / 归档与恢复</x-filament::button>
        {{-- NF-F01：把准备入口与小说入口放在一起，缺少配置时无需猜测导航路径。 --}}
        <x-filament::button color="gray" tag="a" :href="\App\Filament\Pages\SingleChapterSetup::getUrl()">单章验收准备</x-filament::button>
        <x-filament::button color="gray" wire:click="$refresh" wire:loading.attr="disabled">刷新列表</x-filament::button>
    </div>
    <div wire:loading role="status">正在读取小说列表…</div>
    <x-nf.section heading="找到小说，继续创作">
        <p>按最近更新时间浏览小说，进入本书工作台核对规划与缺项。这里不是运行进度或模型质量报告。</p>
        <x-nf.form-grid :filters="true">
            <x-nf.select id="overview-status" label="小说状态" model="status" :value="$status" :options="[''=>'全部','draft'=>'草稿','planning'=>'规划中','ready'=>'就绪','generating'=>'生成中','paused'=>'暂停','completed'=>'完成','archived'=>'已归档']" />
            <x-nf.input id="overview-from" type="date" label="更新日期从（上海时间）" wire:model.live="from" />
            <x-nf.input id="overview-until" type="date" label="更新日期至（上海时间）" wire:model.live="until" />
            <x-nf.select id="overview-limit" label="每页数量" model="limit" :value="$limit" :options="[10=>'10本',25=>'25本',50=>'50本',100=>'100本']" />
        </x-nf.form-grid>
        @php($data=$this->overview())
        @if(isset($data['error']))
            <x-nf.state state="error" title="读取失败">{{ $data['error'] }}</x-nf.state>
            <x-filament::button color="gray" wire:click="$refresh">重试</x-filament::button>
        @else
            <x-nf.table label="小说与创作入口"><table class="nf-overview-table"><colgroup><col style="width:36%"><col style="width:16%"><col style="width:10%"><col style="width:24%"><col style="width:14%"></colgroup><thead><tr><th>小说</th><th>题材 / 存储状态</th><th>目标章数</th><th>最近更新（上海时间）</th><th>创作入口</th></tr></thead><tbody>
            @forelse($data['items'] as $row)
                <tr><td><a href="/admin/novels/{{ $row->id }}/workspace">{{ $row->title }}</a></td><td>{{ $row->genre }} / {{ ['draft'=>'草稿','planning'=>'规划中','ready'=>'就绪','generating'=>'生成中','paused'=>'暂停','completed'=>'完成','archived'=>'已归档'][$row->status] }}</td><td>{{ $row->target_chapters }}</td><td>{{ $row->updated_at->timezone('Asia/Shanghai')->format('Y-m-d H:i') }}</td><td><a href="/admin/novels/{{ $row->id }}/workspace">打开工作台</a></td></tr>
            @empty<tr><td colspan="5"><x-nf.state state="empty" :title="$data['has_books']?'没有符合条件的小说':'尚未创建小说'">@if($data['has_books'])请调整状态或更新日期筛选。@else<a href="/admin/novels/create">创建第一本小说</a>，从原始想法开始。@endif</x-nf.state></td></tr>@endforelse
            </tbody></table></x-nf.table>
            <div class="nf-config-actions"><span>第 {{ $data['page'] }} / {{ $data['last_page'] }} 页 · {{ $data['total'] }} 本</span>
                <x-filament::button color="gray" wire:click="$set('page',{{ max(1,$page-1) }})" :disabled="$page<=1">上一页</x-filament::button>
                <x-filament::button color="gray" wire:click="$set('page',{{ $page+1 }})" :disabled="$page>=$data['last_page']">下一页</x-filament::button>
            </div>
        @endif
    </x-nf.section>
    <x-nf.section heading="生产指标待接入">
        <p>已提交章数、今日费用、预算余额、Worker心跳、索引延迟：未知，尚未接入总览。</p>
        <p>最近运行、继续上次任务、异常与账单尚不可用；未显示数值不代表零，也不代表系统健康。</p>
    </x-nf.section>
</x-filament-panels::page>
