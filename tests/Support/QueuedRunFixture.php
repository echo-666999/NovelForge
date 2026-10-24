<?php

namespace Tests\Support;

use App\Models\Novel;
use App\Services\OutboxService;

/** 只创建无外部成本的两阶段队列图。 */
final class QueuedRunFixture
{
    public static function create(): array
    {
        $f = BlueprintFixture::create(chapterCount: 1, targetChapters: 10);
        $f['novelId'] = $f['book']['novel']['id'];
        $f['payload'] = ['outline_version_id' => $f['outline']['id'], 'expected_version' => Novel::find($f['novelId'])->row_version,
            'nodes' => [
                ['key' => 'first', 'stage' => 'test.echo', 'depends_on' => [], 'input' => ['text' => '前置结果']],
                ['key' => 'second', 'stage' => 'test.echo', 'depends_on' => ['first'], 'input' => ['text' => '后续结果']],
            ]];
        $f['runId'] = app(OutboxService::class)->startQueued($f['actor'], $f['novelId'], $f['payload'], 'queued')['run']['id'];

        return $f;
    }
}
