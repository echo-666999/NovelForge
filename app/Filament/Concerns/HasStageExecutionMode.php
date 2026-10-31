<?php

namespace App\Filament\Concerns;

use App\Generation\StageExecutionMode;

/** 后台明确选择模式；切换真实模式保留编辑内容，但不会把离线内容发送给模型。 */
trait HasStageExecutionMode
{
    public string $executionMode = 'offline_fake';

    protected function stagePayload(array $payload): array
    {
        $payload['execution_mode'] = StageExecutionMode::mode(['execution_mode' => $this->executionMode]);
        if ($this->executionMode === 'production') {
            unset($payload['offline_output']);
        }

        return $payload;
    }
}
