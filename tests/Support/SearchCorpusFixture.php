<?php

namespace Tests\Support;

use App\Generation\StageResult;
use App\Models\Novel;
use App\Services\GenerationService;
use App\Services\LayeredMemoryService;
use App\Services\StoryConsistencyService;
use App\Services\StoryStateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** 固定语料映射至真实候选产物；旧revision仅映射为另一分支，不伪造Canonical。 */
final class SearchCorpusFixture
{
    public static function create(): array
    {
        Http::preventStrayRequests();
        $corpus = array_map(fn ($line) => json_decode($line, true), file(base_path('docs/phase-a/retrieval_corpus.jsonl'), FILE_IGNORE_NEW_LINES));
        // 增加与标注答案无关的20条干扰文档，避免仅20个可检索来源令Top20退化为全集。
        for ($i = 1; $i <= 20; $i++) {
            $corpus[] = ['source_id' => 'D'.$i, 'novel_id' => 'fixture-book-a', 'revision_id' => 'fixture-rev-1', 'introduced_chapter' => 1, 'visible_to' => ['pov-shen'], 'source_status' => 'active', 'title' => '菜谱记录'.$i, 'aliases' => [], 'body' => '菜谱记录'.$i.'：厨房清洗青菜，蒸熟米饭，分装食盒，登记配送数量。'];
        }
        $f = BlueprintFixture::create(chapterCount: 1, targetChapters: 100);
        $f['novelId'] = $f['book']['novel']['id'];
        $f['profile'] = SearchFixture::command($f, 'profile', ['name' => '固定语料离线档案', 'encoder' => 'offline_hash_v1', 'dimensions' => 256, 'distance_metric' => 'cosine'])['profile_id'];
        $other = BlueprintFixture::create(actor: $f['actor'], chapterCount: 1, targetChapters: 100);
        $other['novelId'] = $other['book']['novel']['id'];
        $other['profile'] = $f['profile'];
        $map = [];
        $main = null;
        $entries = [];
        $knowledge = [];
        $invalid = [];
        foreach (['main', 'old', 'other'] as $group) {
            $g = $group === 'other' ? $other : $f;
            $rows = array_values(array_filter($corpus, fn ($r) => ($r['novel_id'] === 'fixture-book-b' ? 'other' : ($r['revision_id'] === 'fixture-rev-0' ? 'old' : 'main')) === $group));
            $nodes = [];
            foreach ($rows as $i => $row) {
                $nodes[] = ['key' => 's'.$i, 'stage' => 'test.echo', 'depends_on' => [], 'input' => ['text' => $row['body']]];
            }
            $g['branch'] = app(GenerationService::class)->start($g['actor'], $g['novelId'], ['expected_version' => Novel::find($g['novelId'])->row_version, 'outline_version_id' => $g['outline']['id'], 'nodes' => $nodes], (string) Str::uuid())['run']['id'];
            $g['build'] = SearchFixture::command($g, 'build', ['branch_id' => $g['branch'], 'profile_id' => $g['profile']])['build_id'];
            foreach ($rows as $i => $row) {
                $claim = app(GenerationService::class)->claim($g['actor'], $g['novelId'], $g['branch'], 's'.$i);
                app(GenerationService::class)->complete($g['actor'], $g['novelId'], $g['branch'], $claim, new StageResult('succeeded', ['text' => $row['body']]));
                $s = app(StoryStateService::class);
                $source = $s->registerArtifact($g['actor'], $g['novelId'], (string) DB::table('generation_artifacts')->where('step_id', $claim['id'])->value('id'));
                $evidence = $s->evidence($g['actor'], $g['novelId'], $source, hash('sha256', $row['body']), 0, mb_strlen($row['body']), $row['body']);
                $event = $s->propose($g['actor'], $g['novelId'], ['evidence_id' => $evidence, 'definition_version_id' => $g['person']['definition']['id'], 'fact_kind' => 'fact', 'action' => 'set', 'chapter_sequence' => $row['introduced_chapter'], 'narrative_order' => $i + 1, 'attribute' => 'corpus_'.$i, 'value' => $row['body'], 'confidence' => 'confirmed', 'story_time' => [], 'reason' => '离线语料事实']);
                SearchFixture::command($g, 'document', ['build_id' => $g['build'], 'source_version_id' => $source, 'evidence_id' => $evidence, 'event_id' => $event, 'title' => $row['title'], 'aliases' => $row['aliases'], 'introduced_chapter' => $row['introduced_chapter']]);
                $map[$row['source_id']] = $source;
                if ($group === 'main') {
                    $entries[] = StoryConsistencyFixture::entry($event, $i);
                    if (in_array('pov-shen', $row['visible_to'], true)) {
                        $knowledge[] = ['knower_id' => $f['person']['entity']['id'], 'fact_event_id' => $event, 'acquired_event_id' => $event, 'method' => 'witness'];
                    }
                    if ($row['source_status'] === 'stale') {
                        $invalid[] = $source;
                    }
                }
            }
            SearchFixture::activate($g);
            if ($group === 'main') {
                $main = $g;
            }
        }
        $context = app(StoryConsistencyService::class)->save($f['actor'], $f['novelId'], ['expected_version' => 0, 'reason' => '固定语料知识链', 'entries' => $entries, 'knowledge' => $knowledge], (string) Str::uuid());
        foreach ($invalid as $source) {
            app(LayeredMemoryService::class)->execute($f['actor'], $f['novelId'], 'invalidate', ['branch_id' => $main['branch'], 'source_version_id' => $source, 'expected_version' => Novel::find($f['novelId'])->row_version, 'reason' => '固定语料来源失效'], (string) Str::uuid());
        }

        return $main + ['context' => $context, 'sourceMap' => $map, 'corpus' => $corpus];
    }
}
