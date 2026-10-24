<?php

namespace Tests\Support;

use App\Generation\StageResult;
use App\Models\Novel;
use App\Services\GenerationService;
use App\Services\LayeredMemoryService;
use App\Services\OutboxService;
use App\Services\StoryStateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** 真实离线产物与证据，不建立假正式提交或调用供应商。 */
final class MemoryFixture
{
    public static function create(): array
    {
        Http::preventStrayRequests();
        $f = QueuedRunFixture::create();
        $f['branch'] = $f['runId'];
        $f['statements'] = ['许砚失去右手。', '铜钥匙证明调包。', '同事目击封记。', '旧案仍未解决。'];
        $f['text'] = implode('', $f['statements']).'𠮷🙂';
        $f['sources'] = [];
        $f['evidence'] = [];
        $g = app(GenerationService::class);
        $s = app(StoryStateService::class);
        foreach (['first', 'second'] as $step) {
            $claim = $g->claim($f['actor'], $f['novelId'], $f['branch'], $step);
            $g->complete($f['actor'], $f['novelId'], $f['branch'], $claim, new StageResult('succeeded', ['text' => $f['text']]));
            $source = $s->registerArtifact($f['actor'], $f['novelId'], (string) DB::table('generation_artifacts')->where('step_id', $claim['id'])->value('id'));
            $f['sources'][] = $source;
            $f['evidence'][] = $s->evidence($f['actor'], $f['novelId'], $source, hash('sha256', $f['text']), 0, mb_strlen($f['text']), $f['text']);
        }
        $f['facts'] = [];
        foreach (['irreversible', 'causal', 'knowledge', 'open_issue'] as $i => $category) {
            $f['facts'][] = self::command($f, 'fact', ['source_version_id' => $f['sources'][0], 'evidence_id' => $f['evidence'][0], 'category' => $category, 'statement' => $f['statements'][$i], 'introduced_chapter' => 2])['fact']['id'];
        }
        $f['memory'] = ['kind' => 'episodic', 'name' => '旧案调查记忆', 'summary' => $f['text'], 'introduced_chapter' => 2, 'visibility' => ['mode' => 'author_only', 'character_ids' => []], 'importance' => 5, 'source_ids' => [$f['sources'][0]], 'fact_ids' => $f['facts'], 'parent_ids' => [], 'chunk_size' => 32];

        return $f;
    }

    public static function command(array $f, string $command, array $data, ?string $key = null): array
    {
        return app(LayeredMemoryService::class)->execute($f['actor'], $f['novelId'], $command, $data + ['branch_id' => $f['branch'], 'expected_version' => Novel::find($f['novelId'])->row_version, 'reason' => '离线记忆验收'], $key ?? (string) Str::uuid());
    }

    public static function secondBranch(array $f): string
    {
        $p = $f['payload'];
        $p['expected_version'] = Novel::find($f['novelId'])->row_version;

        return app(OutboxService::class)->startQueued($f['actor'], $f['novelId'], $p, (string) Str::uuid())['run']['id'];
    }
}
