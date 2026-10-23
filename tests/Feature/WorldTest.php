<?php

namespace Tests\Feature;

use App\Filament\Resources\NovelResource\Pages\WorldLibrary;
use App\Models\CharacterProfile;
use App\Models\EntityAlias;
use App\Models\EntityApproval;
use App\Models\EntityDefinitionVersion;
use App\Models\EntityInitialRelation;
use App\Models\EntityIntroductionPolicy;
use App\Models\Novel;
use App\Models\User;
use App\Models\WorldEntity;
use App\Services\NovelIntentService;
use App\Services\WorldService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class WorldTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private array $book;

    private WorldService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->actor = User::factory()->create(['is_admin' => true]);
        $this->actingAs($this->actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->service = app(WorldService::class);
        $this->book = $this->book();
    }

    private function book(): array
    {
        return app(NovelIntentService::class)->execute($this->actor, 'create', null, ['title' => '铜钥匙', 'genre' => '悬疑', 'background' => '旧档馆中不得凭空复活。许砚寻找铜钥匙，守门人只负责开门。', 'premise_raw' => '铜钥匙留下缺口', 'target_chapters' => 100, 'length_policy' => ['min' => 1000, 'max' => 3000]], (string) Str::uuid());
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['type' => 'character', 'name' => '许砚', 'description' => '档案修复师', 'criticality' => 'core', 'source_version_id' => $this->book['sources'][0]['id'], 'source_quote' => '许砚寻找铜钥匙', 'aliases' => ['许先生'], 'profile' => ['role' => 'primary', 'identity' => '修复师', 'voice' => '短句，先问证据', 'limitations' => '不具备超自然能力', 'abilities' => '修复纸张', 'faction' => '档案馆'], 'introduced_chapter' => 1, 'affects_main_plot' => true, 'relations' => []], $changes);
    }

    private function base(array $data): array
    {
        return $data + ['expected_version' => Novel::find($this->book['novel']['id'])->row_version, 'reason' => '测试设定'];
    }

    private function command(string $command, array $data = []): array
    {
        return $this->service->execute($this->actor, $this->book['novel']['id'], $command, $this->base($data), (string) Str::uuid());
    }

    private function request(string $command, array $data, string $key = 'key')
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/novels/'.$this->book['novel']['id'].'/world/'.$command, $this->base($data));
    }

    private function minor(array $changes = []): array
    {
        return $this->payload(array_replace(['name' => '守门人', 'criticality' => 'minor', 'profile' => ['role' => 'extra', 'identity' => '守门人', 'voice' => '简短回应', 'limitations' => '普通人'], 'affects_main_plot' => false, 'source_quote' => '守门人只负责开门'], $changes));
    }

    private function policy(array $changes = []): array
    {
        return $this->command('policy', array_replace(['enabled' => true, 'start_chapter' => 1, 'end_chapter' => 5, 'max_acceptances' => 1], $changes))['policy'];
    }

    public function test_candidate_approval_and_revision_preserve_approved_history(): void
    {
        $one = $this->command('definition', $this->payload());
        $id = $one['definition']['id'];
        $path = '/api/v1/novels/'.$this->book['novel']['id'].'/world/versions/'.$id;
        $this->getJson($path.'/reference')->assertUnprocessable()->assertJsonPath('error.code', 'UNAPPROVED_ENTITY');
        $this->command('approve', ['definition_version_id' => $id]);
        $this->getJson($path.'/reference')->assertOk()->assertJsonPath('data.definition_approved', true);
        $two = $this->command('definition', $this->payload(['entity_id' => $one['entity']['id'], 'name' => '许砚修订卡']));
        $this->assertSame($id, $two['entity']['approved_version_id']);
        $this->assertNotSame($id, $two['entity']['current_version_id']);
        $this->assertSame('许砚', $this->service->readVersion($this->actor, $this->book['novel']['id'], $id)['definition']['name']);
        $this->assertSame($one['definition']['content_hash'], $this->service->readVersion($this->actor, $this->book['novel']['id'], $id)['content_hash']);
        $this->assertSame('短句，先问证据', CharacterProfile::where('entity_definition_version_id', $id)->first()->profile['voice']);
        $this->assertSame(0, Novel::find($this->book['novel']['id'])->state_version);
        $this->assertFalse(Schema::hasTable('story_events'));
    }

    public function test_initial_relationships_require_approved_exact_version_and_preserve_reference(): void
    {
        $target = $this->command('definition', $this->payload());
        $b = $this->command('definition', $this->minor(['relations' => [['target_definition_version_id' => $target['definition']['id'], 'relation_type' => '同事', 'description' => '初始关系']]]));
        $this->request('approve', ['definition_version_id' => $b['definition']['id']])->assertUnprocessable();
        $this->command('approve', ['definition_version_id' => $target['definition']['id']]);
        $this->command('approve', ['definition_version_id' => $b['definition']['id']]);
        $this->command('definition', $this->payload(['entity_id' => $target['entity']['id'], 'name' => '修订名字']));
        $this->assertSame($target['definition']['id'], EntityInitialRelation::first()->target_definition_version_id);
        $detail = $this->service->detail($this->actor, $this->book['novel']['id'], $target['entity']['id']);
        $this->assertCount(1, $detail['references']);
        $this->assertNull($detail['canonical_state']);
    }

    public function test_rules_conflicts_and_source_quote_validation(): void
    {
        $rule = $this->command('definition', $this->payload(['type' => 'rule', 'name' => '不可复活', 'profile' => [], 'rule' => '死亡不可逆', 'scope' => '全世界', 'conflict' => '与旧规则矛盾']));
        $this->request('approve', ['definition_version_id' => $rule['definition']['id']])->assertConflict();
        $clean = $this->command('definition', $this->payload(['entity_id' => $rule['entity']['id'], 'type' => 'rule', 'name' => '不可复活', 'profile' => [], 'rule' => '死亡不可逆', 'scope' => '全世界', 'conflict' => '']));
        $this->command('approve', ['definition_version_id' => $clean['definition']['id']]);
        $this->assertDatabaseCount('world_rules', 2);
        $this->request('definition', $this->payload(['source_quote' => '不存在的来源']))->assertUnprocessable()->assertJsonPath('error.code', 'EVIDENCE_INVALID');
        $this->request('definition', $this->payload(['current_state' => ['alive' => false]]), 'state')->assertUnprocessable();
    }

    public function test_default_disabled_policy_and_valid_minor_acceptance_quota(): void
    {
        $a = $this->command('definition', $this->minor());
        $this->request('accept-minor', ['definition_version_id' => $a['definition']['id'], 'policy_version_id' => '999'])->assertConflict();
        $p = $this->policy();
        $this->assertSame('minor-v1', $p['schema_version']);
        $this->assertSame(WorldService::POLICY_CONSTRAINTS, $p['constraints']);
        $this->command('accept-minor', ['definition_version_id' => $a['definition']['id'], 'policy_version_id' => $p['id']]);
        $this->assertSame('policy', EntityApproval::first()->mode);
        $this->assertSame($p['id'], EntityApproval::first()->policy_version_id);
        $b = $this->command('definition', $this->minor(['name' => '另一路人']));
        $this->request('accept-minor', ['definition_version_id' => $b['definition']['id'], 'policy_version_id' => $p['id']], 'quota')->assertConflict()->assertJsonPath('error.code', 'POLICY_LIMIT');
        $this->assertDatabaseCount('entity_approvals', 1);
    }

    public function test_policy_blocks_core_abilities_plot_range_and_obsolete_policy(): void
    {
        $p = $this->policy(['max_acceptances' => 10]);
        foreach ([
            $this->payload(), $this->minor(['affects_main_plot' => true]), $this->minor(['introduced_chapter' => 6]),
            $this->minor(['profile' => ['role' => 'extra', 'identity' => '路人', 'voice' => '短句', 'limitations' => '有限', 'abilities' => '瞬移']]),
            $this->minor(['rule' => '可改变时间', 'scope' => '全书']),
        ] as $i => $data) {
            $v = $this->command('definition', $data);
            $this->request('accept-minor', ['definition_version_id' => $v['definition']['id'], 'policy_version_id' => $p['id']], 'block-'.$i)->assertConflict()->assertJsonPath('error.code', 'POLICY_BLOCKED');
        }
        $off = $this->policy(['enabled' => false]);
        $v = $this->command('definition', $this->minor());
        $this->request('accept-minor', ['definition_version_id' => $v['definition']['id'], 'policy_version_id' => $p['id']], 'old')->assertConflict();
        $this->request('accept-minor', ['definition_version_id' => $v['definition']['id'], 'policy_version_id' => $off['id']], 'off')->assertConflict();
        $this->assertDatabaseCount('entity_approvals', 0);
    }

    public function test_alias_merge_creates_candidate_without_rebinding_history(): void
    {
        $a = $this->command('definition', $this->payload());
        $b = $this->command('definition', $this->payload(['name' => '阿砚', 'aliases' => ['小许']]));
        $this->command('approve', ['definition_version_id' => $a['definition']['id']]);
        $merged = $this->command('merge-aliases', ['entity_id' => $a['entity']['id'], 'from_definition_version_id' => $b['definition']['id']]);
        $this->assertSame($b['definition']['id'], $merged['definition']['alias_origin_version_id']);
        $this->assertCount(1, $this->service->detail($this->actor, $this->book['novel']['id'], $b['entity']['id'])['alias_merge_references']);
        $this->assertContains('阿砚', $merged['definition']['definition']['aliases']);
        $this->assertContains('小许', $merged['definition']['definition']['aliases']);
        $this->assertSame($a['definition']['id'], $merged['entity']['approved_version_id']);
        $this->assertDatabaseCount('entities', 2);
        $this->assertNull($merged['definition']['approval']);
        $old = $this->service->readVersion($this->actor, $this->book['novel']['id'], $a['definition']['id']);
        $this->assertSame(['许先生'], $old['definition']['aliases']);
        $this->assertSame($b['definition']['id'], WorldEntity::find($b['entity']['id'])->current_version_id);
    }

    public function test_cross_book_source_relation_alias_and_version_are_denied(): void
    {
        $a = $this->command('definition', $this->payload());
        $other = $this->book();
        $foreign = $this->service->execute($this->actor, $other['novel']['id'], 'definition', array_replace($this->payload(), ['source_version_id' => $other['sources'][0]['id'], 'expected_version' => 1, 'reason' => '外书']), 'foreign');
        $this->request('definition', $this->payload(['source_version_id' => $other['sources'][0]['id']]), 'source')->assertNotFound();
        $this->request('definition', $this->payload(['relations' => [['target_definition_version_id' => $foreign['definition']['id'], 'relation_type' => '同事', 'description' => '外书']]]), 'rel')->assertNotFound();
        $this->request('merge-aliases', ['entity_id' => $a['entity']['id'], 'from_definition_version_id' => $foreign['definition']['id']], 'alias')->assertNotFound();
        $this->request('approve', ['definition_version_id' => $foreign['definition']['id']], 'approve')->assertNotFound();
        $this->getJson('/api/v1/novels/'.$this->book['novel']['id'].'/world/versions/'.$foreign['definition']['id'])->assertNotFound();
    }

    public function test_idempotency_stale_archive_and_retirement(): void
    {
        $data = $this->base($this->payload());
        $id = $this->book['novel']['id'];
        $a = $this->service->execute($this->actor, $id, 'definition', $data, 'repeat');
        $b = $this->service->execute($this->actor, $id, 'definition', $data, 'repeat');
        $this->assertEquals($a, $b);
        $this->assertDatabaseCount('entities', 1);
        $this->withHeader('Idempotency-Key', 'repeat')->postJson('/api/v1/novels/'.$id.'/world/definition', array_replace($data, ['name' => '另一请求']))->assertConflict();
        $this->withHeader('Idempotency-Key', 'stale')->postJson('/api/v1/novels/'.$id.'/world/definition', $data)->assertConflict()->assertJsonPath('error.code', 'STALE_STATE');
        $this->command('approve', ['definition_version_id' => $a['definition']['id']]);
        $this->command('retire', ['entity_id' => $a['entity']['id']]);
        $this->getJson('/api/v1/novels/'.$id.'/world/versions/'.$a['definition']['id'].'/reference')->assertUnprocessable();
        $this->getJson('/api/v1/novels/'.$id.'/world/versions/'.$a['definition']['id'])->assertOk();
        app(NovelIntentService::class)->execute($this->actor, 'archive', $id, ['expected_version' => Novel::find($id)->row_version], 'archive');
        $this->request('definition', $this->payload(), 'archived')->assertConflict();
    }

    public function test_database_blocks_history_update(): void
    {
        $a = $this->command('definition', $this->payload());
        $this->expectException(QueryException::class);
        DB::table('entity_definition_versions')->where('id', $a['definition']['id'])->update(['source_quote' => '改写']);
    }

    public function test_database_blocks_alias_insert_after_sealing(): void
    {
        $a = $this->command('definition', $this->payload());
        $this->expectException(QueryException::class);
        EntityAlias::create(['novel_id' => $this->book['novel']['id'], 'entity_definition_version_id' => $a['definition']['id'], 'alias' => '后补', 'normalized_alias' => '后补']);
    }

    public function test_database_blocks_entity_deletion(): void
    {
        $a = $this->command('definition', $this->payload());
        $this->expectException(QueryException::class);
        WorldEntity::find($a['entity']['id'])->delete();
    }

    public function test_database_blocks_policy_mutation(): void
    {
        $p = $this->policy();
        $this->expectException(QueryException::class);
        EntityIntroductionPolicy::find($p['id'])->update(['max_acceptances' => 100]);
    }

    public function test_database_blocks_direct_core_policy_approval(): void
    {
        $p = $this->policy();
        $a = $this->command('definition', $this->payload());
        $this->expectException(QueryException::class);
        EntityApproval::create(['novel_id' => $this->book['novel']['id'], 'entity_definition_version_id' => $a['definition']['id'], 'policy_version_id' => $p['id'], 'mode' => 'policy', 'reason' => '绕过服务', 'actor_id' => $this->actor->id, 'created_at' => now()]);
    }

    public function test_database_blocks_cross_book_definition_parent(): void
    {
        $a = $this->command('definition', $this->payload());
        $other = $this->book();
        $row = EntityDefinitionVersion::find($a['definition']['id'])->getAttributes();
        unset($row['id']);
        $row['novel_id'] = $other['novel']['id'];
        $row['version'] = 2;
        $this->expectException(QueryException::class);
        DB::table('entity_definition_versions')->insert($row);
    }

    public function test_database_rejects_approval_pointer_without_approval(): void
    {
        $a = $this->command('definition', $this->payload());
        $this->expectException(QueryException::class);
        WorldEntity::find($a['entity']['id'])->update(['approved_version_id' => $a['definition']['id']]);
    }

    public function test_database_preserves_approval_audit(): void
    {
        $a = $this->command('definition', $this->payload());
        $this->command('approve', ['definition_version_id' => $a['definition']['id']]);
        $this->expectException(QueryException::class);
        EntityApproval::first()->delete();
    }

    public function test_database_preserves_profile_history(): void
    {
        $a = $this->command('definition', $this->payload());
        $this->expectException(QueryException::class);
        CharacterProfile::first()->update(['profile' => []]);
    }

    public function test_database_rejects_cross_book_initial_relation(): void
    {
        $a = $this->command('definition', $this->payload());
        $other = $this->book();
        $b = $this->service->execute($this->actor, $other['novel']['id'], 'definition', array_replace($this->payload(), ['source_version_id' => $other['sources'][0]['id'], 'reason' => '另一书', 'expected_version' => 1]), 'other');
        $raw = EntityDefinitionVersion::find($a['definition']['id'])->getAttributes();
        unset($raw['id']);
        $raw['version'] = 2;
        $raw['status'] = 'building';
        $id = DB::table('entity_definition_versions')->insertGetId($raw);
        $this->expectException(QueryException::class);
        EntityInitialRelation::create(['novel_id' => $this->book['novel']['id'], 'entity_definition_version_id' => $id, 'target_definition_version_id' => $b['definition']['id'], 'relation_type' => '同事', 'description' => '跨书']);
    }

    public function test_stale_definition_and_invalid_type_change_are_rejected(): void
    {
        $a = $this->command('definition', $this->payload());
        $this->command('definition', $this->payload(['entity_id' => $a['entity']['id'], 'name' => '新版']));
        $this->request('approve', ['definition_version_id' => $a['definition']['id']], 'old-version')->assertConflict()->assertJsonPath('error.code', 'STALE_DEFINITION');
        $this->request('definition', $this->payload(['entity_id' => $a['entity']['id'], 'type' => 'location', 'profile' => []]), 'type')->assertConflict();
    }

    public function test_filament_non_character_and_archived_readonly(): void
    {
        $page = Livewire::test(WorldLibrary::class, ['record' => $this->book['novel']['id']]);
        $page->mountAction('definition')->fillForm($this->base($this->payload(['type' => 'rule', 'name' => '死亡规则', 'profile' => [], 'rule' => '不得无证据复活', 'scope' => '全书'])) + ['command_key' => 'ui-rule'])->callMountedAction()->assertHasNoActionErrors();
        $this->assertDatabaseCount('world_rules', 1);
        $page->set('type', 'character');
        $this->assertCount(0, $page->instance()->listing()['items']);
        app(NovelIntentService::class)->execute($this->actor, 'archive', $this->book['novel']['id'], ['expected_version' => Novel::find($this->book['novel']['id'])->row_version], 'archive');
        Livewire::test(WorldLibrary::class, ['record' => $this->book['novel']['id']])->assertActionHidden('definition')->assertActionHidden('approve')->assertActionHidden('policy');
    }

    public function test_failure_rolls_back_entity_version_and_command(): void
    {
        $before = Novel::find($this->book['novel']['id'])->row_version;
        CharacterProfile::creating(fn () => throw new \RuntimeException('Injected definition failure'));
        try {
            $this->command('definition', $this->payload());
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected definition failure', $e->getMessage());
        } finally {
            CharacterProfile::flushEventListeners();
        }
        $this->assertDatabaseCount('entities', 0);
        $this->assertDatabaseCount('entity_definition_versions', 0);
        $this->assertSame($before, Novel::find($this->book['novel']['id'])->row_version);
        $this->assertSame(1, $this->command('definition', $this->payload())['definition']['version']);
    }

    public function test_list_filter_cursor_and_authentication(): void
    {
        $a = $this->command('definition', $this->payload());
        $this->command('definition', $this->minor());
        $path = '/api/v1/novels/'.$this->book['novel']['id'].'/world';
        $this->getJson($path.'?role=primary&faction='.urlencode('档案馆'))->assertOk()->assertJsonCount(1, 'data.items');
        $this->getJson($path.'?q='.urlencode('许先生'))->assertOk()->assertJsonCount(2, 'data.items');
        $first = $this->getJson($path.'?limit=1')->assertOk()->json('data');
        $this->getJson($path.'?limit=1&cursor='.urlencode($first['next_cursor']))->assertOk()->assertJsonCount(1, 'data.items');
        $this->getJson($path.'?cursor=invalid')->assertUnprocessable();
        $this->getJson($path.'/versions/9999999999999999999')->assertNotFound();
        $this->command('approve', ['definition_version_id' => $a['definition']['id']]);
        $this->getJson($path.'?status=approved')->assertOk()->assertJsonCount(1, 'data.items');
        $this->actingAs(User::factory()->create())->getJson($path)->assertForbidden();
        auth()->logout();
        $this->getJson($path)->assertUnauthorized();
    }

    public function test_filament_actual_save_approve_history_and_policy(): void
    {
        $page = Livewire::test(WorldLibrary::class, ['record' => $this->book['novel']['id']]);
        $page->mountAction('definition')->fillForm($this->base($this->payload()) + ['command_key' => 'ui-create'])->callMountedAction()->assertHasNoActionErrors();
        $e = WorldEntity::firstOrFail();
        $page->mountAction(TestAction::make('approve')->arguments(['definition' => $e->current_version_id]))->fillForm($this->base(['definition_version_id' => $e->current_version_id]) + ['command_key' => 'ui-approve'])->callMountedAction()->assertHasNoActionErrors();
        $this->assertNotNull($e->fresh()->approved_version_id);
        $page->mountAction(TestAction::make('definition')->arguments(['entity' => $e->id]))->fillForm($this->base($this->payload(['entity_id' => $e->id, 'name' => '修订人物卡'])) + ['command_key' => 'ui-revise'])->callMountedAction()->assertHasNoActionErrors();
        $this->assertDatabaseCount('entity_definition_versions', 2);
        $page->set('entityId', $e->id)->set('versionId', $e->current_version_id)->assertSee('短句，先问证据');
        $this->assertSame('许砚', $page->instance()->selected()['definition']['name']);
        $page->mountAction('policy')->fillForm($this->base(['enabled' => false, 'start_chapter' => 1, 'end_chapter' => 10, 'max_acceptances' => 2]) + ['command_key' => 'ui-policy'])->callMountedAction()->assertHasNoActionErrors();
        $this->assertDatabaseCount('entity_introduction_policies', 1);
        $this->get('/admin/novels/'.$this->book['novel']['id'].'/world')->assertOk()->assertSee('修订人物卡');
    }
}
