<?php

namespace Tests\Feature;

use App\Services\SearchEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\Support\SearchCorpusFixture;
use Tests\TestCase;

/** 固定100查询经生产检索服务评测；不把合成语料结果冒充真实模型或正式基线验收。 */
class SearchCorpusTest extends TestCase
{
    use RefreshDatabase;

    // 大型报告与完整HTTP响应在独立进程释放，避免挤占后续Livewire回归的128MB预算。
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_frozen_hundred_queries_recall_and_zero_leaks_with_distractors(): void
    {
        $f = SearchCorpusFixture::create();
        $cases = [];
        foreach (file(base_path('docs/phase-a/retrieval_queries.jsonl'), FILE_IGNORE_NEW_LINES) as $line) {
            $q = json_decode($line, true);
            $cases[] = ['query_id' => $q['query_id'], 'category' => $q['category'], 'filters' => ['query' => $q['query'], 'chapter' => $q['chapter_cutoff'], 'audience' => 'pov', 'pov_id' => $f['person']['entity']['id'], 'context_id' => $f['context']['id']], 'relevant_source_ids' => array_map(fn ($v) => $f['sourceMap'][$v['source_id']], $q['relevant']), 'forbidden_source_ids' => array_map(fn ($v) => $f['sourceMap'][$v], $q['forbidden_source_ids'])];
        }
        $r = app(SearchEvaluationService::class)->evaluate($f['actor'], $f['novelId'], $f['build'], $cases, hash_file('sha256', base_path('docs/phase-a/retrieval_corpus.jsonl')));
        // 可选导出仅限测试存储目录，正式文档由本轮验证后复制。
        $path = storage_path('app/testing/retrieval-d05.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, json_encode($r + ['evaluation_metadata' => (array) DB::table('search_evaluations')->where('id', $r['id'])->first(['index_build_id', 'manifest_hash', 'corpus_hash', 'queries_hash']), 'embedding_profile' => (array) DB::table('embedding_profiles')->find($f['profile']), 'fixture_queries_sha256' => hash_file('sha256', base_path('docs/phase-a/retrieval_queries.jsonl')), 'source_map' => $f['sourceMap'], 'additional_distractors' => 20, 'adaptation' => 'synthetic canonical flags mapped to candidate branches; no formal commit'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->assertSame(100, $r['report']['query_count']);
        $this->assertGreaterThanOrEqual(0.9, $r['report']['recall_at_20']);
        $this->assertSame(0, $r['report']['leak_count']);
        foreach ($r['report']['queries'] as $query) {
            $this->assertSame(40, $query['eligible_documents']);
            $this->assertCount(20, $query['results']);
        }
        $this->actingAs($f['actor'])->getJson('/api/v1/novels/'.$f['novelId'].'/search-evaluations/'.$r['id'])->assertOk()->assertJsonPath('data.report.query_count', 100);
        $this->assertDatabaseCount('search_evaluations', 1);
        $this->assertDatabaseCount('ai_requests', 0);
        Http::assertNothingSent();
    }
}
