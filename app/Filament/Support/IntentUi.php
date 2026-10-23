<?php

namespace App\Filament\Support;

use App\Exceptions\NovelProblem;
use App\Services\NovelIntentService;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Str;

final class IntentUi
{
    public static function run(string $command, ?string $id, array $data, ?string $key = null): array
    {
        try {
            return app(NovelIntentService::class)->execute(auth()->user(), $command, $id, $data, $key ?? (string) Str::uuid());
        } catch (NovelProblem $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            throw new Halt;
        }
    }
}
