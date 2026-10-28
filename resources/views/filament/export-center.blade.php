<x-filament-panels::page class="nf-story-screen nf-production-screen" x-on:modal-closed.window="if($event.detail.id==='nf-export-editor' && $wire.editing)$wire.closeEditor()"><div class="nf-production-layout">
@include('filament.book-navigation',['navigation'=>$this->bookNavigation()])
@php($frame=$this->frame())
{{-- 默认只呈现持久任务列表；原生弹窗内逐字段设置，不展示可编辑JSON。 --}}
@if($frame['error'])<x-nf.state state="error" title="导出任务读取失败"><p>{{ $frame['error'] }}</p></x-nf.state>
@elseif($novel!=='')
@php($data=$frame['data'])
<p>导出冻结所选正文版本、目录和模板；后台新增章节不会混入原任务。文件私有保存30天，过期可按原清单重建，不会发布到平台。</p>
<div class="nf-config-actions"><x-filament::button id="nf-export-create" wire:click="begin">新建导出</x-filament::button><x-filament::button color="gray" wire:click="$refresh">刷新任务列表</x-filament::button><a href="{{ '/admin/formal-reader?novel='.$novel }}">打开本书阅读器</a></div>
@if($notice)<p role="status">{{ $notice }}</p>@endif
@if($errors->any() && !$editing)<x-nf.state state="error" title="操作未完成"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-nf.state>@endif
<div class="nf-story-toolbar">
<x-nf.story-select id="e08-formatFilter" label="格式" model="formatFilter" :value="$formatFilter"><option value="">全部</option><option value="txt">TXT</option><option value="md">Markdown</option></x-nf.story-select>
<x-nf.story-select id="e08-statusFilter" label="状态" model="statusFilter" :value="$statusFilter"><option value="">全部</option>@foreach(['queued'=>'排队中','building'=>'生成中','ready'=>'可下载','failed'=>'失败','expired'=>'已过期或删除'] as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-nf.story-select>
<x-nf.field id="exports-from" label="UTC开始日期"><x-nf.control id="exports-from" type="date" wire:model.live="from" /></x-nf.field>
<x-nf.field id="exports-to" label="UTC结束日期"><x-nf.control id="exports-to" type="date" wire:model.live="to" /></x-nf.field>
</div>
<x-nf.section heading="导出任务列表"><x-nf.table label="导出任务列表"><table><thead><tr><th>任务 / 基线 / 范围</th><th>格式 / 状态</th><th>大小 / 到期</th><th>操作</th></tr></thead><tbody>
@forelse($data['rows'] as $job)<tr><td>#{{ $job['id'] }} · {{ $job['scope']==='formal'?'正式 #'.$job['revision_id']:'草稿' }} · 第{{ $job['manifest']['from'] }}—{{ $job['manifest']['to'] }}章<p>{{ $job['created_at'] }}</p></td><td>{{ strtoupper($job['format']) }} / {{ ['queued'=>'排队中','building'=>'生成中','ready'=>'可下载','failed'=>'失败','expired'=>'已过期或删除'][$job['status']] }}<p>{{ $job['error_code'] }}</p></td><td>{{ $job['file']['byte_size']??'尚未生成' }} 字节<p>{{ $job['file']['expires_at']??'—' }}</p></td><td><div class="nf-config-actions">
<x-filament::button color="gray" wire:click="inspect('{{ $job['id'] }}')">查看清单</x-filament::button>
@if($job['status']==='ready')<a href="{{ '/api/v1/exports/'.$job['id'].'/download' }}">下载正文</a>@endif
<a href="{{ '/api/v1/exports/'.$job['id'].'/manifest' }}">下载清单</a>
<x-filament::button color="gray" wire:click="rebuild('{{ $job['id'] }}')" wire:loading.attr="disabled">按原清单重建</x-filament::button>
@if($job['file'] && !$job['file']['deleted_at'])<x-filament::button color="gray" wire:click="mountAction('deleteDerived',{export:'{{ $job['id'] }}'})">删除派生文件</x-filament::button>@endif
</div></td></tr>@empty<tr><td colspan="4">尚无导出任务。先选择已正式提交范围；显式草稿始终带草稿标记。</td></tr>@endforelse
</tbody></table></x-nf.table>
<div class="nf-story-toolbar"><span>共{{ $data['total'] }}项 · 第{{ $data['page'] }}页</span><x-filament::button color="gray" :disabled="$page<=1" wire:click="$set('page',{{ max(1,$page-1) }})">上一页</x-filament::button><x-filament::button color="gray" :disabled="$page*25 >= $data['total']" wire:click="$set('page',{{ $page+1 }})">下一页</x-filament::button></div>
</x-nf.section>
<x-nf.editor-modal id="nf-export-editor" heading="新建冻结版本导出">
@if($editing)<form id="nf-export-form" class="nf-config-editor" wire:submit="save" x-on:submit="const error=$el.querySelector('[role=alert]');if(error)error.focus({preventScroll:true});">
@if($errors->any())<x-nf.state state="error" title="导出未创建，输入仍保留" tabindex="-1" wire:key="export-errors-{{ md5(json_encode($errors->all())) }}" x-init="$nextTick(()=>requestAnimationFrame(()=>{const target=$el.closest('[role=dialog]')?.querySelector('[aria-invalid=true]')??$el;target.focus({preventScroll:true});target.scrollIntoView({block:'nearest'});} ))"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-nf.state>@endif
<x-nf.story-select id="e08-form-scope" label="正文身份" model="form.scope" :value="$form['scope']"><option value="formal">正式（默认）</option><option value="draft">显式草稿，非正式作品</option></x-nf.story-select>
<x-nf.story-select id="e08-form-revision_id" label="正式基线" model="form.revision_id" :value="$form['revision_id']"><option value="">当前活动基线（草稿留空）</option>@foreach($data['revisions'] as $revision)<option value="{{ $revision['id'] }}">#{{ $revision['id'] }} / v{{ $revision['version'] }} / 已提交{{ $revision['cursor'] }}章</option>@endforeach</x-nf.story-select>
<x-nf.story-select id="e08-form-range-type" label="导出范围" model="form.range.type" :value="$form['range']['type']">@foreach(['chapter'=>'单章','range'=>'连续范围','volume'=>'整卷','book'=>'全书（缺章阻塞）'] as $v=>$label)<option value="{{ $v }}">{{ $label }}</option>@endforeach</x-nf.story-select>
<div class="nf-story-fields">
@if(in_array($form['range']['type'],['chapter','range']))<x-nf.field id="export-start" label="起始章" :error="$errors->first('form.range.from')"><x-nf.control id="export-start" type="number" min="1" wire:model="form.range.from" /></x-nf.field>@endif
@if($form['range']['type']==='range')<x-nf.field id="export-end" label="结束章" :error="$errors->first('form.range.to')"><x-nf.control id="export-end" type="number" min="1" wire:model="form.range.to" /></x-nf.field>@endif
</div>
@if($form['range']['type']==='volume')<x-nf.story-select id="e08-form-range-volume_id" label="选择本书卷" model="form.range.volume_id" :value="$form['range']['volume_id']"><option value="">请选择</option>@foreach($data['volumes'] as $volume)<option value="{{ $volume['id'] }}">{{ $volume['title'] }}</option>@endforeach</x-nf.story-select>@endif
<x-nf.story-select id="e08-form-format" label="文件格式" model="form.format" :value="$form['format']"><option value="txt">TXT（UTF-8）</option><option value="md">Markdown（UTF-8）</option></x-nf.story-select>
<div class="nf-config-actions"><label class="nf-story-check"><input type="checkbox" wire:model="form.options.toc">包含目录</label><label class="nf-story-check"><input type="checkbox" wire:model="form.options.volume_titles">包含卷名</label></div>
<x-nf.field id="export-author" label="作者署名"><x-nf.control id="export-author" wire:model="form.options.author" /></x-nf.field>
<x-nf.field id="export-synopsis" label="简介"><x-nf.control id="export-synopsis" tag="textarea" wire:model="form.options.synopsis" /></x-nf.field>
<x-nf.field id="export-filename" label="文件名（危险字符自动清理）"><x-nf.control id="export-filename" wire:model="form.options.filename" /></x-nf.field>
<x-nf.story-select id="e08-form-options-separator" label="分隔规则" model="form.options.separator" :value="$form['options']['separator']"><option value="blank">空行</option><option value="rule">分隔线</option></x-nf.story-select>
@if($preview)<x-nf.state :state="$preview['missing']?'notice':'empty'" title="预检目录"><p>第{{ $preview['from'] }}—{{ $preview['to'] }}章；缺项：{{ $preview['missing']?implode('、',$preview['missing']):'无' }}。预检不创建任务，提交仍重新冻结。</p><ul>@foreach($preview['items'] as $item)<li>第{{ $item['sequence'] }}章 {{ $item['title'] }} · #{{ $item['chapter_version_id'] }}</li>@endforeach</ul></x-nf.state>@endif
<div class="nf-config-actions"><x-filament::button color="gray" wire:click="preflight">预检目录</x-filament::button><x-filament::button type="submit" wire:loading.attr="disabled">冻结并排队导出</x-filament::button><x-filament::button color="gray" wire:click="closeEditor">取消</x-filament::button></div>
</form>@endif
</x-nf.editor-modal>
<x-nf.editor-modal id="nf-export-detail" heading="冻结清单与文件证据（只读）">@if($selected)@include('filament.value-table',['value'=>$selected,'label'=>'冻结版本及校验清单'])@endif</x-nf.editor-modal>
@endif
<div wire:loading role="status">正在读取或保存，请稍候…</div>
</div></x-filament-panels::page>
