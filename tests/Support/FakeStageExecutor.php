<?php

namespace Tests\Support;

use App\Generation\StageExecutor;
use App\Generation\StageResult;

/** 纯内存确定性 Fake Provider：不请求网络，不产生模型费用。 */
final class FakeStageExecutor implements StageExecutor
{
    public int $calls = 0;

    public array $inputs = [];

    public function __construct(private string $status = 'succeeded') {}

    public function execute(array $input): StageResult
    {
        $this->calls++;
        $this->inputs[] = $input;

        return new StageResult($this->status, ['text' => $input['node']['input']['text']], $this->status === 'succeeded' ? null : 'TEST_'.strtoupper($this->status));
    }
}
