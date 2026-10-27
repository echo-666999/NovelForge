{{-- 产物预览只能用于核对；截断不能伪装完整结构，下载仍走原授权接口。 --}}
@if($artifact['truncated'])<p>预览已截断，请下载完整受控产物核对；这里不显示残缺JSON。</p>
@else
@php
    $structured=true;
    try {$preview=json_decode($artifact['preview'],false,64,JSON_THROW_ON_ERROR);}catch(\JsonException){$structured=false;$preview=$artifact['preview'];}
@endphp
@if($structured)@include('filament.value-table',['value'=>$preview,'label'=>'产物逐字段预览'])
@else<div class="nf-manuscript" tabindex="0">{{ $preview }}</div>@endif
@endif
