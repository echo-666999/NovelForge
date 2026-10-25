@php
try { $context=app(\App\Services\WorkspaceService::class)->navigation(auth()->user(),$novelId); }
catch (\Illuminate\Database\QueryException $e) { report($e); $context=null; }
@endphp
@if($context)
<x-filament::section>
<p><strong>当前小说：{{ $context['title'] }}</strong> · #{{ $context['id'] }} @if($context['status']==='archived') · 已归档 @endif</p>
<p>正式基线：尚未接入</p>
<nav aria-label="当前小说创作导航" class="flex flex-wrap gap-3">
@foreach($context['links'] as $link)<a href="{{ $link['url'] }}" @if(request()->is(ltrim($link['url'],'/'))) aria-current="page" @endif>{{ $link['label'] }}</a>@endforeach
{{-- 账本只展示本书规划承诺，正式证据由后续提交链提供。 --}}
<a href="/admin/foreshadow-ledger?novel={{ $context['id'] }}">伏笔与主支线账本</a>
<a href="/admin/memory-library?novel={{ $context['id'] }}">分层记忆库</a>
<a href="/admin/search-lab?novel={{ $context['id'] }}">检索实验室</a>
<a href="/admin/novels">切换小说</a><a href="/admin">创作总览</a>
</nav>
</x-filament::section>

@else <p role="alert">当前小说信息读取失败，请重试。</p> @endif

{{-- E01 从要求、蓝图和层级规划共用入口查看离线候选与来源。 --}}
<a href="{{ \App\Filament\Resources\NovelResource::getUrl('planning-candidates', ['record' => $novelId]) }}">离线规划候选</a>

{{-- E02 候选正文独立于正式提交。 --}}
<a href="{{ \App\Filament\Resources\NovelResource::getUrl('draft-editor', ['record' => $novelId]) }}">候选正文</a>
