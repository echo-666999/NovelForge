{{-- 来源、类型和内容逐字段只读；不依赖原始JSON获得信息。 --}}
<x-nf.table label="{{ $label ?? '结构化内容' }}">
    <table><thead><tr><th>字段路径</th><th>类型</th><th>内容</th></tr></thead><tbody>
        @foreach(\App\Filament\Support\JsonFields::rows($value) as $field=>$row)
            <tr><th scope="row">{{ \App\Filament\Support\JsonFields::labelPath($field) }}<small class="nf-field-path">{{ $field }}</small></th><td>{{ $row['type'] }}</td><td class="nf-config-text">{{ $row['value'] === '' ? '空文本' : $row['value'] }}</td></tr>
        @endforeach
    </tbody></table>
</x-nf.table>
