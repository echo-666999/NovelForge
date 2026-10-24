<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/** 配置载入前的隔离拒绝不交给依赖 config 的日志器；命令行仍输出原因并失败退出。 */
final class TestIsolationBootstrapException extends RuntimeException implements ShouldntReport
{
    // 仅用于早期门禁，不抑制配置载入后的数据库、资源隔离或普通应用异常。
}
