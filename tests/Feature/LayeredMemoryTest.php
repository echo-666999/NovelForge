<?php

namespace Tests\Feature;

use App\Exceptions\NovelProblem;
use App\Filament\Pages\MemoryLibrary;
use App\Models\Novel;
use App\Models\User;
use App\Services\LayeredMemoryService;
use App\Services\NovelIntentService;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\MemoryFixture as F;
use Tests\TestCase;

/** 只证明分支内事实保留与来源完整，不把摘要当成正式状态。 */
class LayeredMemoryTest extends TestCase
{
    use RefreshDatabase;

    private function reject(callable $fn, string $code): void
    {
        try {
            $fn();
            $this->fail('必须拒绝');
        } catch (NovelProblem $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    private function invalid(callable $fn): void
    {
        try {
            $fn();
            $this->fail('必须拒绝输入');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
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

    private function filters(array $f, array $extra = []): array
    {
        return $extra + ['scope' => 'candidate', 'branch_id' => $f['branch']];
    }

    public function test_four_layers_chunks_and_content_are_independent_from_index_state(): void
    {
        $f = F::create();
        $s = app(LayeredMemoryService::class);
        foreach (LayeredMemoryService::KINDS as $kind) {
            $m = F::command($f, 'save', array_replace($f['memory'], ['kind' => $kind]))['memory'];
            $this->assertSame('pending', $m['content_status']);
            $this->assertSame('not_requested', $m['index_status']);
            F::command($f, 'activate', ['memory_id' => $m['id']]);
            $r = $s->read($f['actor'], $f['novelId'], $m['id'], $this->filters($f));
            $this->assertSame('active', $r['content_status']);
            $this->assertSame('not_requested', $r['index_status']);
            $this->assertFalse($r['formal_usable']);
            $this->assertFalse($r['pov_usable']);
            $this->assertSame($f['text'], implode('', array_column($r['chunks'], 'text')));
            foreach ($r['chunks'] as $chunk) {
                $this->assertSame(hash('sha256', $chunk['text']), $chunk['content_hash']);
                $this->assertSame(mb_substr($r['summary'], $chunk['start_offset'], $chunk['end_offset'] - $chunk['start_offset']), $chunk['text']);
            }
            $this->assertCount(4, $r['facts']);
            $this->assertCount(1, $r['evidence']);
        }
        $this->assertCount(4, $s->listing($f['actor'], $f['novelId'], $this->filters($f, ['status' => 'active']))['items']);
        $this->assertCount(0, $s->listing($f['actor'], $f['novelId'], $this->filters($f, ['chapter' => 1]))['items']);
        $this->assertCount(0, $s->listing($f['actor'], $f['novelId'], $this->filters($f, ['index_status' => 'failed']))['items']);
        $this->assertSame(0, Novel::find($f['novelId'])->state_version);
        Http::assertNothingSent();
    }

    public function test_omission_cannot_replace_active_even_when_parent_and_fact_ids_are_omitted_and_retry_preserves_history(): void
    {
        $f = F::create();
        $s = app(LayeredMemoryService::class);
        $old = F::command($f, 'save', $f['memory'])['memory'];
        F::command($f, 'activate', ['memory_id' => $old['id']]);
        $bad = F::command($f, 'save', array_replace($f['memory'], ['collection_id' => $old['collection_id'], 'summary' => '铜钥匙证明调包。', 'fact_ids' => [], 'parent_ids' => []]))['memory'];
        $this->assertSame('failed', $bad['content_status']);
        $this->assertCount(3, $bad['report']['issues']);
        $this->assertContains($old['id'], $bad['payload']['parent_ids']);
        $this->assertCount(4, $bad['payload']['fact_ids']);
        $this->reject(fn () => F::command($f, 'activate', ['memory_id' => $bad['id']]), 'MEMORY_VALIDATION_FAILED');
        $active = $s->listing($f['actor'], $f['novelId'], $this->filters($f, ['status' => 'active']))['items'];
        $this->assertSame($old['id'], $active[0]['id']);
        $retry = F::command($f, 'save', array_replace($f['memory'], ['collection_id' => $old['collection_id'], 'retry_of_id' => $bad['id'], 'summary' => '修订摘要：'.$f['text']]))['memory'];
        $this->assertSame($bad['id'], $retry['retry_of_id']);
        $this->assertTrue($retry['report']['passed']);
        F::command($f, 'activate', ['memory_id' => $retry['id']]);
        $active = $s->listing($f['actor'], $f['novelId'], $this->filters($f, ['status' => 'active']))['items'];
        $this->assertCount(1, $active);
        $this->assertSame($retry['id'], $active[0]['id']);
        $this->assertDatabaseCount('memory_items', 3);
        $this->assertDatabaseCount('memory_activations', 2);
    }

    public function test_source_invalidation_is_transitive_and_blocks_activation_and_active_reads(): void
    {
        $f = F::create();
        $s = app(LayeredMemoryService::class);
        $a = F::command($f, 'save', $f['memory'])['memory'];
        F::command($f, 'activate', ['memory_id' => $a['id']]);
        $b = F::command($f, 'save', array_replace($f['memory'], ['name' => '卷摘要', 'parent_ids' => [$a['id']]]))['memory'];
        F::command($f, 'activate', ['memory_id' => $b['id']]);
        $c = F::command($f, 'save', array_replace($f['memory'], ['name' => '长程摘要', 'parent_ids' => [$b['id']]]))['memory'];
        F::command($f, 'invalidate', ['source_version_id' => $f['sources'][0], 'reason' => '源正文已经作废']);
        $this->assertCount(3, $s->listing($f['actor'], $f['novelId'], $this->filters($f, ['status' => 'stale']))['items']);
        $this->assertCount(0, $s->listing($f['actor'], $f['novelId'], $this->filters($f, ['status' => 'active']))['items']);
        $this->reject(fn () => F::command($f, 'activate', ['memory_id' => $c['id']]), 'STALE_SOURCE');
        $r = $s->read($f['actor'], $f['novelId'], $c['id'], $this->filters($f));
        $this->assertSame('源正文已经作废', $r['invalidations'][0]['reason']);
        $this->assertSame($b['id'], $r['parents'][0]['id']);
    }

    public function test_disabling_parent_invalidates_descendants_without_disabling_unrelated_memory(): void
    {
        $f = F::create();
        $s = app(LayeredMemoryService::class);
        $a = F::command($f, 'save', $f['memory'])['memory'];
        $b = F::command($f, 'save', array_replace($f['memory'], ['name' => '依赖摘要', 'parent_ids' => [$a['id']]]))['memory'];
        F::command($f, 'activate', ['memory_id' => $b['id']]);
        $other = F::command($f, 'save', array_replace($f['memory'], ['name' => '独立摘要']))['memory'];
        F::command($f, 'activate', ['memory_id' => $other['id']]);
        F::command($f, 'invalidate', ['memory_id' => $a['id'], 'reason' => '作者发现派生错误']);
        $this->assertSame('stale', $s->read($f['actor'], $f['novelId'], $b['id'], $this->filters($f))['content_status']);
        $this->assertSame($other['id'], $s->listing($f['actor'], $f['novelId'], $this->filters($f, ['status' => 'active']))['items'][0]['id']);
    }

    public function test_formal_branch_and_cross_book_scopes_never_fall_back_to_candidate_sources(): void
    {
        $f = F::create();
        $s = app(LayeredMemoryService::class);
        $m = F::command($f, 'save', $f['memory'])['memory'];
        foreach (['1', '9223372036854775807'] as $revision) {
            $this->reject(fn () => $s->listing($f['actor'], $f['novelId'], ['scope' => 'formal', 'revision_id' => $revision]), 'STALE_REVISION');
        }
        $this->invalid(fn () => $s->listing($f['actor'], $f['novelId'], []));
        $this->invalid(fn () => $s->listing($f['actor'], $f['novelId'], $this->filters($f, ['revision_id' => '1'])));
        $branch = F::secondBranch($f);
        $this->reject(fn () => F::command($f, 'save', $f['memory'] + ['branch_id' => $branch]), 'NOT_FOUND');
        $this->reject(fn () => $s->read($f['actor'], $f['novelId'], $m['id'], ['scope' => 'candidate', 'branch_id' => $branch]), 'NOT_FOUND');
        $this->assertCount(0, $s->listing($f['actor'], $f['novelId'], ['scope' => 'candidate', 'branch_id' => $branch])['items']);
        $book = app(NovelIntentService::class)->execute($f['actor'], 'create', null, ['title' => '别书', 'genre' => '悬疑', 'background' => '测试', 'premise_raw' => '另一部作品', 'target_chapters' => 10, 'length_policy' => ['min' => 2000, 'max' => 3000]], 'other')['novel'];
        $this->reject(fn () => $s->read($f['actor'], $book['id'], $m['id'], $this->filters($f)), 'NOT_FOUND');
        $this->invalid(fn () => F::command($f, 'save', $f['memory'] + ['revision_id' => 1]));
    }

    public function test_visibility_and_chapter_cannot_widen_during_compression_and_evidence_must_match(): void
    {
        $f = F::create();
        $a = F::command($f, 'save', $f['memory'])['memory'];
        $this->reject(fn () => F::command($f, 'save', array_replace($f['memory'], ['parent_ids' => [$a['id']], 'introduced_chapter' => 1])), 'VISIBILITY_WIDENED');
        $this->reject(fn () => F::command($f, 'save', array_replace($f['memory'], ['parent_ids' => [$a['id']], 'visibility' => ['mode' => 'characters', 'character_ids' => [$f['person']['entity']['id']]]])), 'VISIBILITY_WIDENED');
        $this->reject(fn () => F::command($f, 'fact', ['source_version_id' => $f['sources'][1], 'evidence_id' => $f['evidence'][0], 'category' => 'irreversible', 'statement' => '许砚失去右手。', 'introduced_chapter' => 2]), 'INVALID_EVIDENCE');
        $this->reject(fn () => F::command($f, 'fact', ['source_version_id' => $f['sources'][0], 'evidence_id' => $f['evidence'][0], 'category' => 'irreversible', 'statement' => '许砚得到新手臂。', 'introduced_chapter' => 2]), 'INVALID_EVIDENCE');
        $early = F::command($f, 'save', array_replace($f['memory'], ['introduced_chapter' => 1]))['memory'];
        $this->assertFalse($early['report']['passed']);
        $this->assertContains('FUTURE_FACT', array_column($early['report']['issues'], 'code'));
    }

    public function test_canceled_branch_is_stale_and_new_versions_cannot_reuse_it(): void
    {
        $f = F::create();
        $m = F::command($f, 'save', $f['memory'])['memory'];
        F::command($f, 'activate', ['memory_id' => $m['id']]);
        // 用持久运行的真实状态值模拟分支取消后的读取，不制造正式基线。
        DB::table('generation_runs')->where('id', $f['branch'])->update(['status' => 'canceled']);
        $s = app(LayeredMemoryService::class);
        $this->assertCount(0, $s->listing($f['actor'], $f['novelId'], $this->filters($f, ['status' => 'active']))['items']);
        $this->assertSame('stale', $s->read($f['actor'], $f['novelId'], $m['id'], $this->filters($f))['content_status']);
        $this->reject(fn () => F::command($f, 'save', $f['memory']), 'STALE_SOURCE');
    }

    public function test_idempotence_stale_version_and_database_guards(): void
    {
        $f = F::create();
        $p = $f['memory'] + ['expected_version' => Novel::find($f['novelId'])->row_version];
        $r = F::command($f, 'save', $p, 'same');
        $again = F::command($f, 'save', $p, 'same');
        $this->assertSame(NovelIntentService::canonical($r), NovelIntentService::canonical($again));
        $this->reject(fn () => F::command($f, 'save', $p, 'different'), 'STALE_STATE');
        $this->reject(fn () => F::command($f, 'save', array_replace($p, ['summary' => '不同']), 'same'), 'IDEMPOTENCY_CONFLICT');
        $m = $r['memory'];
        $this->sqlReject(fn () => DB::table('memory_items')->where('id', $m['id'])->update(['summary' => '篡改']));
        $this->sqlReject(fn () => DB::table('memory_chunks')->where('memory_id', $m['id'])->delete());
        $this->sqlReject(fn () => DB::table('memory_sources')->insert(['novel_id' => $f['novelId'], 'branch_id' => $f['branch'], 'memory_id' => $m['id'], 'source_version_id' => $f['sources'][1], 'source_hash' => hash('sha256', $f['text'])]));
        $bad = F::command($f, 'save', array_replace($f['memory'], ['summary' => '遗漏']))['memory'];
        $this->sqlReject(fn () => DB::table('memory_activations')->insert(['novel_id' => $f['novelId'], 'branch_id' => $f['branch'], 'memory_id' => $bad['id'], 'reason' => '绕过服务', 'actor_id' => $f['actor']->id]));
        $tables = DB::select("SELECT c.relname,obj_description(c.oid) AS remark FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind='r' AND c.relname LIKE 'memory_%' AND c.relname <> 'memory_embeddings'");
        $this->assertCount(9, $tables);
        foreach ($tables as $t) {
            $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $t->remark);
            foreach (DB::select('SELECT col_description(a.attrelid,a.attnum) AS remark FROM pg_attribute a WHERE a.attrelid=?::regclass AND a.attnum>0 AND NOT a.attisdropped', [$t->relname]) as $c) {
                $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $c->remark);
            }
        }
        $this->assertTrue(DB::table('audit_events')->where('object_type', 'memory_items')->exists());
    }

    public function test_api_and_filament_use_same_versioned_service_and_escape_text(): void
    {
        $f = F::create();
        $url = '/api/v1/novels/'.$f['novelId'].'/memories';
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['is_admin' => false]))->getJson($url)->assertForbidden();
        $this->actingAs($f['actor']);
        $this->getJson($url.'?scope=formal&revision_id=1')->assertConflict()->assertJsonPath('error.code', 'STALE_REVISION');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(MemoryLibrary::class)->assertSee('请先选择小说和候选分支');
        $page = Livewire::test(MemoryLibrary::class, ['novel' => $f['novelId'], 'branch' => $f['branch']])->assertSee('暂无符合条件')->set('form', $f['memory'])->set('reason', '作者离线摘要验收')->call('saveMemory')->assertSee('记忆操作已保存')->call('activate');
        $m = $page->get('detail');
        $this->assertSame('active', $m['content_status']);
        $page->call('loadRevision')->set('form.summary', '遗漏事实')->call('saveMemory')->assertSee('旧活动记忆未替换');
        $this->getJson($url.'/'.$m['id'].'?scope=candidate&branch_id='.$f['branch'])->assertOk()->assertJsonPath('data.content_status', 'active')->assertJsonPath('data.index_status', 'not_requested');
        $page->call('loadRevision')->set('form.summary', '<script>alert(1)</script>'.$f['text'])->call('saveMemory')->assertDontSeeHtml('<script>alert(1)</script>');
        $this->assertDatabaseCount('ai_requests', 0);
        Http::assertNothingSent();
    }
}
