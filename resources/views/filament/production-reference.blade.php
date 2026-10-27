@php($choices=$this->productionChoices($kind))
@if($multiple??false)
<fieldset class="nf-story-multiple"><legend>{{ $label }}</legend><p class="nf-field-help">逐项选取实际记录，保留选择顺序。</p>
@forelse($choices as $id=>$text)<label class="nf-story-check"><input type="checkbox" wire:change="toggleProductionReference('{{ $model }}','{{ $id }}')" @checked(in_array((string)$id,array_filter(array_map('trim',explode(',',$this->$model))),true))>{{ $text }}</label>@empty<p>暂无符合当前范围的记录。</p>@endforelse
</fieldset>
@else
<x-nf.select :id="'prod-ref-'.str_replace('.','-',$model)" :label="$label" :model="$model" :value="data_get($__livewire,$model)" :options="[''=>'请选择实际记录']+$choices" :error="$errors->first($model)" />
@endif
