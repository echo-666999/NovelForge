{{-- 来源、类型和内容逐字段只读；不依赖原始JSON获得信息。 --}}
<x-nf.table label="{{ $label ?? '结构化内容' }}">
    <table><thead><tr><th>{{ ($billingLabels ?? false) ? '字段名称' : '字段路径' }}</th><th>类型</th><th>内容</th></tr></thead><tbody>
        @foreach(\App\Filament\Support\JsonFields::rows($value) as $field=>$row)
            <tr><th scope="row">
                @if($billingLabels ?? false)
                    {{-- 账本默认使用中文业务名称，原始路径折叠保留供核查未知扩展。 --}}
                    {{ \App\Filament\Support\BillingDetailLabels::label($field) }}
                    <details class="nf-field-path"><summary>技术路径</summary>{{ $field }}</details>
                @else
                    {{ \App\Filament\Support\JsonFields::labelPath($field) }}<small class="nf-field-path">{{ $field }}</small>
                @endif
            </th><td>{{ $row['type'] }}</td><td class="nf-config-text">{{ $row['value'] === '' ? '空文本' : $row['value'] }}</td></tr>
        @endforeach
    </tbody></table>
</x-nf.table>
