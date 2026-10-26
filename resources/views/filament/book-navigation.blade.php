{{-- 所有身份和入口只从授权查询获得；不把客户端回传的标题/基线当真实状态。 --}}
<x-nf.section heading="小说上下文">
    <form method="GET" action="{{ route('novel-context.switch') }}" class="nf-book-switch">
        <input type="hidden" name="page" value="{{ $navigation['page'] }}">
        @php
            $options = ['' => '选择小说'];
            foreach ($navigation['books'] as $option) {
                $options[(string) $option->id] = $option->title.($option->status === 'archived' ? '（已归档）' : '');
            }
        @endphp
        <div class="nf-book-picker"><x-nf.select id="nf-navigation-novel" label="当前小说" name="novel"
            :value="$navigation['book']['id'] ?? ''" :options="$options" :disabled="count($navigation['books']) === 0" /></div>
        <x-filament::button type="submit" color="gray" :disabled="count($navigation['books']) === 0">切换小说</x-filament::button>
        <a href="/admin/novels">小说库</a>
    </form>
    @if($navigation['book'])
        @php($book = $navigation['book'])
        <p class="nf-book-identity"><strong>当前小说：{{ $book['title'] }}</strong> · #{{ $book['id'] }} @if($book['status'] === 'archived') · 已归档 @endif</p>
        @if($book['baseline'])
            <p class="nf-book-baseline">正式基线 #{{ $book['baseline']['id'] }} · v{{ $book['baseline']['version'] }} · @if($book['baseline']['cursor'] === 0)尚无正式章节@else连续提交至第{{ $book['baseline']['cursor'] }}章@endif · 状态版本 {{ $book['state_version'] }}</p>
            @if($book['baseline']['state_version'] !== $book['state_version'])<p role="alert">正式基线与小说状态版本不一致，请到正式提交中心核对。</p>@endif
        @elseif($book['baseline_status'] === 'unavailable')<p role="alert">活动正式基线暂不可读取，请重试或到正式提交中心核对。</p>
        @else<p class="nf-book-baseline">正式基线：尚未初始化；当前内容仍按候选或规划身份展示。</p>@endif
        {{-- 任务页收起附属入口，将主要空间留给当前操作；故事总入口默认展开。 --}}
        <details class="nf-book-navigation-drawer" @if($navigation['page'] === 'StoryHub') open @endif>
        <summary>本书创作入口 · {{ $navigation['active_group'] }}</summary>
        <nav class="nf-book-navigation" aria-label="当前小说创作导航">
            @foreach($navigation['groups'] as $label => $links)
                <details @if(collect($links)->contains('active', true) || ($navigation['page'] === 'StoryHub' && $label === '故事管理')) open @endif>
                    <summary>{{ $label }}</summary>
                    <div class="nf-book-links">@foreach($links as $link)<a href="{{ $link['url'] }}" @if($link['active']) aria-current="page" @endif>{{ $link['label'] }}</a>@endforeach</div>
                </details>
            @endforeach
        </nav>
        </details>
    @elseif(count($navigation['books']) === 0)
        <x-nf.state state="empty" title="还没有小说">先<a href="/admin/novels/create">创建第一本小说</a>，再进入故事规划与生产。</x-nf.state>
    @else<x-nf.state state="notice" title="请选择当前小说">选择后显示本书已有入口和实际正式基线，不自动采用其他标签页的小说。</x-nf.state>@endif
</x-nf.section>
