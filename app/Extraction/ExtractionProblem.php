<?php

namespace App\Extraction;

use App\Exceptions\NovelProblem;

/** 结构失败携带声明位置供诊断，绝不将未经核验的片段标作有效证据。 */
final class ExtractionProblem extends NovelProblem
{
    public function __construct(string $code, public array $diagnostic)
    {
        parent::__construct($code, '提取结构或证据校验失败。', 422);
    }
}
