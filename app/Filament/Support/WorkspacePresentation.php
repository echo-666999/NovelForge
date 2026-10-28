<?php

namespace App\Filament\Support;

/** UI14只翻译已知运行/评审状态，不将未知值解释为成功。 */
final class WorkspacePresentation
{
    public static function runStatus(string $value): string
    {
        return ['queued' => '排队中', 'running' => '运行中', 'succeeded' => '已完成', 'failed' => '失败', 'blocked' => '已阻塞', 'deferred' => '已延后', 'paused' => '已暂停', 'canceled' => '已取消', 'needs_attention' => '需要人工处理'][$value] ?? '未知状态';
    }

    public static function verdict(string $value): string
    {
        return ['pass' => '通过', 'fail' => '未通过', 'revision_required' => '需要修订', 'manual_required' => '需要人工处理'][$value] ?? '未知结论';
    }
}
