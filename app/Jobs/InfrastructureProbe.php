<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class InfrastructureProbe implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(public string $token)
    {
        $this->onQueue('maintenance');
    }

    public function handle(): void
    {
        Cache::put('novelforge-probe:'.$this->token, 'ok', 60);
    }
}
