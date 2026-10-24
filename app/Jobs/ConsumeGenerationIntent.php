<?php

namespace App\Jobs;

use App\Services\OutboxService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** 消息只包含持久意图标识，重复投递由数据库回执去重。 */
final class ConsumeGenerationIntent implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public string $eventId) {}

    public function handle(OutboxService $outbox): void
    {
        $outbox->consume($this->eventId);
    }
}
