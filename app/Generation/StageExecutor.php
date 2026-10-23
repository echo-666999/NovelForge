<?php

namespace App\Generation;

/** 阶段执行边界：输入已经冻结；实现不得自行修改运行记录。 */
interface StageExecutor
{
    public function execute(array $input): StageResult;
}
