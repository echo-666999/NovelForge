{{-- 在DOM更新后的绘制帧定位，避免焦点落在即将被替换的旧控件。只处理发送事件的Livewire组件；展开已有折叠祖先后再定位，不移动到其他表单。 --}}
<div hidden x-data x-on:nf-validation-failed.window="$nextTick(() => requestAnimationFrame(() => {
    const root = Array.from(document.querySelectorAll('[wire\\:id]')).find(el => el.getAttribute('wire:id') === $event.detail.sourceId);
    if (!root) return;
    const target = Array.from(root.querySelectorAll('[aria-invalid=true]')).find(el => !el.disabled &amp;&amp; el.closest('[wire\\:id]') === root) ?? Array.from(root.querySelectorAll('[role=alert]')).find(el => el.closest('[wire\\:id]') === root);
    if (!target) return;
    for (let parent = target.parentElement; parent &amp;&amp; parent !== root; parent = parent.parentElement) {
        if (parent.tagName === 'DETAILS') parent.open = true;
    }
    if (!target.hasAttribute('tabindex') &amp;&amp; target.matches('[role=alert]')) target.setAttribute('tabindex', '-1');
    target.focus({preventScroll: true});
    target.scrollIntoView({block: 'center', behavior: 'auto'});
}))"></div>
