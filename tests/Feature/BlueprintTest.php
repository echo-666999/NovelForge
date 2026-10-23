<?php

namespace Tests\Feature;

use App\Filament\Resources\NovelResource\Pages\Blueprints;
use App\Models\BlueprintApproval;
use App\Models\BlueprintThreadVersion;
use App\Models\BlueprintValidation;
use App\Models\BlueprintVersion;
use App\Models\EndingContract;
use App\Models\Novel;
use App\Models\User;
use App\Services\BlueprintService;
use App\Services\NovelIntentService;
use App\Services\StructureService;
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
use Tests\Support\BlueprintFixture;
use Tests\TestCase;

class BlueprintTest extends TestCase
{
    use RefreshDatabase;

    private array $f;

    private BlueprintService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->f = BlueprintFixture::create();
        $this->actingAs($this->f['actor']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->service = app(BlueprintService::class);
    }

    private function base(array $d = []): array
    {
        return $d + ['expected_version' => Novel::find($this->f['book']['novel']['id'])->row_version, 'reason' => '测试蓝图'];
    }

    private function command(string $command, array $data = []): array
    {
        return $this->service->execute($this->f['actor'], $this->f['book']['novel']['id'], $command, $this->base($data), (string) Str::uuid());
    }

    private function save(?array $payload = null): array
    {
        return $this->command('save', $payload ?? $this->f['payload'])['blueprint'];
    }

    private function check(array $v): array
    {
        return $this->command('validate', ['blueprint_version_id' => $v['id']])['validation'];
    }

    private function approval(array $v, array $c): array
    {
        return ['blueprint_version_id' => $v['id'], 'validation_id' => $c['id'], 'report_hash' => $c['report_hash'], 'acknowledge_impact' => true];
    }

    private function postCommand(string $command, array $d, string $key = 'key')
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/novels/'.$this->f['book']['novel']['id'].'/blueprints/'.$command, $this->base($d));
    }

    public function test_complete_blueprint_checks_and_approves_without_enabling_generation(): void
    {
        $v = $this->save();
        $c = $this->check($v);
        $this->assertTrue($c['report']['passed'], json_encode($c['report']['blockers']));
        $this->assertSame((string) $this->f['actor']->id, $c['actor_id']);
        $this->assertSame('测试蓝图', $c['reason']);
        $this->assertSame('blueprint-check-v1', $c['report']['validator_schema']);
        $this->command('approve', $this->approval($v, $c));
        $read = $this->service->read($this->f['actor'], $v['novel_id'], $v['id']);
        $this->assertSame('approved', $read['display_status']);
        $this->assertCount(1, $this->service->listing($this->f['actor'], $v['novel_id'], ['status' => 'approved'])['items']);
        $this->assertCount(0, $this->service->listing($this->f['actor'], $v['novel_id'], ['status' => 'draft'])['items']);
        $this->assertFalse($read['generation_eligible']);
        $this->assertSame('planning', Novel::find($v['novel_id'])->status);
        $this->assertSame(0, Novel::find($v['novel_id'])->state_version);
        $this->assertDatabaseCount('ending_contracts', 1);
        $this->assertDatabaseCount('blueprint_thread_versions', 1);
        $this->assertFalse(Schema::hasTable('canonical_commits'));
    }

    public function test_ending_change_creates_new_contract_and_preserves_old_approval(): void
    {
        $one = $this->save();
        $c = $this->check($one);
        $this->command('approve', $this->approval($one, $c));
        $p = $this->f['payload'];
        $p['ending']['outcomes'][0]['outcome'] = '牺牲自身保护档案';
        $p['ending']['core_reversal'] = '另一层封记谜底';
        $two = $this->save($p);
        $this->assertSame(2, $two['ending_contract']['version']);
        $this->assertTrue($two['impact']['ending_changed']);
        $this->assertCount(10, $two['impact']['chapters']);
        $this->assertNull($two['impact']['canonical_text_impact']);
        $this->assertNull($two['approval']);
        $old = $this->service->read($this->f['actor'], $one['novel_id'], $one['id']);
        $this->assertSame($one['content_hash'], $old['content_hash']);
        $this->assertSame('活着承担后果', $old['content']['ending']['outcomes'][0]['outcome']);
        $check = $this->check($two);
        $this->command('approve', $this->approval($two, $check));
        $this->assertSame('superseded', $this->service->read($this->f['actor'], $one['novel_id'], $one['id'])['display_status']);
    }

    public function test_missing_coverage_main_resolution_and_author_checks_block_approval(): void
    {
        $p = $this->f['payload'];
        $p['coverages'] = [];
        $p['threads'][0]['resolution'] = '';
        $p['ending']['forbidden_solutions'] = '';
        $p['author_checks']['world_consistency'] = false;
        $p['body']['conflicts'] = '世界规则冲突';
        $v = $this->save($p);
        $c = $this->check($v);
        $codes = array_column($c['report']['blockers'], 'code');
        foreach (['REQUIREMENT_UNCOVERED', 'MAIN_RESOLUTION_REQUIRED', 'ENDING_INCOMPLETE', 'AUTHOR_REVIEW_REQUIRED', 'CONFLICT_UNRESOLVED'] as $code) {
            $this->assertContains($code, $codes);
        }
        $this->postCommand('approve', $this->approval($v, $c))->assertUnprocessable()->assertJsonPath('error.code', 'BLUEPRINT_BLOCKED');
        $this->assertDatabaseCount('blueprint_approvals', 0);
    }

    public function test_forged_coverage_quote_and_non_bound_ending_entity_are_rejected(): void
    {
        $p = $this->f['payload'];
        $p['coverages'][0]['quote'] = '不在字段中的证据';
        $this->postCommand('save', $p)->assertUnprocessable()->assertJsonPath('error.code', 'EVIDENCE_INVALID');
        $p = $this->f['payload'];
        $p['ending']['outcomes'][0]['entity_definition_version_id'] = '999999';
        $this->postCommand('save', $p, 'outcome')->assertUnprocessable();
        $this->assertDatabaseCount('blueprint_versions', 0);
    }

    public function test_changed_requirement_invalidates_previous_passed_validation(): void
    {
        $v = $this->save();
        $c = $this->check($v);
        $id = $v['novel_id'];
        $req = app(NovelIntentService::class)->requirements(Novel::find($id))[0];
        app(NovelIntentService::class)->execute($this->f['actor'], 'requirement', $id, array_replace($req, ['decision_status' => 'unresolved', 'author_decision' => '', 'expected_version' => Novel::find($id)->row_version, 'reason' => '重新考虑']), 'changed');
        $this->postCommand('approve', $this->approval($v, $c))->assertConflict()->assertJsonPath('error.code', 'VALIDATION_STALE');
        $next = $this->check($v);
        $this->assertContains('AUTHOR_DECISION_REQUIRED', array_column($next['report']['blockers'], 'code'));
        $this->assertDatabaseCount('blueprint_approvals', 0);
    }

    public function test_retired_entity_blocks_approval_and_history_stays_readable(): void
    {
        $v = $this->save();
        $c = $this->check($v);
        app(WorldService::class)->execute($this->f['actor'], $v['novel_id'], 'retire', $this->base(['entity_id' => $this->f['person']['entity']['id']]), 'retire');
        $this->postCommand('approve', $this->approval($v, $c))->assertConflict();
        $check = $this->check($v);
        $this->assertContains('UNAPPROVED_ENTITY', array_column($check['report']['blockers'], 'code'));
        $this->assertSame($v['content_hash'], $this->service->read($this->f['actor'], $v['novel_id'], $v['id'])['content_hash']);
    }

    public function test_stale_structure_and_invalid_ending_window_block(): void
    {
        $p = $this->f['payload'];
        $p['ending']['target_chapters'] = 11;
        $v = $this->save($p);
        $check = $this->check($v);
        $this->assertContains('ENDING_WINDOW_INVALID', array_column($check['report']['blockers'], 'code'));
        app(StructureService::class)->execute($this->f['actor'], $v['novel_id'], 'thread', $this->base(['thread_id' => $this->f['thread']['id'], 'title' => '新主线说明', 'objective' => '新目标']), 'change');
        $check = $this->check($v);
        $this->assertContains('OUTLINE_STALE', array_column($check['report']['blockers'], 'code'));
    }

    public function test_cross_novel_references_and_reads_are_rejected(): void
    {
        $other = BlueprintFixture::create($this->f['actor']);
        $p = $this->f['payload'];
        $p['outline_version_id'] = $other['outline']['id'];
        $this->postCommand('save', $p, 'outline')->assertNotFound();
        $p = $this->f['payload'];
        $p['entity_version_ids'] = [$other['person']['definition']['id']];
        $this->postCommand('save', $p, 'entity')->assertNotFound();
        $p = $this->f['payload'];
        $p['threads'][0]['thread_id'] = $other['thread']['id'];
        $this->postCommand('save', $p, 'thread')->assertNotFound();
        $v = $this->save();
        $this->getJson('/api/v1/novels/'.$other['book']['novel']['id'].'/blueprints/'.$v['id'])->assertNotFound();
    }

    public function test_idempotency_and_stale_optimistic_version(): void
    {
        $d = $this->base($this->f['payload']);
        $id = $this->f['book']['novel']['id'];
        $a = $this->service->execute($this->f['actor'], $id, 'save', $d, 'same');
        $b = $this->service->execute($this->f['actor'], $id, 'save', $d, 'same');
        $this->assertEquals($a, $b);
        $this->assertDatabaseCount('blueprint_versions', 1);
        $this->postCommand('save', $this->f['payload'], 'same')->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->withHeader('Idempotency-Key', 'stale')->postJson('/api/v1/novels/'.$id.'/blueprints/save', $d)->assertConflict()->assertJsonPath('error.code', 'STALE_STATE');
    }

    public function test_database_rejects_blueprint_mutation(): void
    {
        $v = $this->save();
        $this->expectException(QueryException::class);
        BlueprintVersion::find($v['id'])->update(['reason' => '改写']);
    }

    public function test_database_rejects_ending_mutation(): void
    {
        $v = $this->save();
        $this->expectException(QueryException::class);
        EndingContract::first()->update(['content' => []]);
    }

    public function test_database_rejects_unvalidated_approval(): void
    {
        $v = $this->save();
        $this->expectException(QueryException::class);
        BlueprintApproval::create(['novel_id' => $v['novel_id'], 'blueprint_version_id' => $v['id'], 'validation_id' => 999999, 'report_hash' => str_repeat('a', 64), 'reason' => '绕过检查', 'actor_id' => $this->f['actor']->id, 'created_at' => now()]);
    }

    public function test_failed_materialization_rolls_back_blueprint_contract_and_command(): void
    {
        $before = Novel::find($this->f['book']['novel']['id'])->row_version;
        BlueprintThreadVersion::creating(fn () => throw new \RuntimeException('Injected blueprint failure'));
        try {
            $this->save();
            $this->fail('Expected fault');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected blueprint failure', $e->getMessage());
        } finally {
            BlueprintThreadVersion::flushEventListeners();
        }
        $this->assertDatabaseCount('blueprint_versions', 0);
        $this->assertDatabaseCount('ending_contracts', 0);
        $this->assertSame($before, Novel::find($this->f['book']['novel']['id'])->row_version);
        $this->assertSame(1, $this->save()['version']);
    }

    public function test_api_pagination_access_and_archived_state(): void
    {
        $v = $this->save();
        $this->save();
        $path = '/api/v1/novels/'.$v['novel_id'].'/blueprints';
        $page = $this->getJson($path.'?limit=1')->assertOk()->json('data');
        $this->getJson($path.'?limit=1&cursor='.urlencode($page['next_cursor']))->assertOk()->assertJsonCount(1, 'data.items');
        $this->getJson($path.'?cursor=bad')->assertUnprocessable();
        $this->getJson($path.'/9999999999999999999')->assertNotFound();
        app(NovelIntentService::class)->execute($this->f['actor'], 'archive', $v['novel_id'], ['expected_version' => Novel::find($v['novel_id'])->row_version], 'archive');
        $this->postCommand('save', $this->f['payload'])->assertConflict();
        $this->actingAs(User::factory()->create())->getJson($path)->assertForbidden();
        auth()->logout();
        $this->getJson($path)->assertUnauthorized();
    }

    public function test_first_ten_structure_missing_scene_blocks(): void
    {
        $this->f = BlueprintFixture::create($this->f['actor'], 9);
        $v = $this->save();
        $check = $this->check($v);
        $this->assertContains('EARLY_SCENE_MISSING', array_column($check['report']['blockers'], 'code'));
    }

    public function test_database_rejects_cross_book_outline_without_service(): void
    {
        $v = $this->save();
        $other = BlueprintFixture::create($this->f['actor']);
        $row = BlueprintVersion::find($v['id'])->getAttributes();
        unset($row['id']);
        $row['version'] = 2;
        $row['outline_version_id'] = $other['outline']['id'];
        $this->expectException(QueryException::class);
        DB::table('blueprint_versions')->insert($row);
    }

    public function test_database_rejects_approval_audit_delete(): void
    {
        $v = $this->save();
        $c = $this->check($v);
        $this->command('approve', $this->approval($v, $c));
        $this->expectException(QueryException::class);
        BlueprintApproval::first()->delete();
    }

    public function test_database_rejects_check_history_update(): void
    {
        $v = $this->save();
        $c = $this->check($v);
        $this->expectException(QueryException::class);
        BlueprintValidation::find($c['id'])->update(['report' => ['passed' => true]]);
    }

    public function test_missing_impact_confirmation_and_forged_report_are_rejected(): void
    {
        $v = $this->save();
        $c = $this->check($v);
        $this->postCommand('approve', array_replace($this->approval($v, $c), ['acknowledge_impact' => false]), 'ack')->assertUnprocessable();
        $this->postCommand('approve', array_replace($this->approval($v, $c), ['report_hash' => str_repeat('a', 64)]), 'hash')->assertConflict();
        $this->assertDatabaseCount('blueprint_approvals', 0);
    }

    public function test_filament_save_check_approve_and_copy_are_real_operations(): void
    {
        $page = Livewire::test(Blueprints::class, ['record' => $this->f['book']['novel']['id']]);
        $page->mountAction('save')->fillForm($this->base($this->f['payload']) + ['command_key' => 'ui-save'])->callMountedAction()->assertHasNoActionErrors();
        $v = BlueprintVersion::firstOrFail();
        $page->mountAction(TestAction::make('validate')->arguments(['blueprint' => $v->id]))->fillForm($this->base(['blueprint_version_id' => $v->id]) + ['command_key' => 'ui-check'])->callMountedAction()->assertHasNoActionErrors();
        $check = BlueprintValidation::firstOrFail();
        $this->assertTrue($check->report['passed']);
        $page->mountAction(TestAction::make('approve')->arguments(['blueprint' => $v->id]))->fillForm($this->base(['blueprint_version_id' => $v->id, 'validation_id' => $check->id, 'report_hash' => $check->report_hash, 'acknowledge_impact' => true]) + ['command_key' => 'ui-approve'])->callMountedAction()->assertHasNoActionErrors();
        $this->assertDatabaseCount('blueprint_approvals', 1);
        $page->mountAction(TestAction::make('save')->arguments(['blueprint' => $v->id]))->fillForm(['reason' => '复制为新候选'])->callMountedAction()->assertHasNoActionErrors();
        $this->assertDatabaseCount('blueprint_versions', 2);
        $page->set('selectedId', $v->id)->set('compareId', BlueprintVersion::latest('id')->first()->id)->assertSee('铜钥匙缺口');
        $this->get('/admin/novels/'.$v->novel_id.'/blueprints')->assertOk();
    }
}
