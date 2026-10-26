<?php

namespace Tests\Feature;

use App\Filament\Pages\ExtractionLedger;
use App\Filament\Pages\Overview;
use App\Filament\Support\JsonFields;
use App\Models\Novel;
use App\Models\User;
use App\Services\NovelIntentService;
use App\Services\WorkspaceService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ExtractionFixture as F;
use Tests\TestCase;

/** UI05仅使用隔离PostgreSQL、真实离线正文及既有服务，绝不调用真实模型。 */
class ExtractionUiTest extends TestCase
{
    use RefreshDatabase;

    private function actor(?User $actor = null): User
    {
        Http::preventStrayRequests();
        $actor ??= User::factory()->create(['is_admin' => true]);
        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $actor;
    }

    public function test_empty_overview_and_extraction_have_explicit_unknowns_and_no_writes(): void
    {
        $this->actor();
        Livewire::test(Overview::class)->assertSee('尚未创建小说')->assertSee('未知')->assertSee('尚未接入总览')
            ->set('status', 'invalid')->assertSee('读取失败');
        Livewire::test(ExtractionLedger::class)->assertSee('先选择当前小说')->assertDontSee('离线提取响应');
        $this->assertDatabaseCount('novels', 0);
        $this->assertDatabaseCount('generation_runs', 0);
        $this->assertDatabaseCount('ai_requests', 0);
        Http::assertNothingSent();
    }

    public function test_editor_builds_event_with_actual_selectors_saves_and_retries_idempotently(): void
    {
        $f = F::create('😀甲许砚检查封记。');
        $this->actor($f['actor']);
        $requests = DB::table('ai_requests')->count();
        $page = Livewire::test(ExtractionLedger::class)->set('novel', $f['novel'])->set('version', $f['body']['id'])->call('loadBody')->assertSee('原文指纹');
        $events = $page->instance()->responseField('events');
        $page->call('addResponseRow', $events);
        $map = JsonFields::paths($page->get('trees.extraction'), 'trees.extraction', 'response');
        $time = $map['response.events.0.time'];
        foreach (['clock', 'start', 'end'] as $key) {
            $page->call('addResponseField', $time, $key);
        }
        $map = JsonFields::paths($page->get('trees.extraction'), 'trees.extraction', 'response');
        $e = F::event($f);
        foreach (['key', 'definition_version_id', 'fact_kind', 'action', 'attribute', 'value', 'confidence', 'reason'] as $key) {
            $page->set(JsonFields::binding($map['response.events.0.'.$key]), $e[$key]);
        }
        foreach (['time', 'evidence'] as $group) {
            foreach ($e[$group] as $key => $value) {
                $page->set(JsonFields::binding($map['response.events.0.'.$group.'.'.$key]), (string) $value);
            }
        }
        $page->set('reason', '逐字段验证同一正文')->call('extract')->assertHasNoErrors()->assertSet('detail.current_eligible', true)->assertDontSee('提取资料读取失败');
        $id = $page->get('selected');
        $page->call('extract')->assertSet('selected', $id);
        $this->assertDatabaseCount('extraction_attempts', 1);
        $this->assertSame(2, $page->get('detail')['events'][0]['evidence']['start_offset']);
        $this->assertDatabaseCount('story_events', 0);
        $this->assertSame(0, Novel::find($f['novel'])->state_version);
        $this->assertSame($requests, DB::table('ai_requests')->count());
        $page->call('exportResponseFields')->assertFileDownloaded('extraction-response.json');
        $actual = json_decode(base64_decode(data_get($page->effects, 'download.content')), true);
        $this->assertEquals(F::output([$e]), $actual);
        Http::assertNothingSent();
    }

    public function test_forged_quote_has_event_key_source_and_focus_then_correct_reextract(): void
    {
        $f = F::create();
        $this->actor($f['actor']);
        $out = F::output([F::event($f)]);
        $out['events'][0]['evidence']['quote'] = '伪造引文';
        $page = Livewire::test(ExtractionLedger::class)->set('novel', $f['novel'])->set('version', $f['body']['id'])->call('loadBody')
            ->set('importResponse', json_encode($out))->call('importResponseFields')->set('reason', '验证错误不冒充成功')->call('extract')
            ->assertSet('noticeState', 'error')->assertSee('INVALID_EVIDENCE')->assertSee('定位到事件编辑行')->call('focusExtractionIssue', 'observe')->assertDispatched('extraction-editor-focused');
        $id = $page->get('selected');
        $page->call('editExtraction', $id)->assertSet('parent', $id)->assertDontSee('提取资料读取失败');
        $map = JsonFields::paths($page->get('trees.extraction'), 'trees.extraction', 'response');
        $page->set(JsonFields::binding($map['response.events.0.evidence.quote']), F::event($f)['evidence']['quote'])->set('reason', '修正引文保留原记录')->call('extract')->assertSet('detail.current_eligible', true);
        $this->assertDatabaseCount('extraction_attempts', 2);
        $this->assertDatabaseCount('chapter_versions', 1);
        $this->assertDatabaseCount('story_events', 0);
        Http::assertNothingSent();
    }

    public function test_import_preserves_types_unknown_fields_order_and_failed_import_is_atomic(): void
    {
        $this->actor();
        $out = F::output([]) + ['future' => ['flag' => false, 'zero' => 0, 'empty' => '', 'null' => null, 'ordered' => ['乙', '甲'], 'obj' => (object) []]];
        $page = Livewire::test(ExtractionLedger::class)->set('importResponse', json_encode($out))->call('importResponseFields')->call('exportResponseFields');
        $export = json_decode(base64_decode(data_get($page->effects, 'download.content')));
        $this->assertSame(false, $export->future->flag);
        $this->assertSame(0, $export->future->zero);
        $this->assertSame('', $export->future->empty);
        $this->assertNull($export->future->null);
        $this->assertSame(['乙', '甲'], $export->future->ordered);
        $this->assertInstanceOf(\stdClass::class, $export->future->obj);
        $before = $page->get('trees');
        $page->set('importResponse', '{"events":[],"events":[]}')->call('importResponseFields')->assertHasErrors('importJson')->assertSet('trees', $before);
        $page->set('jsonInputs.forged', 'bad')->assertHasErrors('trees');
        $this->assertDatabaseCount('extraction_attempts', 0);
    }

    public function test_lists_keep_order_and_required_fields_cannot_be_removed(): void
    {
        $this->actor();
        $page = Livewire::test(ExtractionLedger::class);
        $path = $page->instance()->responseField('events');
        $page->call('addResponseRow', $path)->call('addResponseRow', $path);
        $first = $page->get($path.'.children.0.node');
        $second = $page->get($path.'.children.1.node');
        $this->assertSame('event_1', $first['children'][0]['node']['value']);
        $this->assertSame('event_2', $second['children'][0]['node']['value']);
        $page->call('changeJsonRow', $path, 1, 'up')->assertSet($path.'.children.0.node', $second)->call('removeResponseField', $path.'.children.0.node', 0)->assertHasErrors($path.'.children.0.node');
        $this->assertDatabaseCount('extraction_attempts', 0);
    }

    public function test_loaded_concurrency_conflict_and_cross_book_versions_cannot_write(): void
    {
        $f = F::create();
        $this->actor($f['actor']);
        $page = Livewire::test(ExtractionLedger::class)->set('novel', $f['novel'])->set('version', $f['body']['id'])->call('loadBody')->set('importResponse', json_encode(F::output([F::event($f)])))->call('importResponseFields')->set('reason', '并发冲突');
        DB::table('novels')->where('id', $f['novel'])->increment('row_version');
        $page->call('extract')->assertSet('noticeState', 'error')->assertHasNoErrors()->assertSee('操作结果');
        $this->assertDatabaseCount('extraction_attempts', 0);
        $foreign = (string) DB::table('novels')->where('id', '<>', $f['novel'])->value('id');
        $page->set('novel', $foreign)->call('loadBody')->assertSet('noticeState', 'error');
        $this->assertDatabaseCount('extraction_attempts', 0);
        $this->actingAs(User::factory()->create())->get('/admin/extraction-ledger?novel='.$f['novel'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_overview_read_failure_is_not_empty_and_output_is_escaped(): void
    {
        $this->actor();
        $this->mock(WorkspaceService::class)->shouldReceive('overview')->andThrow(new \RuntimeException('isolated-read-failure'));
        Livewire::test(Overview::class)->assertSee('读取失败')->assertDontSee('尚未创建小说')->assertSee('重试');
        $this->assertDatabaseCount('novels', 0);
    }

    /** 五类数据都保留原协议；普通控件改值后仍能完整往返。 */
    public function test_all_five_groups_round_trip_and_null_reference_can_be_selected(): void
    {
        $f = F::create();
        $this->actor($f['actor']);
        $a = F::event($f);
        $b = F::event($f, 'follow', ['time' => ['kind' => 'exact', 'clock' => 'relative_seconds', 'start' => 2, 'end' => 2]]);
        $out = F::output([$a, $b]);
        $out['events'][0]['holder_id'] = null;
        $out['dependencies'][] = ['predecessor_ref' => 'local:observe', 'successor_ref' => 'local:follow', 'relation_type' => 'before'];
        $out['knowledge'][] = ['knower_id' => $f['person']['entity']['id'], 'fact_ref' => 'local:observe', 'acquired_ref' => 'local:follow', 'method' => 'witness'];
        $out['relationships'][] = ['event_ref' => 'local:follow', 'from_entity_id' => $f['person']['entity']['id'], 'to_entity_id' => $f['character'][0]['entity_id'], 'relation_type' => 'ally', 'state' => '同事'];
        $out['foreshadows'][] = ['plan_id' => $f['foreshadow']['id'], 'event_ref' => 'local:observe', 'kind' => 'plant'];
        $page = Livewire::test(ExtractionLedger::class)->set('novel', $f['novel'])->set('version', $f['body']['id'])->call('loadBody')
            ->set('importResponse', json_encode($out))->call('importResponseFields')->assertDontSee('提取资料读取失败')->call('exportResponseFields');
        $actual = json_decode(base64_decode(data_get($page->effects, 'download.content')), true);
        $this->assertSame($out, $actual);
        $map = JsonFields::paths($page->get('trees.extraction'), 'trees.extraction', 'response');
        $before = $page->get('trees');
        $page->set(JsonFields::binding($map['response.events.0.holder_id']), ['bad'])->assertHasErrors('trees')->assertSet('trees', $before);
        $page->set(JsonFields::binding($map['response.events.0.holder_id']), $f['person']['entity']['id'])->call('exportResponseFields');
        $actual = json_decode(base64_decode(data_get($page->effects, 'download.content')), true);
        $this->assertSame($f['person']['entity']['id'], $actual['events'][0]['holder_id']);
        $this->assertSame($out['dependencies'], $actual['dependencies']);
        $this->assertSame($out['knowledge'], $actual['knowledge']);
        $this->assertSame($out['relationships'], $actual['relationships']);
        $this->assertSame($out['foreshadows'], $actual['foreshadows']);
        $this->assertDatabaseCount('extraction_attempts', 0);
        Http::assertNothingSent();
    }

    /** 长中文和标签作为文本输出，分页、空筛选与失败保持可区分。 */
    public function test_overview_long_titles_pagination_and_extraction_read_failure(): void
    {
        $actor = $this->actor();
        $id = '';
        for ($i = 0; $i < 12; $i++) {
            $id = app(NovelIntentService::class)->execute($actor, 'create', null, ['title' => $i === 11 ? str_repeat('长标题', 30).'<script>标题</script>' : 'UI05小说'.$i, 'genre' => '悬疑', 'background' => '档案馆', 'premise_raw' => '许砚检查封记', 'target_chapters' => 100, 'length_policy' => ['min' => 2000, 'max' => 3000]], (string) Str::uuid())['novel']['id'];
        }
        Livewire::test(Overview::class)->set('limit', 10)->assertSee('12 本')->assertSee('&lt;script&gt;标题&lt;/script&gt;', false)->assertDontSeeHtml('<script>标题</script>')->set('page', 2)->assertSee('第 2 / 2 页')->set('status', 'archived')->assertSet('page', 1)->assertSee('没有符合条件的小说');
        DB::connection()->beforeExecuting(function ($sql) {
            if (str_contains($sql, 'extraction_attempts')) {
                throw new \RuntimeException('isolated-extraction-failure');
            }
        });
        Livewire::test(ExtractionLedger::class)->set('novel', $id)->assertSee('提取资料读取失败')->assertDontSee('本书暂无章节正文');
        $this->assertDatabaseCount('generation_runs', 0);
        Http::assertNothingSent();
    }

    /** 伪造其他生成运行ID不能通过提取界面消费其投递意图。 */
    public function test_replay_rejects_non_extraction_run_without_new_request_or_artifact(): void
    {
        $f = F::create();
        $this->actor($f['actor']);
        $run = (string) DB::table('generation_runs')->where('novel_id', $f['novel'])->value('id');
        $requests = DB::table('ai_requests')->count();
        $artifacts = DB::table('generation_artifacts')->count();
        Livewire::test(ExtractionLedger::class)->set('novel', $f['novel'])->set('run', $run)->call('replayPipeline')->assertSet('noticeState', 'error')->assertSee('只能回放本书离线提取运行');
        $this->assertSame($requests, DB::table('ai_requests')->count());
        $this->assertSame($artifacts, DB::table('generation_artifacts')->count());
        $this->assertDatabaseCount('extraction_attempts', 0);
        Http::assertNothingSent();
    }
}
