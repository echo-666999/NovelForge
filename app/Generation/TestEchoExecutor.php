<?php

namespace App\Generation;

/** 队列集成专用的确定性阶段；生产环境禁止解析，不包含外部请求。 */
final class TestEchoExecutor implements StageExecutor
{
    public function execute(array $input): StageResult
    {
        if (! app()->environment('testing')) {
            throw new \LogicException('测试阶段不可在生产环境执行。');
        }

        return new StageResult('succeeded', ['text' => $input['node']['input']['text']]);
    }
}
