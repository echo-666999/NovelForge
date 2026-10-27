# NF-UI19 保持性检查源码

本轮实际使用源码如下；仅做源码解析，不启动应用或连接数据库。按原顺序解析类名，不重新排序数组或语句；仅将新增入口错误边界与原复现主体分开比较。路径为本轮工作区位置，原版逐字节存档另附，存档不能在错误目录直接运行。

```php
<?php
// 仅错误处理可变：bootstrap流程、守卫条件、合法复现与finally必须保持原AST。
require '/Users/webb/Herd/NovelForge/vendor/autoload.php';
function parseProgram(string $source): array
{
    $nodes = (new PhpParser\ParserFactory)->createForNewestSupportedVersion()->parse($source);
    $traverser = new PhpParser\NodeTraverser;
    $traverser->addVisitor(new PhpParser\NodeVisitor\NameResolver);
    return array_values(array_filter($traverser->traverse($nodes), static fn ($n) => !$n instanceof PhpParser\Node\Stmt\Use_));
}
function canonical(mixed $value): mixed
{
    if ($value instanceof PhpParser\Node) {
        $data = ['node_type'=>$value->getType()];
        foreach ($value->getSubNodeNames() as $key) $data[$key] = canonical($value->$key);
        return $data;
    }
    return is_array($value) ? array_map('canonical', $value) : $value;
}
$old = parseProgram(file_get_contents(__DIR__.'/before-repro.php'));
$source = file_get_contents('/Users/webb/Herd/NovelForge/docs/ui/NF-UI16/scripts/before-repro.php');
$new = parseProgram($source);
if (count($new)!==1 || !$new[0] instanceof PhpParser\Node\Stmt\TryCatch) throw new RuntimeException('缺少统一入口异常边界。');
$body = $new[0]->stmts;
$index = array_search(true,array_map(static fn ($n) => $n instanceof PhpParser\Node\Stmt\If_, $old),true);
if ($index===false || !$body[$index] instanceof PhpParser\Node\Stmt\If_) throw new RuntimeException('未定位原守卫。');
$checks = [
    'bootstrap_sequence_unchanged' => canonical(array_slice($old,0,$index))===canonical(array_slice($body,0,$index)),
    'guard_condition_unchanged' => canonical($old[$index]->cond)===canonical($body[$index]->cond),
    'legal_reproduction_and_cleanup_unchanged' => canonical(array_slice($old,$index+1))===canonical(array_slice($body,$index+1)),
];
if (in_array(false,$checks,true)) throw new RuntimeException('变更超出文档入口错误处理范围。');
echo json_encode($checks+['core_ast_sha256'=>hash('sha256',serialize(canonical(array_slice($old,$index+1)))),'after_sha256'=>hash('sha256',$source),'method'=>'按原顺序解析名称，保留语句/数组顺序，仅忽略节点位置和注释属性；分别比较bootstrap、守卫条件和守卫之后全部合法复现语句'],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
```
