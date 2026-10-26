<x-filament-panels::page>
<div class="flex gap-3"><a href="/admin/novels/create">创建小说</a><a href="/admin/novels">小说列表 / 归档与恢复</a><x-filament::button wire:click="$refresh" wire:loading.attr="disabled">刷新</x-filament::button></div>
<div wire:loading role="status">正在读取小说列表…</div>
<x-nf.section heading="生产指标待接入">
<p>已提交章数、今日费用、预算余额、Worker 心跳、索引延迟：未知，尚未接入。</p>
<p>最近运行、继续上次任务、异常与账单尚不可用。下表按小说最近更新时间排序，不代表运行记录或任务进度。</p>
</x-nf.section>
<x-nf.section heading="小说与创作入口">
<label>小说状态 <select wire:model.live="status"><option value="">全部</option>@foreach(['draft'=>'草稿','planning'=>'规划中','ready'=>'就绪','generating'=>'生成中','paused'=>'暂停','completed'=>'完成','archived'=>'已归档'] as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
<label>更新日期从 <input type="date" wire:model.live="from" /></label><label>至 <input type="date" wire:model.live="until" /></label>
<label>每页 <select wire:model.live="limit"><option>10</option><option>25</option><option>50</option><option>100</option></select></label>
@php($data=$this->overview())
@if(isset($data['error']))<x-nf.state state="error" title="读取失败">{{ $data['error'] }}</x-nf.state><x-filament::button wire:click="$refresh">重试</x-filament::button>
@else
<x-nf.table label="小说与创作入口"><table class="w-full text-left"><thead><tr><th>小说</th><th>题材 / 存储状态</th><th>目标章数</th><th>最近更新（上海时间）</th><th>创作入口</th></tr></thead><tbody>
@forelse($data['items'] as $row)<tr><td>{{ $row->title }}</td><td>{{ $row->genre }} / {{ ['draft'=>'草稿','planning'=>'规划中','ready'=>'就绪','generating'=>'生成中','paused'=>'暂停','completed'=>'完成','archived'=>'已归档'][$row->status] }}</td><td>{{ $row->target_chapters }}</td><td>{{ $row->updated_at->timezone('Asia/Shanghai')->format('Y-m-d H:i') }}</td><td><a href="/admin/novels/{{ $row->id }}/workspace">打开工作台</a></td></tr>@empty<tr><td colspan="5">@if($data['has_books'])没有符合条件的小说，请调整筛选。@else 尚未创建小说。<a href="/admin/novels/create">创建第一本小说</a>@endif</td></tr>@endforelse
</tbody></table></x-nf.table>
<p>第 {{ $data['page'] }} / {{ $data['last_page'] }} 页 · {{ $data['total'] }} 本</p>
@if($page>1)<x-filament::button wire:click="$set('page',{{ $page-1 }})">上一页</x-filament::button>@endif
@if($page<$data['last_page'])<x-filament::button wire:click="$set('page',{{ $page+1 }})">下一页</x-filament::button>@endif
@endif
</x-nf.section>
</x-filament-panels::page>
