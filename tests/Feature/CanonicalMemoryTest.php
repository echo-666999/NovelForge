<?php

namespace Tests\Feature;

use App\Exceptions\NovelProblem;
use App\Filament\Pages\CanonicalMemoryCenter;
use App\Services\CanonicalMemoryService;
use App\Services\DraftingService;
use App\Services\HybridSearchService;
use App\Services\LayeredMemoryService;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\CanonicalFixture as F;
use Tests\Support\ExtractionFixture;
use Tests\Support\ReviewFixture;
use Tests\TestCase;

/** 正式投影只用隔离PostgreSQL和明确离线来源，不请求真实模型。 */
class CanonicalMemoryTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $f = F::create(true, true);
        $f['commit'] = F::commit($f);
        $s = app(CanonicalMemoryService::class);
        $s->discover();
        $f['task'] = (string) DB::table('canonical_memory_tasks')->value('id');

        return $f;
    }

    public function test_outbox_materializes_four_memories_and_offline_index_exactly_once(): void
    {
        $f = $this->fixture();
        $s = app(CanonicalMemoryService::class);
        $this->assertTrue($s->dashboard($f['actor'], $f['novel'])['memory_pending']);
        $this->assertTrue($s->consume($f['task']));
        $this->assertFalse($s->consume($f['task']));
        $s->discover();
        $this->assertDatabaseCount('canonical_memory_tasks', 1);
        $this->assertDatabaseCount('canonical_memories', 4);
        $this->assertDatabaseCount('canonical_memory_documents', 1);
        $this->assertDatabaseCount('canonical_memory_embeddings', 1);
        $this->assertDatabaseCount('story_events', 1);
        $mem = app(LayeredMemoryService::class)->listing($f['actor'], $f['novel'], ['scope' => 'formal', 'revision_id' => $f['revision']]);
        $this->assertSame(4, $mem['total']);
        $q = ['scope' => 'formal', 'revision_id' => $f['revision'], 'chapter' => 1, 'query' => '封记', 'audience' => 'pov', 'pov_id' => $f['person']['entity']['id']];
        $hits = app(HybridSearchService::class)->search($f['actor'], $f['novel'], $q);
        $this->assertCount(1, $hits['results']);
        $this->assertFalse($hits['memory_pending']);
        $this->assertFalse($s->dashboard($f['actor'], $f['novel'])['memory_pending']);
        foreach (['canonical_memory_tasks', 'canonical_memory_attempts', 'canonical_memories', 'canonical_memory_documents', 'canonical_memory_embeddings'] as $t) {
            $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', DB::selectOne('SELECT obj_description(?::regclass) AS c', [$t])->c);
            foreach (DB::select('SELECT col_description(attrelid,attnum) AS c FROM pg_attribute WHERE attrelid=?::regclass AND attnum>0 AND NOT attisdropped', [$t]) as $c) {
                $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $c->c);
            }
        }
        Http::assertNothingSent();
    }

    public function test_pending_index_never_omits_formal_state_or_recent_body_from_next_chapter(): void
    {
        $f = $this->fixture();
        $next = F::nextChapter($f);
        $scene = DB::table('scene_versions')->where('node_id', $next['scene']->id)->first();
        $cfg = json_decode(DB::table('generation_runs')->find($scene->run_id)->frozen_config, true)['context'];
        $this->assertSame('formal', $cfg['scope']);
        $this->assertTrue($cfg['formal_usable']);
        $this->assertTrue($cfg['memory_pending']);
        $state = collect($cfg['items'])->firstWhere('category', 'state');
        $recent = collect($cfg['items'])->firstWhere('category', 'recent_body');
        $this->assertTrue($state['required']);
        $this->assertTrue($state['selected']);
        $this->assertTrue($recent['required']);
        $this->assertSame($f['body']['body'], $recent['text']);
        $this->assertDatabaseCount('canonical_memories', 0);
        $this->assertSame(1, DB::table('novels')->where('id', $f['novel'])->value('state_version'));
        Http::assertNothingSent();
    }

    public function test_vector_degradation_and_explicit_retry_do_not_duplicate_facts_or_summaries(): void
    {
        $f = $this->fixture();
        $s = app(CanonicalMemoryService::class);
        config(['novelforge.canonical_vectors' => false]);
        $s->consume($f['task']);
        $q = ['revision_id' => $f['revision'], 'chapter' => 1, 'query' => '封记', 'audience' => 'author'];
        $r = $s->search($f['actor'], $f['novel'], $q);
        $this->assertTrue($r['degraded']);
        $this->assertCount(1, $r['results']);
        $a = $s->retry($f['actor'], $f['novel'], $f['task'], '恢复离线向量', 'retry');
        $this->assertSame($a, $s->retry($f['actor'], $f['novel'], $f['task'], '恢复离线向量', 'retry'));
        config(['novelforge.canonical_vectors' => true]);
        $s->consume($f['task']);
        $this->assertDatabaseCount('canonical_memories', 4);
        $this->assertDatabaseCount('canonical_memory_documents', 1);
        $this->assertDatabaseCount('canonical_memory_embeddings', 1);
        $this->assertDatabaseCount('story_events', 1);
        $this->assertFalse($s->search($f['actor'], $f['novel'], $q)['degraded']);
    }

    public function test_old_lease_cannot_complete_and_failed_projection_is_atomic(): void
    {
        $f = $this->fixture();
        $s = app(CanonicalMemoryService::class);
        $old = $s->claim($f['task']);
        $p = $s->prepare($old);
        DB::table('canonical_memory_tasks')->where('id', $f['task'])->update(['lease_until' => DB::raw("clock_timestamp()-interval '1 second'")]);
        $new = $s->claim($f['task']);
        $this->assertGreaterThan($old['token'], $new['token']);
        $this->assertFalse($s->finish($old, $p));
        DB::unprepared("CREATE OR REPLACE FUNCTION pg_temp.e07_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'E07 fault'; END $$; CREATE TRIGGER zz_fault BEFORE INSERT ON canonical_memory_documents FOR EACH ROW EXECUTE FUNCTION pg_temp.e07_fault();");
        try {
            $s->finish($new, $s->prepare($new));
            $this->fail('故障必须回滚全部派生数据');
        } catch (QueryException $e) {
            $this->assertStringContainsString('E07 fault', $e->getMessage());
        } finally {
            DB::statement('DROP TRIGGER zz_fault ON canonical_memory_documents');
        }
        $this->assertDatabaseCount('canonical_memories', 0);
        $this->assertDatabaseCount('canonical_memory_documents', 0);
        $this->assertTrue($s->finish($new, $s->prepare($new)));
        $this->assertDatabaseCount('canonical_memories', 4);
    }

    public function test_formal_filters_reject_other_revision_and_future_cutoff_without_candidate_fallback(): void
    {
        $f = $this->fixture();
        $s = app(CanonicalMemoryService::class);
        $s->consume($f['task']);
        foreach ([['revision_id' => '999999', 'chapter' => 1, 'query' => '封记', 'audience' => 'author'], ['revision_id' => $f['revision'], 'chapter' => 2, 'query' => '封记', 'audience' => 'author']] as $d) {
            try {
                $s->search($f['actor'], $f['novel'], $d);
                $this->fail('不能放宽正式过滤');
            } catch (NovelProblem $e) {
                $this->assertContains($e->errorCode, ['STALE_REVISION', 'FORMAL_PREFIX_INCOMPLETE']);
            }
        }
        $other = (string) DB::table('novels')->where('id', '<>', $f['novel'])->value('id');
        try {
            $s->memories($f['actor'], $other, ['revision_id' => $f['revision']]);
            $this->fail('跨书不可读取');
        } catch (NovelProblem $e) {
            $this->assertSame('STALE_REVISION', $e->errorCode);
        }
    }

    /** 死亡等不可逆状态从正式快照直读，不受摘要/向量延迟影响。 */
    public function test_irreversible_state_is_injected_without_any_memory_or_embedding(): void
    {
        $f = F::create();
        $d = ['source_version_id' => $f['body']['id'], 'expected_version' => 1, 'plan_id' => $f['plan']['id'], 'title' => $f['body']['title'], 'body' => $f['body']['body'].'许砚死亡。', 'reason' => '不可逆状态的明确离线原文'];
        $f['body'] = app(DraftingService::class)->save($f['actor'], $f['novel'], 'chapter', $f['body']['node_id'], $d, 'death-body');
        $event = ExtractionFixture::event($f);
        $death = ExtractionFixture::event($f, 'death', ['attribute' => 'life_status', 'value' => 'dead', 'time' => ['kind' => 'exact', 'clock' => 'relative_seconds', 'start' => 10, 'end' => 10]], '许砚死亡');
        $f['extraction'] = ExtractionFixture::extract($f, ExtractionFixture::output([$event, $death]));
        $f['review'] = ReviewFixture::review($f);
        $f['payload'] = F::payload($f);
        F::commit($f);
        $bridge = app(CanonicalMemoryService::class)->bridge($f['actor'], $f['novel'], $f['revision'], 1, $f['character'][0]['entity_id']);
        $this->assertTrue($bridge['memory_pending']);
        $this->assertContains('dead', array_column($bridge['state']['state']['fact'], 'value'));
        $this->assertSame($f['body']['body'], $bridge['recent'][0]['body']);
        $this->assertDatabaseCount('canonical_memories', 0);
        $this->assertDatabaseCount('canonical_memory_embeddings', 0);
        // 模拟单份嵌入失败：可保留文本索引，但查询必须一致地回退至文本排名。
        $service = app(CanonicalMemoryService::class);
        $service->discover();
        $lease = $service->claim((string) DB::table('canonical_memory_tasks')->value('id'));
        $prepared = $service->prepare($lease);
        unset($prepared['vectors'][$prepared['documents'][1]['event_id']]);
        $prepared['vector_status'] = 'degraded';
        $service->finish($lease, $prepared);
        $hits = $service->search($f['actor'], $f['novel'], ['revision_id' => $f['revision'], 'chapter' => 1, 'query' => '封记', 'audience' => 'author']);
        $this->assertTrue($hits['degraded']);
        $this->assertCount(1, $hits['results']);
        $this->assertArrayNotHasKey('offline_vector', $hits['results'][0]['contributions']);
        Http::assertNothingSent();
    }

    public function test_formal_pov_without_acquisition_cannot_retrieve_another_characters_fact(): void
    {
        $f = $this->fixture();
        $s = app(CanonicalMemoryService::class);
        $s->consume($f['task']);
        $q = ['revision_id' => $f['revision'], 'chapter' => 1, 'query' => '封记', 'audience' => 'pov', 'pov_id' => $f['character'][0]['entity_id']];
        $this->assertSame([], $s->search($f['actor'], $f['novel'], $q)['results']);
        $this->assertCount(1, $s->search($f['actor'], $f['novel'], array_replace($q, ['pov_id' => $f['person']['entity']['id']]))['results']);
    }

    /** HTTP和Livewire复用同书服务，任务中心不把重试变成正式剧情操作。 */
    public function test_api_and_livewire_show_formal_pending_and_explicit_retry(): void
    {
        $f = $this->fixture();
        $url = '/api/v1/novels/'.$f['novel'].'/canonical-memory';
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($f['actor']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.memory_pending', true)->assertHeader('Cache-Control', 'no-store, private');
        $this->withHeader('Idempotency-Key', 'bad')->postJson($url.'/tasks/'.$f['task'].'/retry', ['reason' => '非法字段', 'force' => true])->assertUnprocessable();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(CanonicalMemoryCenter::class)->assertSee('请选择小说');
        Livewire::test(CanonicalMemoryCenter::class, ['novel' => $f['novel']])->assertSee('memory_pending')->set('reason', '作者明确恢复派生任务')->call('retry', $f['task'])->assertSee('已处理');
        app(CanonicalMemoryService::class)->consume($f['task']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.memory_pending', false);
        Livewire::test(CanonicalMemoryCenter::class, ['novel' => $f['novel']])->set('query', '封记')->call('search')->assertSet('result.memory_pending', false)->assertCount('result.results', 1);
        $this->assertDatabaseCount('canonical_commits', 1);
        Http::assertNothingSent();
    }

    /** 任何正式版本不一致都等待，不从候选或旧索引补造硬事实。 */
    public function test_inconsistent_head_and_incomplete_projection_wait_instead_of_succeeding(): void
    {
        $f = $this->fixture();
        $s = app(CanonicalMemoryService::class);
        $lease = $s->claim($f['task']);
        $p = $s->prepare($lease);
        $p['documents'] = [];
        try {
            $s->finish($lease, $p);
            $this->fail('缺文档不能成功封口');
        } catch (NovelProblem $e) {
            $this->assertSame('INCOMPLETE_MEMORY_OUTPUT', $e->errorCode);
        }
        $this->assertDatabaseCount('canonical_memories', 0);
        DB::table('novels')->where('id', $f['novel'])->increment('state_version');
        try {
            $s->bridge($f['actor'], $f['novel'], $f['revision'], 1, $f['person']['entity']['id']);
            $this->fail('版本不一致必须等待');
        } catch (NovelProblem $e) {
            $this->assertSame('STALE_STATE', $e->errorCode);
        }
    }

    public function test_technical_limit_and_failure_history_require_explicit_retry(): void
    {
        $f = $this->fixture();
        $s = app(CanonicalMemoryService::class);
        for ($i = 0; $i < 3; $i++) {
            $lease = $s->claim($f['task']);
            $s->failure($lease, 'TEST_TRANSIENT');
            DB::table('canonical_memory_tasks')->where('id', $f['task'])->update(['available_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
        }
        $this->assertNull($s->claim($f['task']));
        $this->assertSame('failed', DB::table('canonical_memory_tasks')->where('id', $f['task'])->value('status'));
        $this->assertSame(3, DB::table('canonical_memory_attempts')->where('kind', 'failed')->count());
        $s->retry($f['actor'], $f['novel'], $f['task'], '作者明确重新执行离线派生', 'manual');
        $s->consume($f['task']);
        $this->assertDatabaseCount('canonical_memories', 4);
        $this->assertSame(3, DB::table('canonical_memory_attempts')->where('kind', 'failed')->count());
    }

    /** 推断可留在角色知识快照，但不能借事实检索路径无类型地升级为确定事实。 */
    public function test_inferred_knowledge_stays_typed_and_is_not_reinjected_as_retrieved_fact(): void
    {
        $f = F::create();
        $output = ExtractionFixture::output([ExtractionFixture::event($f)]);
        $output['knowledge'] = [['knower_id' => $f['person']['entity']['id'], 'fact_ref' => 'local:observe', 'acquired_ref' => 'local:observe', 'method' => 'inference']];
        $f['extraction'] = ExtractionFixture::extract($f, $output);
        $f['review'] = ReviewFixture::review($f);
        $f['payload'] = F::payload($f);
        F::commit($f);
        $s = app(CanonicalMemoryService::class);
        $s->discover();
        $s->consume((string) DB::table('canonical_memory_tasks')->value('id'));
        $bridge = $s->bridge($f['actor'], $f['novel'], $f['revision'], 1, $f['person']['entity']['id']);
        $this->assertSame('inference', $bridge['pov']['knowledge'][0]['knowledge_kind']);
        $this->assertSame([], $bridge['known_event_ids']);
        $this->assertSame([], $s->search($f['actor'], $f['novel'], ['revision_id' => $f['revision'], 'chapter' => 1, 'audience' => 'pov', 'pov_id' => $f['person']['entity']['id'], 'query' => '封记'])['results']);
        Http::assertNothingSent();
    }
}
