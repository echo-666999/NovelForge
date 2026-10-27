{{-- 仅摘要等已声明可承载结构的文本使用此预览；小说正文仍逐字保持原文。 --}}
@php
    $structured=false;$decoded=null;
    if(is_string($text)&&in_array(substr(ltrim($text),0,1),['{','['],true)){
        try{$decoded=json_decode($text,false,64,JSON_THROW_ON_ERROR);$structured=true;}catch(\JsonException){}
    }
@endphp
@if($structured)@include('filament.value-table',['value'=>$decoded,'label'=>$label??'结构化摘要只读'])
@else<div class="nf-manuscript" tabindex="0">{{ $text }}</div>@endif
