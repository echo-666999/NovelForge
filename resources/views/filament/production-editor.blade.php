@php($editor=$this->productionEditor($property))
<div class="nf-production-editor" wire:key="production-{{ $property }}">
    @include('filament.production-field', ['node'=>$editor['node'],'path'=>$editor['path'],'schema'=>$editor['schema'],'label'=>$label,'fieldKey'=>''])
    <details wire:ignore.self><summary>高级导入（可选，不代替逐字段编辑）</summary>
        <x-nf.field id="import-{{ $property }}" label="导入完整响应" :error="$errors->first('productionImports.'.$property)"><x-nf.control tag="textarea" id="import-{{ $property }}" rows="6" wire:model="productionImports.{{ $property }}"></x-nf.control></x-nf.field>
        <x-filament::button color="gray" wire:click="importProductionPayload('{{ $property }}')">校验后载入字段</x-filament::button>
    </details>
</div>
