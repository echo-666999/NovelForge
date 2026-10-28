<x-filament-panels::page class="nf-story-screen nf-production-screen" x-on:modal-closed.window="if($event.detail.id==='nf-reading-issue' &amp;&amp; $wire.issueVersion!=='')$wire.closeIssue()"><div class="nf-production-layout">
@include('filament.book-navigation',['navigation'=>$this->bookNavigation()])
@php($frame=$this->frame())
{{-- 阅读正文始终由Blade转义；候选、正式与已读身份明确分开。 --}}
@if($frame['error'])<x-nf.state state="error" title="阅读资料不可用"><p>{{ $frame['error'] }}</p></x-nf.state>
@elseif($novel!=='')
@php($data=$frame['data'])
<div class="nf-story-toolbar">
<x-nf.story-select id="e08-scope" label="正文范围" model="scope" :value="$scope"><option value="formal">正式正文</option><option value="draft">显式草稿（非正式）</option></x-nf.story-select>
<x-nf.story-select id="reader-revision" label="阅读基线" model="revision" :value="$revision"><option value="">当前活动基线（草稿留空）</option>@foreach($data['revisions'] as $revisionRow)<option value="{{ $revisionRow['id'] }}">#{{ $revisionRow['id'] }} / v{{ $revisionRow['version'] }} / {{ $revisionRow['cursor'] }}章</option>@endforeach</x-nf.story-select>
<x-nf.field id="reader-chapter" label="起始章"><x-nf.control id="reader-chapter" type="number" min="1" wire:model.live="chapter" /></x-nf.field>
<x-nf.field id="reader-count" label="连续章数（最多20）"><x-nf.control id="reader-count" type="number" min="1" max="20" wire:model.live="count" /></x-nf.field>
</div>
<div class="nf-story-toolbar">
<x-nf.story-select id="e08-fontSize" label="字号" model="fontSize" :value="$fontSize">@foreach(['16','18','20','24'] as $v)<option value="{{ $v }}">{{ $v }}px</option>@endforeach</x-nf.story-select>
<x-nf.story-select id="e08-lineHeight" label="行距" model="lineHeight" :value="$lineHeight">@foreach(['1.6','1.8','2.0'] as $v)<option value="{{ $v }}">{{ $v }}</option>@endforeach</x-nf.story-select>
<x-nf.story-select id="e08-filter" label="阅读筛选" model="filter" :value="$filter"><option value="all">全部</option><option value="unread">本版本未读</option><option value="issues">本版本有问题</option></x-nf.story-select>
<x-filament::button color="gray" wire:click="turn(-1)" :disabled="$chapter<=1">上一页章节</x-filament::button><x-filament::button color="gray" wire:click="turn(1)">下一页章节</x-filament::button>
<a href="{{ '/admin/export-center?novel='.$novel }}">导出本书</a>
</div>
<p>当前为{{ $data['scope']==='formal'?'正式基线 #'.($data['revision_id']??'未初始化'):'草稿（不代表正式提交）' }}，第{{ $data['from'] }}至{{ $data['to'] }}章。标记已读不等于作品验收通过。</p>
@if($notice)<p role="status">{{ $notice }}</p>@endif
@if($errors->any() && $issueVersion==='')<x-nf.state state="error" title="操作未完成"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-nf.state>@endif
@if($data['missing'])<x-nf.state state="notice" title="所选范围缺少正文"><p>第{{ implode('、',$data['missing']) }}章缺少所选范围的精确版本；未回退其他基线或候选。</p></x-nf.state>@endif
<nav aria-label="本页章节目录"><ul>@foreach($data['items'] as $item)<li><a href="#read-chapter-{{ $item['chapter_version_id'] }}">第{{ $item['sequence'] }}章 {{ $item['title'] }} · {{ $item['read']?'已读':'未读' }} · {{ count($item['issues']) }}个问题</a></li>@endforeach</ul></nav>
@foreach($data['items'] as $item)
@if(($filter==='all') || ($filter==='unread' && !$item['read']) || ($filter==='issues' && count($item['issues'])))
<x-nf.section :heading="'第'.$item['sequence'].'章 '.$item['title']">
<article id="read-chapter-{{ $item['chapter_version_id'] }}" aria-label="第{{ $item['sequence'] }}章正文">
<p>{{ $item['volume_title'] }} · 版本 #{{ $item['chapter_version_id'] }} · {{ $item['character_count'] }}字 · SHA256 {{ $item['body_hash'] }}</p>
<div style="white-space:pre-wrap;overflow-wrap:anywhere;max-width:42em;margin-inline:auto;font-size:{{ $fontSize }}px;line-height:{{ $lineHeight }}">{{ $item['body'] }}</div>
</article>
<div class="nf-config-actions"><x-filament::button color="gray" wire:click="observe('{{ $item['chapter_version_id'] }}','read')">标记本版本已读</x-filament::button><x-filament::button color="gray" wire:click="beginIssue('{{ $item['chapter_version_id'] }}')">报告阅读问题</x-filament::button><a href="{{ '/admin/novels/'.$novel.'/draft-editor?chapter='.$item['chapter_id'] }}">进入候选编辑与修订</a></div>
<div class="nf-story-toolbar"><x-nf.story-select id="e08-otherVersion-{{ $item['chapter_version_id'] }}" :label="'比较版本（第'.$item['sequence'].'章）'" model="otherVersion" :value="$otherVersion"><option value="">选择同章版本</option>@foreach($item['versions'] as $version)<option value="{{ $version['id'] }}">v{{ $version['version'] }} / #{{ $version['id'] }} {{ $version['title'] }}</option>@endforeach</x-nf.story-select><x-filament::button color="gray" wire:click="compare('{{ $item['chapter_version_id'] }}')">查看版本差异</x-filament::button></div>
@foreach($item['issues'] as $issue)<blockquote>“{{ $issue['quote'] }}” [{{ $issue['start_offset'] }},{{ $issue['end_offset'] }})：{{ $issue['note'] }}</blockquote>@endforeach
</x-nf.section>
@endif
@endforeach
@endif
<x-nf.editor-modal id="nf-reading-issue" heading="记录人工阅读问题">
<form wire:submit="observe('{{ $issueVersion }}','issue')" class="nf-config-editor" x-on:submit="const error=$el.querySelector('[role=alert]');if(error)error.focus({preventScroll:true});">
@if($errors->any())<x-nf.state state="error" title="问题未保存，输入仍保留" tabindex="-1" wire:key="reading-errors-{{ md5(json_encode($errors->all())) }}" x-init="$nextTick(()=>requestAnimationFrame(()=>{const target=$el.closest('[role=dialog]')?.querySelector('[aria-invalid=true]')??$el;target.focus({preventScroll:true});target.scrollIntoView({block:'nearest'});} ))"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-nf.state>@endif
<x-nf.field id="reading-quote" label="精确引文" :error="$errors->first('quote')"><x-nf.control id="reading-quote" tag="textarea" wire:model="quote" /></x-nf.field>
<x-nf.field id="reading-offset" label="引文起点（Unicode码点，从0开始）" :error="$errors->first('start_offset')"><x-nf.control id="reading-offset" type="number" min="0" wire:model="offset" /></x-nf.field>
<x-nf.field id="reading-note" label="问题说明" :error="$errors->first('note')"><x-nf.control id="reading-note" tag="textarea" wire:model="note" /></x-nf.field>
<p>例：甲😀乙中的😀范围为[1,2)。不按字节或浏览器UTF-16长度计算。</p>
<div class="nf-config-actions"><x-filament::button type="submit" wire:loading.attr="disabled">保存阅读问题</x-filament::button></div>
</form>
</x-nf.editor-modal>
<x-nf.editor-modal id="nf-reading-diff" heading="版本差异（保守行区间比较）">
@if($comparison)<p>{{ $comparison['left_title'] }} → {{ $comparison['right_title'] }}；{{ $comparison['changed']?'存在修改':'无修改' }}。差异不改变正式指针；可关闭返回完整正文。</p>{{-- 长篇差异优先展示变化，公共上下文按需展开，仍保留完整原文。 --}}<details><summary>展开未变化的前文</summary><pre style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $comparison['prefix'] }}</pre></details><h3>删除区间</h3><del style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $comparison['removed'] }}</del><h3>新增区间</h3><ins style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $comparison['added'] }}</ins><details><summary>展开未变化的后文</summary><pre style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $comparison['suffix'] }}</pre></details>@endif
</x-nf.editor-modal>
<div wire:loading role="status">正在读取或保存，请稍候…</div>
</div></x-filament-panels::page>
