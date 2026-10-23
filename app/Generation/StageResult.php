<?php

namespace App\Generation;

/** 类型化测试阶段结果；未来生产阶段须使用各自版本化输出契约。 */
final readonly class StageResult
{
    public function __construct(public string $status, public array $content, public ?string $errorCode = null) {}
}
