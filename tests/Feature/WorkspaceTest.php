<?php

namespace Tests\Feature;

use App\Filament\Pages\Overview;
use App\Filament\Resources\NovelResource\Pages\CreateNovel;
use App\Filament\Resources\NovelResource\Pages\ListNovels;
use App\Filament\Resources\NovelResource\Pages\Workspace;
use App\Models\Novel;
use App\Models\User;
use App\Services\NovelIntentService;
use App\Services\RollingPlanService;
use App\Services\WorkspaceService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\RollingPlanFixture;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->actor = User::factory()->create(['is_admin' => true]);
        $this->actingAs($this->actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function book(string $title = '工作台测试'): string
    {
        return app(NovelIntentService::class)->execute($this->actor, 'create', null, ['title' => $title, 'genre' => '悬疑', 'background' => '档案馆', 'premise_raw' => '铜钥匙必须保留', 'target_chapters' => 100, 'length_policy' => ['min' => 2000, 'max' => 3000]], (string) Str::uuid())['novel']['id'];
    }

    public function test_empty_overview_and_no_match_are_distinct(): void
    {
        Livewire::test(Overview::class)->assertSee('创建第一本小说')->assertSee('未知，尚未接入');
        $this->book();
        Livewire::test(Overview::class)->set('status', 'archived')->assertSee('没有符合条件的小说')->assertDontSee('创建第一本小说');
        $this->get('/admin')->assertOk()->assertSee('创作总览');
    }

    public function test_summary_has_real_missing_items_unknown_metrics_and_no_side_effects(): void
    {
        $id = $this->book();
        $before = Novel::find($id)->toArray();
        $commands = DB::table('novel_commands')->count();
        $d = $this->getJson('/api/v1/novels/'.$id)->assertOk()->assertJsonPath('version', 'v1')->json('data');
        $this->assertSame('needs_decision', $d['planning_status']);
        $this->assertContains('BLUEPRINT_REQUIRED', array_column($d['blockers'], 'code'));
        $this->assertContains('PLAN_REQUIRED', array_column($d['blockers'], 'code'));
        $this->assertFalse($d['generation_eligible']);
        foreach ($d['production'] as $value) {
            $this->assertNull($value);
        }
        $this->assertSame($before, Novel::find($id)->toArray());
        $this->assertDatabaseCount('novel_commands', $commands);
        $this->assertArrayNotHasKey('settings', $d['novel']);
        $this->assertArrayNotHasKey('sources', $d);
        $this->get('/admin/novels/'.$id.'/workspace')->assertOk()->assertSee('尚无章节骨架')->assertSee('正文生产');
    }

    public function test_all_planning_pages_keep_book_context_and_return_link(): void
    {
        $a = $this->book('甲书');
        $b = $this->book('乙书');
        foreach (array_keys(WorkspaceService::LINKS) as $page) {
            $this->get('/admin/novels/'.$a.'/'.$page)->assertOk()->assertSee('当前小说：甲书')->assertSee('/admin/novels/'.$a.'/workspace', false)->assertDontSee('/admin/novels/'.$b.'/workspace', false);
        }
        $this->get('/admin/novels/'.$b.'/workspace')->assertOk()->assertSee('当前小说：乙书');
        $this->get('/admin/novels/'.$a.'/workspace')->assertOk()->assertSee('当前小说：甲书');
    }

    public function test_auth_ownership_and_invalid_filters(): void
    {
        $id = $this->book();
        $this->getJson('/api/v1/novels/'.$id.'?from=10&until=2')->assertUnprocessable();
        $other = User::factory()->create();
        Novel::whereKey($id)->update(['owner_id' => $other->id]);
        $this->getJson('/api/v1/novels/'.$id)->assertNotFound();
        $this->get('/admin/novels/'.$id.'/workspace')->assertNotFound();
        $this->actingAs($other)->getJson('/api/v1/novels/'.$id)->assertForbidden();
        auth()->logout();
        $this->getJson('/api/v1/novels/'.$id)->assertUnauthorized();
    }

    public function test_real_ready_frozen_and_stale_plans_are_not_generation_readiness(): void
    {
        $f = RollingPlanFixture::create(10, $this->actor);
        $id = $f['book']['novel']['id'];
        $cmd = function ($command, $d) use ($id) {
            return app(RollingPlanService::class)->execute($this->actor, $id, $command, $d + ['expected_version' => Novel::find($id)->row_version, 'reason' => '工作台验证'], (string) Str::uuid());
        };
        $p = $cmd('save', $f['plan_payload'])['plan'];
        $d = app(WorkspaceService::class)->summary($this->actor, $id);
        $this->assertSame('ready', $d['plan']['status']);
        $this->assertContains('PLAN_FREEZE_REQUIRED', array_column($d['blockers'], 'code'));
        $c = $cmd('validate', ['rolling_plan_id' => $p['id']])['validation'];
        $cmd('freeze', ['rolling_plan_id' => $p['id'], 'validation_id' => $c['id'], 'report_hash' => $c['report_hash']]);
        $d = app(WorkspaceService::class)->summary($this->actor, $id, ['from' => 2, 'until' => 4, 'status' => 'frozen']);
        $this->assertSame('planning_frozen', $d['planning_status']);
        $this->assertSame(10, $d['planning_counts']['valid_frozen_chapters']);
        $this->assertCount(3, $d['chapters']['items']);
        $this->assertFalse($d['generation_eligible']);
        $intent = app(NovelIntentService::class);
        $r = $intent->requirements(Novel::find($id))[0];
        $intent->execute($this->actor, 'requirement', $id, array_replace($r, ['content' => '修改后的要求', 'reason' => '检查过期', 'author_decision' => '作者修正', 'expected_version' => Novel::find($id)->row_version]), (string) Str::uuid());
        $d = app(WorkspaceService::class)->summary($this->actor, $id);
        $this->assertSame('stale', $d['plan']['status']);
        $this->assertSame(0, $d['planning_counts']['valid_frozen_chapters']);
        Livewire::test(Workspace::class, ['record' => $id])->set('status', 'stale')->assertSee('依赖已变化')->assertSee('规划问题');
    }

    public function test_invalid_filters_and_read_failure_do_not_render_empty_state(): void
    {
        $id = $this->book();
        Livewire::test(Workspace::class, ['record' => $id])->set('from', '9')->set('until', '2')->assertSee('筛选条件无效')->assertDontSee('尚无章节骨架');
        $this->partialMock(WorkspaceService::class, fn ($mock) => $mock->shouldReceive('summary')->andThrow(new \RuntimeException('private diagnostic')));
        Livewire::test(Workspace::class, ['record' => $id])->assertSee('规划状态暂时读取失败')->assertDontSee('private diagnostic')->assertDontSee('尚无章节骨架');
    }

    public function test_archived_workbench_and_overview_filters_are_read_only(): void
    {
        $id = $this->book();
        app(NovelIntentService::class)->execute($this->actor, 'archive', $id, ['expected_version' => Novel::find($id)->row_version], (string) Str::uuid());
        $d = app(WorkspaceService::class)->summary($this->actor, $id);
        $this->assertSame('archived', $d['planning_status']);
        $this->assertStringContainsString('恢复', $d['unavailable_reason']);
        Livewire::test(Overview::class)->set('status', 'archived')->assertSee('工作台测试')->set('from', '2099-01-01')->assertSee('没有符合条件的小说');
    }

    public function test_overview_pagination_is_stable_and_has_only_owned_books(): void
    {
        for ($i = 1; $i <= 11; $i++) {
            $this->book('书'.$i);
        }
        $d = app(WorkspaceService::class)->overview($this->actor, ['limit' => 10]);
        $this->assertCount(10, $d['items']);
        $this->assertSame(2, $d['last_page']);
        $next = app(WorkspaceService::class)->overview($this->actor, ['limit' => 10, 'page' => 2]);
        $this->assertCount(1, $next['items']);
        $this->assertNotContains($next['items'][0]->id, array_map(fn ($r) => $r->id, $d['items']));
    }

    public function test_single_sided_ranges_and_filter_reset(): void
    {
        $id = $this->book();
        $this->assertSame(1, app(WorkspaceService::class)->overview($this->actor, ['until' => '2099-01-01'])['total']);
        $this->getJson('/api/v1/novels/'.$id.'?until=10')->assertOk();
        Livewire::test(Workspace::class, ['record' => $id])->set('from', '9')->set('until', '2')->assertSee('筛选条件无效')->call('resetFilters')->assertSet('from', '')->assertSet('until', '')->assertSee('尚无章节骨架');
    }

    public function test_creation_and_copy_redirect_to_the_correct_workspace(): void
    {
        $data = ['title' => '创建后工作台', 'genre' => '悬疑', 'background' => '档案馆', 'premise_raw' => '保留铜钥匙', 'target_chapters' => 10, 'length_policy' => ['min' => 2000, 'max' => 3000], 'acknowledged' => true];
        $ui = Livewire::test(CreateNovel::class)->fillForm($data)->call('create')->assertHasNoFormErrors();
        $book = Novel::firstOrFail();
        $ui->assertRedirect('/admin/novels/'.$book->id.'/workspace');
        $list = Livewire::test(ListNovels::class)->mountAction(TestAction::make('copy')->table($book))->fillForm(['title' => '复制书', 'expected_version' => $book->row_version])->callMountedAction()->assertHasNoActionErrors();
        $copy = Novel::where('title', '复制书')->firstOrFail();
        $this->assertNotSame($book->id, $copy->id);
        $list->assertRedirect('/admin/novels/'.$copy->id.'/workspace');
    }
}
