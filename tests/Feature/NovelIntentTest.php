<?php

namespace Tests\Feature;

use App\Filament\Resources\NovelResource\Pages\CreateNovel;
use App\Filament\Resources\NovelResource\Pages\ListNovels;
use App\Filament\Resources\NovelResource\Pages\NovelIntent;
use App\Models\Novel;
use App\Models\PremiseVersion;
use App\Models\RequirementItem;
use App\Models\RequirementVersion;
use App\Models\User;
use App\Services\NovelIntentService;
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

class NovelIntentTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private NovelIntentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->author = User::factory()->create();
        $this->author->is_admin = true;
        $this->author->save();
        $this->actingAs($this->author);
        $this->service = app(NovelIntentService::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function input(): array
    {
        return ['title' => '雾城旧档', 'genre' => '悬疑', 'target_reader' => '成年读者', 'background' => "  雾城，甲😀乙\n旧档馆  ", 'premise_raw' => "  铜钥匙的缺口。\r\n旧案封记不得替换。  ", 'selling_point' => '档案谜案', 'forbidden_content' => '不得用梦境解释', 'target_chapters' => 100, 'length_policy' => ['min' => 2500, 'max' => 3500], 'style' => '克制', 'ending_preference' => '凭证据破解旧案', 'budget_note' => '待定'];
    }

    private function createBook(): array
    {
        return $this->service->execute($this->author, 'create', null, $this->input(), (string) Str::uuid());
    }

    private function write(string $path, array $data, ?string $key = null)
    {
        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())->postJson('/api/v1/'.$path, $data);
    }

    private function revision(array $book): array
    {
        $r = $book['requirements'][0];

        return ['expected_version' => $book['novel']['row_version'], 'requirement_id' => $r['requirement_id'], 'source_version_id' => $r['source_version_id'], 'source_quote' => $r['source_quote'], 'content' => '背景必须保留雾城和档案馆', 'category' => '背景', 'strength' => 'hard', 'critical' => true, 'decision_status' => 'accepted', 'author_decision' => '作者确认背景原句不可替换', 'reason' => '明确背景要求'];
    }

    public function test_create_preserves_original_bytes_and_returns_string_ids_and_unresolved_items(): void
    {
        $response = $this->write('novels', $this->input())->assertCreated()->assertJsonPath('version', 'v1')->assertJsonPath('data.freeze_gate.allowed', false);
        $book = $response->json('data');
        $this->assertIsString($book['novel']['id']);
        $this->assertSame($this->input()['premise_raw'], $book['sources'][0]['premise_raw']);
        $this->assertSame($this->input()['background'], $book['sources'][0]['input']['background']);
        $this->assertCount(4, $book['unresolved_requirements']);
        $this->assertSame(hash('sha256', NovelIntentService::canonical($book['sources'][0]['input'])), $book['sources'][0]['content_hash']);
        $this->assertFalse(Schema::hasTable('chapter_versions'));
        $this->assertFalse(Schema::hasTable('generation_runs'));
    }

    public function test_creation_validation_and_key_are_required(): void
    {
        $this->postJson('/api/v1/novels', $this->input())->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->write('novels', array_replace($this->input(), ['length_policy' => ['min' => 4000, 'max' => 3000]]))->assertUnprocessable();
        $this->write('novels', array_replace($this->input(), ['background' => '']))->assertUnprocessable();
        $this->write('novels', $this->input(), '中文键')->assertUnprocessable();
        $this->assertDatabaseCount('novels', 0);
        $this->assertDatabaseCount('novel_commands', 0);
    }

    public function test_idempotent_replay_is_exact_and_different_payload_conflicts(): void
    {
        $a = $this->write('novels', $this->input(), 'create-once')->assertCreated()->json('data');
        $b = $this->write('novels', array_reverse($this->input(), true), 'create-once')->assertCreated()->json('data');
        $this->assertEquals($a, $b);
        $this->write('novels', array_replace($this->input(), ['title' => '不同书名']), 'create-once')->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->assertDatabaseCount('novels', 1);
    }

    public function test_metadata_versions_and_forbidden_state_updates(): void
    {
        $b = $this->createBook();
        $id = $b['novel']['id'];
        $this->withHeader('Idempotency-Key', 'metadata')->patchJson('/api/v1/novels/'.$id, ['expected_version' => 1, 'title' => '新书名'])->assertOk()->assertJsonPath('data.novel.row_version', 2);
        $this->withHeader('Idempotency-Key', 'stale')->patchJson('/api/v1/novels/'.$id, ['expected_version' => 1, 'title' => '旧窗口'])->assertConflict()->assertJsonPath('error.code', 'STALE_STATE');
        $this->withHeader('Idempotency-Key', 'state')->patchJson('/api/v1/novels/'.$id, ['expected_version' => 2, 'status' => 'ready', 'state_version' => 9])->assertUnprocessable();
        $this->assertSame('draft', Novel::find($id)->status);
        $this->assertSame(0, Novel::find($id)->state_version);
    }

    public function test_source_and_requirement_revisions_keep_history_and_cross_book_ids_are_rejected(): void
    {
        $b = $this->createBook();
        $id = $b['novel']['id'];
        $r = $this->revision($b);
        $this->write('novels/'.$id.'/requirement', $r)->assertOk()->assertJsonPath('data.requirement.version', 2);
        $this->assertSame('unresolved', RequirementVersion::find($b['requirements'][0]['id'])->decision_status);
        $this->write('novels/'.$id.'/source', array_replace($this->input(), ['expected_version' => 2, 'premise_raw' => '新的创意', 'reason' => '作者追加']))->assertOk();
        $this->assertSame($this->input()['premise_raw'], PremiseVersion::find($b['sources'][0]['id'])->premise_raw);
        $this->assertSame(2, PremiseVersion::where('novel_id', $id)->count());
        $other = $this->createBook();
        $this->write('novels/'.$id.'/requirement', array_replace($r, ['expected_version' => 3, 'source_version_id' => $other['sources'][0]['id']]))->assertNotFound();
        $this->write('novels/'.$id.'/requirement', array_replace($r, ['expected_version' => 3, 'requirement_id' => $other['requirements'][0]['requirement_id']]))->assertNotFound();
        $this->write('novels/'.$id.'/requirement', array_replace($r, ['expected_version' => 3, 'source_quote' => '伪造原句']))->assertUnprocessable()->assertJsonPath('error.code', 'EVIDENCE_INVALID');
        $this->assertSame(3, Novel::find($id)->row_version);
    }

    public function test_hard_waiver_conflict_and_coverage_cannot_be_forged(): void
    {
        $b = $this->createBook();
        $path = 'novels/'.$b['novel']['id'].'/requirement';
        $r = $this->revision($b);
        $this->write($path, array_replace($r, ['decision_status' => 'waived']))->assertUnprocessable();
        $this->write($path, array_replace($r, ['decision_status' => 'conflict', 'conflict' => null]))->assertUnprocessable();
        $this->write($path, array_replace($r, ['decision_status' => 'covered']))->assertUnprocessable();
        $this->write($path, array_replace($r, ['author_decision' => '']))->assertUnprocessable();
        $this->write($path, array_replace($r, ['decision_status' => 'conflict', 'conflict' => '两条背景要求矛盾']))->assertOk();
        $r['expected_version'] = 2;
        $this->write($path, array_replace($r, ['strength' => 'preference', 'decision_status' => 'waived', 'author_decision' => '作者决定不再要求此偏好']))->assertOk();
        $this->getJson('/api/v1/novels/'.$b['novel']['id'].'/intent')->assertOk()->assertJsonPath('data.freeze_gate.allowed', false);
    }

    public function test_archive_restore_and_copy_reset_history(): void
    {
        $b = $this->createBook();
        $id = $b['novel']['id'];
        $this->write('novels/'.$id.'/archive', ['expected_version' => 1], 'archive')->assertOk()->assertJsonPath('data.novel.status', 'archived');
        $this->write('novels/'.$id.'/archive', ['expected_version' => 1], 'archive')->assertOk()->assertJsonPath('data.novel.row_version', 2);
        $this->write('novels/'.$id.'/requirement', array_replace($this->revision($b), ['expected_version' => 2]))->assertConflict();
        $copy = $this->write('novels/'.$id.'/copy', ['expected_version' => 2, 'title' => '独立新书'])->assertCreated()->json('data');
        $this->assertNotSame($id, $copy['novel']['id']);
        $this->assertSame('draft', $copy['novel']['status']);
        $this->assertSame(0, $copy['novel']['state_version']);
        $this->assertSame(1, $copy['novel']['row_version']);
        $this->assertCount(1, $copy['sources']);
        $this->assertSame('unresolved', $copy['requirements'][0]['decision_status']);
        $this->write('novels/'.$id.'/restore', ['expected_version' => 2])->assertOk()->assertJsonPath('data.novel.status', 'draft');
        $this->write('novels/'.$id.'/restore', ['expected_version' => 3])->assertConflict();
    }

    public function test_database_blocks_cross_novel_reference(): void
    {
        $a = $this->createBook();
        $b = $this->createBook();
        $row = RequirementVersion::find($a['requirements'][0]['id'])->getAttributes();
        unset($row['id']);
        $row['version'] = 2;
        $row['source_version_id'] = $b['sources'][0]['id'];
        $this->expectException(QueryException::class);
        DB::table('requirement_versions')->insert($row);
    }

    public function test_database_blocks_original_history_update(): void
    {
        $b = $this->createBook();
        $this->expectException(QueryException::class);
        DB::table('premise_versions')->where('id', $b['sources'][0]['id'])->update(['premise_raw' => '覆盖原文']);
    }

    public function test_database_blocks_requirement_history_delete(): void
    {
        $b = $this->createBook();
        $this->expectException(QueryException::class);
        DB::table('requirement_versions')->where('id', $b['requirements'][0]['id'])->delete();
    }

    public function test_authorization_and_record_scoping(): void
    {
        $b = $this->createBook();
        $other = User::factory()->create();
        $foreign = Novel::find($b['novel']['id'])->replicate();
        $foreign->owner_id = $other->id;
        $foreign->save();
        $this->getJson('/api/v1/novels/'.$foreign->id.'/intent')->assertNotFound();
        $this->get('/admin/novels/'.$foreign->id.'/intent')->assertNotFound();
        $this->actingAs($other)->getJson('/api/v1/novels/'.$b['novel']['id'].'/intent')->assertForbidden();
        $this->get('/admin/novels')->assertForbidden();
        auth()->logout();
        $this->getJson('/api/v1/novels/'.$b['novel']['id'].'/intent')->assertUnauthorized();
    }

    public function test_csrf_is_enforced_outside_phpunit_bypass(): void
    {
        $this->app['env'] = 'production';
        $this->write('novels', $this->input())->assertStatus(419)->assertJsonPath('error.code', 'CSRF_MISMATCH');
        $this->assertDatabaseCount('novels', 0);
    }

    public function test_filament_create_list_and_requirement_actions_use_the_service(): void
    {
        Livewire::test(CreateNovel::class)->fillForm($this->input() + ['acknowledged' => true])->call('create')->assertHasNoFormErrors();
        $novel = Novel::firstOrFail();
        $this->get('/admin/novels')->assertOk();
        $this->get('/admin/novels/'.$novel->id.'/intent')->assertOk()->assertSee('覆盖矩阵')->assertSee('计划模块尚未接入');
        Livewire::test(ListNovels::class)->assertCanSeeTableRecords([$novel])->filterTable('genre', '不存在')->assertCanNotSeeTableRecords([$novel]);
        $book = $this->service->snapshot($this->author, $novel);
        $data = $this->revision($book);
        $component = Livewire::test(NovelIntent::class, ['record' => $novel->id]);
        $component->mountAction(TestAction::make('requirement')->arguments(['requirement' => $data['requirement_id']]));
        $component->fillForm($data + ['command_key' => 'ui-requirement'])->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame(2, RequirementItem::find($data['requirement_id'])->current_version);
        Livewire::test(ListNovels::class)->resetTableFilters()->mountAction(TestAction::make('archive')->table($novel))->fillForm(['expected_version' => 2])->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('archived', $novel->fresh()->status);
    }

    public function test_requirement_cursor_pagination_and_filters(): void
    {
        $book = $this->createBook();
        $path = '/api/v1/novels/'.$book['novel']['id'].'/requirements';
        $first = $this->getJson($path.'?limit=2')->assertOk()->json('data');
        $this->assertCount(2, $first['items']);
        $this->assertNotEmpty($first['next_cursor']);
        $second = $this->getJson($path.'?limit=2&cursor='.urlencode($first['next_cursor']))->assertOk()->json('data');
        $this->assertCount(2, $second['items']);
        $this->assertNotSame($first['items'][0]['id'], $second['items'][0]['id']);
        $this->getJson($path.'?limit=101')->assertUnprocessable();
        $this->getJson($path.'?cursor=invalid')->assertUnprocessable();
        $this->getJson($path.'?strength=hard')->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_filament_source_revision_and_matrix_filters(): void
    {
        $book = $this->createBook();
        $id = $book['novel']['id'];
        $component = Livewire::test(NovelIntent::class, ['record' => $id]);
        $component->mountAction('source')->fillForm(array_replace($this->input(), ['expected_version' => 1, 'premise_raw' => '修改后的原文', 'reason' => '作者修正', 'command_key' => 'source-ui']))->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame(2, PremiseVersion::where('novel_id', $id)->count());
        $this->assertSame($this->input()['premise_raw'], PremiseVersion::find($book['sources'][0]['id'])->premise_raw);
        $component->set('strength', 'hard');
        $this->assertCount(1, $component->instance()->rows()->items());
        $component->set('source', PremiseVersion::where('novel_id', $id)->latest('version')->first()->id);
        $this->assertCount(0, $component->instance()->rows()->items());
    }

    public function test_stale_filament_action_does_not_overwrite_newer_state(): void
    {
        $b = $this->createBook();
        $novel = Novel::find($b['novel']['id']);
        $this->service->execute($this->author, 'update', $novel->id, ['expected_version' => 1, 'title' => '较新标题'], 'new-title');
        Livewire::test(ListNovels::class)->mountAction(TestAction::make('metadata')->table($novel))->fillForm(['expected_version' => 1, 'title' => '旧标题', 'genre' => '悬疑'])->callMountedAction()->assertNotified('内容已更新，请刷新后重新操作。');
        $this->assertSame('较新标题', $novel->fresh()->title);
    }
}
