# NF-UI18 实际AST检查方法

以下为本轮临时工作区实际使用的检查源码，仅供审计。输入/自动加载路径对应本机验证位置；复现时需保持原/新脚本输入，不能直接改变脚本根目录层级运行。AST检查本身不启动应用内核或连接数据库。代码以Markdown保存，避免再引入未受检的PHP文档脚本。

```php
<?php
// 按原始节点顺序解析名称，禁止提前登记导入；再比较可执行AST，不改语句/数组顺序。
require '/Users/webb/Herd/NovelForge/vendor/autoload.php';
function program(string $source): array
{
    $parser = (new PhpParser\ParserFactory)->createForNewestSupportedVersion();
    $nodes = $parser->parse($source);
    foreach ($nodes as $node) {
        if ($node instanceof PhpParser\Node\Stmt\Namespace_) {
            throw new RuntimeException('本检查仅适用于当前全局脚本。');
        }
    }
    $traverser = new PhpParser\NodeTraverser;
    $traverser->addVisitor(new PhpParser\NodeVisitor\NameResolver);
    $nodes = $traverser->traverse($nodes);
    return array_values(array_filter($nodes, static fn ($n) => !$n instanceof PhpParser\Node\Stmt\Use_ && !$n instanceof PhpParser\Node\Stmt\GroupUse));
}
function normalize(mixed $value): mixed
{
    if ($value instanceof PhpParser\Node) {
        $data = ['node_type' => $value->getType()];
        foreach ($value->getSubNodeNames() as $key) {
            $data[$key] = normalize($value->$key);
        }
        return $data;
    }
    return is_array($value) ? array_map('normalize', $value) : $value;
}
$before = file_get_contents(__DIR__.'/before-repro.php');
$after = file_get_contents('/Users/webb/Herd/NovelForge/docs/ui/NF-UI16/scripts/before-repro.php');
$a = normalize(program($before));
$b = normalize(program($after));
if ($a !== $b) throw new RuntimeException('解析完整名称后可执行AST有差异。');
// 反例：把短名的导入移到调用后，检查器必须拒绝，不能再次因预处理而误判。
$imports = [];
$bad = preg_replace_callback('/^use [^;]+;\n/m', function ($match) use (&$imports) { $imports[] = trim($match[0]); return ''; }, $after);
$call = '$app->make(Kernel::class)->bootstrap();';
$bad = str_replace($call, $call."\n".implode("\n", $imports), $bad);
if (normalize(program($bad)) === $a) throw new RuntimeException('检查器未识别导入晚于调用的反例。');
$comments = static fn ($s) => array_values(array_map(static fn ($t) => $t[1], array_filter(token_get_all($s), static fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT,T_DOC_COMMENT], true))));
if ($comments($before) !== $comments($after)) throw new RuntimeException('原有注释发生变化。');
echo json_encode(['before_sha256'=>hash('sha256',$before),'after_sha256'=>hash('sha256',$after),'normalized_ast_equal'=>true,'late_import_negative_control_detected'=>true,'ast_sha256'=>hash('sha256',serialize($a)),'statement_order_preserved'=>true,'array_order_preserved'=>true,'comments_unchanged'=>true,'method'=>'按原始节点顺序解析名称，再去除use节点和节点位置属性；不提前登记导入，不排序语句或数组'],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
```
