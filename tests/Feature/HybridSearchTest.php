<?php

namespace Tests\Feature;

use App\Exceptions\NovelProblem;
use App\Filament\Pages\SearchLab;
use App\Models\User;
use App\Services\LayeredMemoryService;
use App\Services\StoryConsistencyService;
use App\Services\StoryStateService;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\MemoryFixture as M;
use Tests\Support\SearchFixture as F;
use Tests\Support\StoryConsistencyFixture;
use Tests\TestCase;

/** 实际 PostgreSQL 检索、不可变代次和过滤边界的正反例。 */
class HybridSearchTest extends TestCase
{
    use RefreshDatabase;

    private function reject(callable $fn, string $code): void
    {
        try {
            $fn();
            $this->fail('应拒绝越界操作');
        } catch (NovelProblem $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    private function sqlReject(callable $fn): void
    {
        try {
            DB::transaction($fn);
            $this->fail('数据库必须拒绝');
        } catch (QueryException $e) {
            $this->assertNotEmpty($e->errorInfo);
        }
    }

    public function test_channels_short_words_unicode_and_source_invalidation(): void
    {
        $f = F::create();
        F::activate($f);
        foreach (['铜钥匙', '缺齿钥', '𠮷', '铜钥，匙！', '钥', '铜钥匙证明调苞'] as $q) {
            $r = F::query($f, ['query' => $q]);
            $this->assertCount(1, $r['results']);
            $this->assertSame($f['sources'][0], $r['results'][0]['source_version_id']);
            $this->assertArrayHasKey('vector', $r['results'][0]['contributions']);
        }
        $this->assertArrayHasKey('alias', F::query($f, ['query' => '缺齿钥'])['results'][0]['contributions']);
        $this->assertArrayHasKey('trgm', F::query($f)['results'][0]['contributions']);
        $this->assertCount(0, F::query($f, ['chapter' => 1])['results']);
        $this->assertTrue(F::query($f, ['vector' => false])['degraded']);
        M::command($f, 'invalidate', ['source_version_id' => $f['sources'][0]]);
        $this->assertCount(0, F::query($f)['results']);
        $this->assertDatabaseCount('ai_requests', 0);
        Http::assertNothingSent();
    }

    public function test_profile_switch_rollback_dedup_and_no_mixed_generations(): void
    {
        $f = F::create();
        F::command($f, 'document', array_replace($f['document'], ['title' => '另一个片段']));
        F::activate($f);
        $this->assertCount(1, F::query($f)['results']);
        $g = $f;
        $g['profile'] = F::command($f, 'profile', ['name' => '离线档案', 'encoder' => 'offline_hash_v1', 'dimensions' => 64, 'distance_metric' => 'l2'])['profile_id'];
        $g['build'] = F::command($g, 'build', ['branch_id' => $g['branch'], 'profile_id' => $g['profile']])['build_id'];
        $this->reject(fn () => F::query($g), 'INDEX_PROFILE_MISMATCH');
        F::command($g, 'document', array_replace($f['document'], ['build_id' => $g['build']]));
        F::activate($g);
        $this->reject(fn () => F::query($f), 'INDEX_PROFILE_MISMATCH');
        $this->reject(fn () => F::query($g, ['profile_id' => $f['profile']]), 'INDEX_PROFILE_MISMATCH');
        $this->assertSame(2, F::query($g)['generation']);
        F::command($f, 'activate', ['build_id' => $f['build']]);
        $this->assertSame(1, F::query($f)['generation']);
        $this->reject(fn () => F::command($f, 'document', $f['document']), 'INDEX_SEALED');
        $this->reject(fn () => F::query($f, ['scope' => 'formal']), 'FORMAL_SEARCH_UNAVAILABLE');
        $this->reject(fn () => F::query($f, ['branch_id' => M::secondBranch($f)]), 'INDEX_PROFILE_MISMATCH');
    }

    public function test_database_guards_comments_and_idempotent_commands(): void
    {
        $f = F::create();
        $r = F::command($f, 'document', $f['document'], 'same');
        $this->assertSame($r, F::command($f, 'document', $f['document'] + ['expected_version' => $r['row_version'] - 1], 'same'));
        $this->sqlReject(fn () => DB::table('embedding_profiles')->where('id', $f['profile'])->update(['dimensions' => 64]));
        $this->sqlReject(fn () => DB::table('search_documents')->delete());
        $v = (array) DB::table('memory_embeddings')->first();
        unset($v['id']);
        $v['embedding'] = '[1,2]';
        $this->sqlReject(fn () => DB::table('memory_embeddings')->insert($v));
        foreach (['embedding_profiles', 'index_builds', 'search_documents', 'memory_embeddings', 'search_evaluations'] as $table) {
            $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', DB::selectOne('SELECT obj_description(?::regclass) AS c', [$table])->c);
            foreach (DB::select('SELECT col_description(a.attrelid,a.attnum) AS c FROM pg_attribute a WHERE a.attrelid=?::regclass AND a.attnum>0 AND NOT a.attisdropped', [$table]) as $c) {
                $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $c->c);
            }
        }
        $this->assertTrue(DB::table('audit_events')->where('object_type', 'embedding_profiles')->exists());
    }

    public function test_pov_requires_acquisition_and_rechecks_its_source(): void
    {
        $f = F::create();
        $s = app(StoryStateService::class);
        $events = [];
        foreach ([0, 1] as $i) {
            $events[] = $s->propose($f['actor'], $f['novelId'], ['evidence_id' => $f['evidence'][$i], 'definition_version_id' => $f['person']['definition']['id'], 'fact_kind' => 'fact', 'action' => 'set', 'chapter_sequence' => $i + 2, 'narrative_order' => 1, 'attribute' => 'test_fact_'.$i, 'value' => $f['text'], 'confidence' => 'confirmed', 'story_time' => [], 'reason' => '验证知识来源']);
        }
        $document = array_replace($f['document'], ['event_id' => $events[0], 'aliases' => []]);
        $this->reject(fn () => F::command($f, 'document', array_replace($document, ['title' => '额外秘密'])), 'INVALID_DISCLOSURE');
        F::command($f, 'document', $document);
        F::activate($f);
        $context = app(StoryConsistencyService::class)->save($f['actor'], $f['novelId'], ['expected_version' => 0, 'reason' => '知识链测试', 'entries' => [StoryConsistencyFixture::entry($events[0], 0), StoryConsistencyFixture::entry($events[1], 10)], 'knowledge' => [['knower_id' => $f['person']['entity']['id'], 'fact_event_id' => $events[0], 'acquired_event_id' => $events[1], 'method' => 'witness']]], 'context');
        $filter = ['audience' => 'pov', 'pov_id' => $f['person']['entity']['id'], 'context_id' => $context['id']];
        $this->assertCount(0, F::query($f, $filter + ['chapter' => 2])['results']);
        $this->assertCount(1, F::query($f, $filter + ['chapter' => 3])['results']);
        M::command($f, 'invalidate', ['source_version_id' => $f['sources'][1]]);
        $this->assertCount(0, F::query($f, $filter + ['chapter' => 3])['results']);
        $this->assertCount(1, F::query($f)['results']);
    }

    public function test_memory_projection_and_api_filament_authorization(): void
    {
        $f = F::create();
        $m = M::command($f, 'save', array_replace($f['memory'], ['summary' => str_repeat($f['text'], 3)]))['memory'];
        M::command($f, 'activate', ['memory_id' => $m['id']]);
        $m = app(LayeredMemoryService::class)->read($f['actor'], $f['novelId'], $m['id'], ['scope' => 'candidate', 'branch_id' => $f['branch']]);
        F::command($f, 'document', ['build_id' => $f['build'], 'source_version_id' => $f['sources'][0], 'memory_id' => $m['id'], 'chunk_id' => $m['chunks'][0]['id'], 'title' => '记忆片段', 'aliases' => [], 'introduced_chapter' => 2]);
        F::activate($f);
        $this->assertSame('partial', app(LayeredMemoryService::class)->read($f['actor'], $f['novelId'], $m['id'], ['scope' => 'candidate', 'branch_id' => $f['branch']])['index_status']);
        $url = '/api/v1/novels/'.$f['novelId'].'/search-indexes';
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['is_admin' => false]))->getJson($url)->assertForbidden();
        $this->actingAs($f['actor'])->getJson($url)->assertOk();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(SearchLab::class)->assertSee('选择小说后');
        Livewire::test(SearchLab::class, ['novel' => $f['novelId']])->set('branch', $f['branch'])->set('profile', $f['profile'])->set('build', $f['build'])->set('query', '铜钥匙')->set('chapter', 20)->call('runQuery')->assertSee('铜钥匙证明调包');
        Livewire::test(SearchLab::class, ['novel' => $f['novelId']])->set('reason', '页面登记离线档案')->call('registerProfile')->assertSee('索引操作已保存')->set('branch', $f['branch'])->call('createBuild')->call('validateBuild')->assertSee('索引为空或嵌入缺失');
        M::command($f, 'invalidate', ['source_version_id' => $f['sources'][0]]);
        $this->assertSame('stale', app(LayeredMemoryService::class)->read($f['actor'], $f['novelId'], $m['id'], ['scope' => 'candidate', 'branch_id' => $f['branch']])['index_status']);
    }
}
