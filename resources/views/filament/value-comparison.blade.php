@php
    $leftRows=\App\Filament\Support\JsonFields::rows($leftValue);
    $rightRows=\App\Filament\Support\JsonFields::rows($rightValue);
@endphp
<x-nf.table :label="$comparisonLabel">
    <table><thead><tr><th>字段路径</th><th>旧值 / 类型</th><th>新值 / 类型</th><th>变化</th></tr></thead><tbody>
        @foreach(array_unique([...array_keys($leftRows),...array_keys($rightRows)]) as $field)
            @php($same=($leftRows[$field]??null)===($rightRows[$field]??null))
            <tr @class(['nf-value-changed'=>!$same])><th scope="row">{{ \App\Filament\Support\JsonFields::labelPath($field) }}<small class="nf-field-path">{{ $field }}</small></th>
                <td class="nf-config-text">{{ isset($leftRows[$field])?($leftRows[$field]['value']===''?'空文本':$leftRows[$field]['value']).' / '.$leftRows[$field]['type']:'未定义' }}</td>
                <td class="nf-config-text">{{ isset($rightRows[$field])?($rightRows[$field]['value']===''?'空文本':$rightRows[$field]['value']).' / '.$rightRows[$field]['type']:'未定义' }}</td>
                <td>{{ $same?'相同':'已变化' }}</td>
            </tr>
        @endforeach
    </tbody></table>
</x-nf.table>
