{{-- 模式与路由共同冻结；选择真实模式不等于能力和发布门禁已通过。 --}}
<x-nf.field id="stage-execution-mode" label="执行模式" :error="$errors->first('execution_mode')">
    <x-nf.control tag="select" id="stage-execution-mode" wire:model.live="executionMode">
        <option value="offline_fake">离线回放</option>
        <option value="production">真实模型</option>
    </x-nf.control>
</x-nf.field>
@if($executionMode === 'production')
    <p role="status">真实模式不使用离线响应。当前能力验证及真实结果持久化门禁尚未完成，启动仍会被阻断；选择模式不会发布配置或启用供应商。</p>
@endif
