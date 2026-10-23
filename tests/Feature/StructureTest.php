<?php

namespace Tests\Feature;

use App\Filament\Resources\NovelResource\Pages\Structure;
use App\Models\Novel;
use App\Models\OutlineVersion;
use App\Models\PlanNode;
use App\Models\StoryArc;
use App\Models\User;
use App\Models\Volume;
use App\Services\NovelIntentService;
use App\Services\StructureService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class StructureTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private array $book;

    private StructureService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->actor = User::factory()->create();
        $this->actor->is_admin = true;
        $this->actor->save();
        $this->actingAs($this->actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->book = $this->createBook();
        $this->service = app(StructureService::class);
    }

    private function createBook(): array
    {
        return app(NovelIntentService::class)->execute($this->actor, 'create', null, ['title' => '铜钥匙旧档', 'genre' => '悬疑', 'background' => '旧档馆', 'premise_raw' => '缺口铜钥匙必须保留', 'target_chapters' => 100, 'length_policy' => ['min' => 2500, 'max' => 3500]], (string) Str::uuid());
    }

    private function base(array $data = []): array
    {
        return $data + ['expected_version' => Novel::find($this->book['novel']['id'])->row_version, 'reason' => '测试规划'];
    }

    private function command(string $command, array $data = []): array
    {
        return $this->service->execute($this->actor, $this->book['novel']['id'], $command, $this->base($data), (string) Str::uuid());
    }

    private function payload(string $type, ?string $parent = null): array
    {
        return ['type' => $type, 'parent_id' => $parent, 'source_version_id' => $this->book['sources'][0]['id'], 'sequence' => 1, 'title' => ['volume' => '第一卷：缺口', 'story_arc' => '馆中疑案', 'chapter' => '铜钥匙缺口对上旧封记', 'scene' => '雨夜取出封记'][$type], 'objective' => '保留具体证据', 'summary' => '原始细节不能泛化', 'status' => 'detailed', 'details' => ['transition_in' => '接续上场遗留铜钥匙', 'transition_out' => '留下印泥气味', 'pov' => '许砚', 'target_words' => 2800]] + (in_array($type, ['volume', 'story_arc']) ? ['start_chapter' => 1, 'end_chapter' => 20] : []);
    }

    private function tree(): array
    {
        $v = $this->command('node', $this->payload('volume'))['node'];
        $a = $this->command('node', $this->payload('story_arc', $v['id']))['node'];
        $c = $this->command('node', $this->payload('chapter', $a['id']))['node'];
        $s = $this->command('node', $this->payload('scene', $c['id']))['node'];

        return compact('v', 'a', 'c', 's');
    }

    private function sendCommand(string $command, array $data, string $key = 'key')
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/novels/'.$this->book['novel']['id'].'/structure/'.$command, $data);
    }

    public function test_tree_snapshot_retains_titles_transitions_and_historical_parent(): void
    {
        $tree = $this->tree();
        $one = $this->command('snapshot')['version'];
        $this->assertCount(4, $one['nodes']);
        $this->assertSame('sealed', $one['status']);
        $this->assertFalse($one['generation_eligible']);
        $hash = $one['content_hash'];
        $this->command('node', array_replace($this->payload('chapter', $tree['a']['id']), ['node_id' => $tree['c']['id'], 'title' => '新的具体章名']));
        $two = $this->command('snapshot')['version'];
        $this->assertSame(2, $two['version']);
        $old = $this->service->version($this->actor, $this->book['novel']['id'], $one['id']);
        $this->assertSame($hash, $old['content_hash']);
        $this->assertSame('铜钥匙缺口对上旧封记', $old['content']['nodes'][2]['title']);
        $this->assertSame('接续上场遗留铜钥匙', $old['content']['nodes'][3]['details']['transition_in']);
        $this->assertSame($old['nodes'][2]['id'], $old['nodes'][3]['parent_plan_node_id']);
        $this->assertSame($tree['s']['id'], $old['nodes'][3]['scene_id']);
        $this->assertNotSame($old['nodes'][3]['id'], $two['nodes'][3]['id']);
        $this->assertFalse(Schema::hasTable('generation_runs'));
        $this->assertTrue(Schema::hasTable('blueprint_versions'));
    }

    public function test_overlap_parent_shrink_and_duplicate_sequences_fail_atomically(): void
    {
        $t = $this->tree();
        $version = Novel::find($this->book['novel']['id'])->row_version;
        $this->sendCommand('node', $this->base(array_replace($this->payload('volume'), ['sequence' => 2, 'start_chapter' => 15, 'end_chapter' => 30])), 'overlap')->assertConflict();
        $this->sendCommand('node', $this->base(array_replace($this->payload('volume'), ['node_id' => $t['v']['id'], 'end_chapter' => 10])), 'shrink')->assertConflict();
        $this->sendCommand('node', $this->base($this->payload('chapter', $t['a']['id'])), 'duplicate')->assertConflict();
        $this->sendCommand('node', $this->base(array_replace($this->payload('chapter', $t['a']['id']), ['sequence' => 21])), 'outside')->assertConflict();
        $this->sendCommand('node', $this->base($this->payload('scene', $t['c']['id'])), 'scene-duplicate')->assertConflict();
        $this->assertSame($version, Novel::find($this->book['novel']['id'])->row_version);
        $this->assertSame(1, Volume::count());
    }

    public function test_cross_novel_parent_source_and_thread_are_denied(): void
    {
        $t = $this->tree();
        $other = $this->createBook();
        $foreign = $this->service->execute($this->actor, $other['novel']['id'], 'node', array_replace($this->payload('volume'), ['source_version_id' => $other['sources'][0]['id'], 'expected_version' => 1, 'reason' => '另一书']), 'other')['node'];
        $this->sendCommand('node', $this->base($this->payload('story_arc', $foreign['id'])), 'foreign-parent')->assertNotFound();
        $this->sendCommand('node', $this->base(array_replace($this->payload('scene', $t['c']['id']), ['sequence' => 2, 'source_version_id' => $other['sources'][0]['id']])), 'foreign-source')->assertNotFound();
        $thread = $this->service->execute($this->actor, $other['novel']['id'], 'thread', ['title' => '外书线索', 'objective' => '不得关联', 'reason' => '测试', 'expected_version' => 2], 'foreign-thread')['thread'];
        $this->sendCommand('node', $this->base(array_replace($this->payload('story_arc', $t['v']['id']), ['node_id' => $t['a']['id'], 'thread_ids' => [$thread['id']]])), 'foreign-link')->assertNotFound();
        $this->assertDatabaseCount('arc_thread', 0);
    }

    public function test_global_thread_links_across_volumes_preserve_one_arc_parent(): void
    {
        $t = $this->tree();
        $thread = $this->command('thread', ['title' => '旧档主线', 'objective' => '揭露封记被调换'])['thread'];
        $this->command('node', array_replace($this->payload('story_arc', $t['v']['id']), ['node_id' => $t['a']['id'], 'thread_ids' => [$thread['id']]]));
        $v2 = $this->command('node', array_replace($this->payload('volume'), ['sequence' => 2, 'start_chapter' => 21, 'end_chapter' => 40]))['node'];
        $a2 = $this->command('node', array_replace($this->payload('story_arc', $v2['id']), ['start_chapter' => 21, 'end_chapter' => 40, 'thread_ids' => [$thread['id']]]))['node'];
        $one = $this->command('snapshot')['version'];
        $this->assertDatabaseCount('arc_thread', 2);
        $this->assertSame($t['v']['id'], StoryArc::find($t['a']['id'])->parent_id);
        $this->assertSame($v2['id'], StoryArc::find($a2['id'])->parent_id);
        $this->command('thread', ['thread_id' => $thread['id'], 'title' => '更新说明', 'objective' => '新说明']);
        $this->assertSame('旧档主线', $this->service->version($this->actor, $this->book['novel']['id'], $one['id'])['content']['threads'][0]['title']);
    }

    public function test_idempotency_stale_versions_and_archived_book(): void
    {
        $payload = $this->base($this->payload('volume'));
        $a = $this->sendCommand('node', $payload, 'once')->assertOk()->json('data');
        $this->assertEquals($a, $this->sendCommand('node', $payload, 'once')->assertOk()->json('data'));
        $this->sendCommand('node', array_replace($payload, ['title' => '不同载荷']), 'once')->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->sendCommand('node', $payload, 'other-key')->assertConflict()->assertJsonPath('error.code', 'STALE_STATE');
        app(NovelIntentService::class)->execute($this->actor, 'archive', $this->book['novel']['id'], ['expected_version' => 2], 'archive');
        $this->sendCommand('snapshot', $this->base(), 'sealed-archived')->assertConflict();
        $this->assertDatabaseCount('outline_versions', 0);
    }

    public function test_database_rejects_cross_novel_parent_without_service(): void
    {
        $t = $this->tree();
        $other = $this->createBook();
        $row = StoryArc::find($t['a']['id'])->getAttributes();
        unset($row['id']);
        $row['novel_id'] = $other['novel']['id'];
        $row['source_version_id'] = $other['sources'][0]['id'];
        $this->expectException(QueryException::class);
        DB::table('story_arcs')->insert($row);
    }

    public function test_database_rejects_modifying_sealed_version(): void
    {
        $this->tree();
        $v = $this->command('snapshot')['version'];
        $this->expectException(QueryException::class);
        DB::table('outline_versions')->where('id', $v['id'])->update(['reason' => '改写旧历史']);
    }

    public function test_database_rejects_modifying_version_node(): void
    {
        $this->tree();
        $v = $this->command('snapshot')['version'];
        $this->expectException(QueryException::class);
        DB::table('plan_nodes')->where('id', $v['nodes'][0]['id'])->update(['details' => '{}']);
    }

    public function test_sealed_version_cannot_receive_more_nodes(): void
    {
        $this->tree();
        $v = $this->command('snapshot')['version'];
        $row = PlanNode::find($v['nodes'][0]['id'])->getAttributes();
        unset($row['id']);
        $this->expectException(QueryException::class);
        DB::table('plan_nodes')->insert($row);
    }

    public function test_database_rejects_duplicate_version_numbers(): void
    {
        $this->tree();
        $v = $this->command('snapshot')['version'];
        $row = OutlineVersion::find($v['id'])->getAttributes();
        unset($row['id']);
        $this->expectException(QueryException::class);
        DB::table('outline_versions')->insert($row);
    }

    public function test_api_version_lookup_pagination_and_access(): void
    {
        $this->tree();
        $v = $this->command('snapshot')['version'];
        $this->command('snapshot');
        $path = '/api/v1/novels/'.$this->book['novel']['id'].'/structure';
        $this->getJson($path.'/versions/'.$v['id'])->assertOk()->assertJsonPath('data.content_hash', $v['content_hash']);
        $page = $this->getJson($path.'/versions?limit=1')->assertOk()->json('data');
        $this->assertCount(1, $page['items']);
        $this->getJson($path.'/versions?limit=1&cursor='.urlencode($page['next_cursor']))->assertOk()->assertJsonCount(1, 'data.items');
        $this->getJson($path.'/versions?cursor=invalid')->assertUnprocessable();
        $cursor = (new Cursor(['id' => '9999999999999999999']))->encode();
        $this->getJson($path.'/versions?cursor='.urlencode($cursor))->assertUnprocessable();
        $this->getJson($path.'/versions/9999999999999999999')->assertNotFound();
        $other = $this->createBook();
        $this->getJson('/api/v1/novels/'.$other['novel']['id'].'/structure/versions/'.$v['id'])->assertNotFound();
        $this->actingAs(User::factory()->create())->getJson($path)->assertForbidden();
        auth()->logout();
        $this->getJson($path)->assertUnauthorized();
    }

    public function test_target_reduction_and_forged_frozen_status_are_rejected(): void
    {
        $this->tree();
        $novel = Novel::find($this->book['novel']['id']);
        $input = array_replace($novel->settings, ['target_chapters' => 10, 'reason' => '缩短', 'expected_version' => $novel->row_version]);
        $this->withHeader('Idempotency-Key', 'shorten')->postJson('/api/v1/novels/'.$novel->id.'/source', $input)->assertConflict()->assertJsonPath('error.code', 'STRUCTURE_CONFLICT');
        $this->assertSame(100, $novel->fresh()->target_chapters);
        $this->sendCommand('node', $this->base(array_replace($this->payload('volume'), ['status' => 'frozen'])), 'fake-frozen')->assertUnprocessable();
    }

    public function test_database_blocks_changing_novel_target_below_volume(): void
    {
        $this->tree();
        $this->expectException(QueryException::class);
        DB::table('novels')->where('id', $this->book['novel']['id'])->update(['target_chapters' => 10]);
    }

    public function test_structure_snapshot_failure_rolls_back_all_rows(): void
    {
        $this->tree();
        $before = Novel::find($this->book['novel']['id'])->row_version;
        PlanNode::creating(function ($node) {
            if ($node->node_type === 'chapter') {
                throw new \RuntimeException('Injected snapshot failure');
            }
        });
        try {
            $this->command('snapshot');
            $this->fail('Expected injected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected snapshot failure', $e->getMessage());
        } finally {
            PlanNode::flushEventListeners();
        }
        $this->assertDatabaseCount('outline_versions', 0);
        $this->assertDatabaseCount('plan_nodes', 0);
        $this->assertSame($before, Novel::find($this->book['novel']['id'])->row_version);
        $this->assertSame(1, $this->command('snapshot')['version']['version']);
    }

    public function test_page_filters_parent_and_compares_history(): void
    {
        $t = $this->tree();
        $v = $this->command('snapshot')['version'];
        $this->command('node', array_replace($this->payload('scene', $t['c']['id']), ['node_id' => $t['s']['id'], 'title' => '新衔接场景']));
        $page = Livewire::test(Structure::class, ['record' => $this->book['novel']['id']]);
        $page->set('type', 'scene')->set('parent', 'chapter:'.$t['c']['id']);
        $this->assertCount(1, $page->instance()->rows()->items());
        $page->set('type', 'chapter');
        $this->assertSame('', $page->instance()->parent);
        $page->set('compareId', $v['id']);
        $diff = $page->instance()->differences();
        $this->assertCount(1, $diff);
        $this->assertSame('新衔接场景', $diff[0]['after']['title']);
        $page->set('versionId', $v['id']);
        $this->assertCount(0, $page->instance()->differences());
    }

    public function test_filament_actual_node_edit_snapshot_readonly_and_filter(): void
    {
        $page = Livewire::test(Structure::class, ['record' => $this->book['novel']['id']]);
        $page->mountAction('node')->fillForm($this->base($this->payload('volume')) + ['command_key' => 'ui-volume'])->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame(1, Volume::count());
        $volume = Volume::first();
        $page = Livewire::test(Structure::class, ['record' => $this->book['novel']['id']]);
        $page->mountAction(TestAction::make('node')->arguments(['node_type' => 'volume', 'node' => $volume->id]))->fillForm($this->base(array_replace($this->payload('volume'), ['node_id' => $volume->id, 'title' => '修订卷名'])) + ['command_key' => 'ui-edit'])->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('修订卷名', $volume->fresh()->title);
        $page->mountAction('snapshot')->fillForm($this->base() + ['command_key' => 'ui-snapshot'])->callMountedAction()->assertHasNoActionErrors();
        $v = OutlineVersion::firstOrFail();
        $this->assertSame('sealed', $v->status);
        $this->get('/admin/novels/'.$this->book['novel']['id'].'/structure')->assertOk()->assertSee('修订卷名');
        $page->set('versionId', $v->id)->assertActionHidden('node')->assertActionHidden('snapshot');
        $page->set('type', 'scene');
        $this->assertCount(0, $page->instance()->rows()->items());
    }
}
