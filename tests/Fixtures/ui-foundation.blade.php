<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>NF-UI02 公共组件验收样本</title>
@filamentStyles
{{-- CSS 来自本机已构建产物，样本没有业务数据、保存动作或外部模型请求。 --}}
<style>{!! $css !!}</style></head>
<body class="fi-body fi-panel-admin"><main class="fi-page-content" style="max-width:1120px;margin:auto;padding:24px">
<x-nf.state state="notice" title="NF-UI02 公共组件验收样本">以下均为界面样本，不连接后台写接口。<x-slot:actions><button id="toggle-theme" type="button">切换样本明暗</button></x-slot:actions></x-nf.state>
<x-nf.section heading="字段与控件" description="复用 Filament 的原生字段容器与输入组件。">
<x-nf.form-grid>
<x-nf.input id="normal" label="普通字段" value="可输入文本" help="说明紧贴字段，不占据主要操作区。" :required="true" />
<x-nf.input id="invalid" label="错误字段" error="请输入操作原因。" />
<x-nf.input id="readonly" label="只读版本" value="示例 v1（非正式记录）" :readonly="true" />
<x-nf.input id="disabled" label="未接入动作" value="暂不可用" :disabled="true" />
<x-nf.field id="select" label="枚举字段"><x-filament::input.wrapper><x-filament::input.select id="select"><option>候选</option><option>正式（示例）</option></x-filament::input.select></x-filament::input.wrapper></x-nf.field>
<x-nf.field id="long" label="长文本字段"><textarea id="long" rows="3">长中文与证据来源应当换行，保持正常阅读宽度。</textarea></x-nf.field>
</x-nf.form-grid>
</x-nf.section>
<x-nf.section heading="状态与表格">
<x-nf.form-grid><x-nf.state state="loading" title="正在读取">请等待，尚未得到结果。</x-nf.state><x-nf.state state="empty" title="尚无数据">这是空态，不代表读取失败。</x-nf.state><x-nf.state state="error" title="读取失败">保留已有输入；安全重试由页面提供。</x-nf.state><x-nf.state state="success" title="操作已完成">成功文案不能暗示候选已成为正式状态。</x-nf.state></x-nf.form-grid>
<x-nf.table label="组件样本明细"><table><caption>只读示例表格</caption><thead><tr><th>来源</th><th>说明</th><th>状态</th></tr></thead><tbody><tr><td>公共组件</td><td>无需使用数据库 ID 生成样本</td><td>界面验收</td></tr></tbody></table></x-nf.table>
</x-nf.section>
</main><script>
// 仅切换独立样本的样式，不写入后台或浏览器持久偏好。
document.querySelector('#toggle-theme').addEventListener('click',()=>document.documentElement.classList.toggle('dark'));
</script></body></html>
