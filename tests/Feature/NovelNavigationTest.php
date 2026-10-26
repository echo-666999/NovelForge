<?php

namespace Tests\Feature;

use App\Filament\Pages\ContextInspector;
use App\Filament\Pages\ExtractionLedger;
use App\Filament\Pages\ProjectStatus;
use App\Filament\Pages\SearchLab;
use App\Models\Novel;
use App\Models\User;
use App\Services\NovelIntentService;
use App\Services\NovelNavigationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\CanonicalFixture;
use Tests\TestCase;

/** UI03以真实同书数据、无写入导航和安全深链接验证，所有数据仅在测试库。 */
class NovelNavigationTest extends TestCase
{
    use RefreshDatabase;

    private ?User $actor = null;

    private function actor(): User
    {
        $this->actor ??= User::factory()->create(['is_admin' => true]);
        $this->actingAs($this->actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $this->actor;
    }

    private function book(string $title = '导航甲书'): string
    {
        return app(NovelIntentService::class)->execute($this->actor(), 'create', null, ['title' => $title, 'genre' => '悬疑', 'background' => '仅用于导航测试', 'premise_raw' => '保留原始要求', 'target_chapters' => 10, 'length_policy' => ['min' => 2000, 'max' => 3000]], (string) Str::uuid())['novel']['id'];
    }

    public function test_navigation_groups_order_orphans_and_status_secondary_entry(): void
    {
        $this->actor();
        $groups = Filament::getNavigation();
        $this->assertSame(NovelNavigationService::GROUPS, array_values(array_map(fn ($group) => $group->getLabel(), $groups)));
        $production = collect($groups)->first(fn ($group) => $group->getLabel() === '生产与质量');
        $labels = collect($production->getItems())->map(fn ($item) => $item->getLabel())->values()->all();
        $this->assertSame(['生成运行中心', '正文提取与硬约束', '评审中心与策略', '受限修订工作台', '正式提交与基线', '上下文检查器', '运行恢复台'], $labels);
        $this->assertFalse(ProjectStatus::shouldRegisterNavigation());
        $this->get('/admin')->assertOk()->assertSee('关于与实施状态');
        $this->get('/admin/project-status')->assertOk()->assertSee('实施状态');
    }

    public function test_empty_book_context_does_not_select_first_book_or_previous_tab(): void
    {
        $this->actor();
        $this->get('/admin/story-hub')->assertOk()->assertSee('还没有小说');
        $id = $this->book();
        $this->get('/admin/story-hub?novel='.$id)->assertOk()->assertSee('当前小说：导航甲书');
        $this->get('/admin/story-hub')->assertOk()->assertSee('请选择当前小说')->assertDontSee('当前小说：导航甲书');
    }

    public function test_all_book_entries_keep_identity_and_unique_links_without_writes(): void
    {
        $id = $this->book();
        $other = $this->book('导航乙书');
        $service = app(NovelNavigationService::class);
        $before = [DB::table('novel_commands')->count(), DB::table('generation_runs')->count(), DB::table('ai_requests')->count()];
        foreach (array_keys(NovelNavigationService::RESOURCE_PAGES + NovelNavigationService::PAGES) as $page) {
            $this->get($service->destination($page, $id))->assertOk()->assertSee('当前小说：导航甲书')->assertDontSee('当前小说：导航乙书')->assertSee('正式基线：尚未初始化');
        }
        $context = $service->context($this->actor(), $id, 'Workspace');
        $links = collect($context['groups'])->flatten(1)->pluck('url')->all();
        $this->assertSame($links, array_values(array_unique($links)));
        $this->assertSame($before, [DB::table('novel_commands')->count(), DB::table('generation_runs')->count(), DB::table('ai_requests')->count()]);
        $this->get($service->destination('Workspace', $other))->assertSee('当前小说：导航乙书');
    }

    public function test_switch_drops_previous_book_object_parameters_and_keeps_target(): void
    {
        $a = $this->book();
        $b = $this->book('导航乙书');
        $service = app(NovelNavigationService::class);
        foreach (array_keys(NovelNavigationService::RESOURCE_PAGES + NovelNavigationService::PAGES) as $page) {
            $this->get('/admin/novel-context?'.http_build_query(['page' => $page, 'novel' => $b, 'run' => '777', 'version' => '888', 'pov' => '999', 'branch' => '111', 'context' => '222', 'return_url' => 'https://example.com']))
                ->assertRedirect($service->destination($page, $b));
        }
        $this->get('/admin/novel-context?page=Workspace&novel=')->assertRedirect('/admin/novels');
        $this->get('/admin/novel-context?page=ExtractionLedger&novel='.$b.'&version=888')->assertRedirect('/admin/extraction-ledger?novel='.$b);
        Livewire::withQueryParams(['novel' => $b])->test(ExtractionLedger::class)->assertSet('novel', $b)->assertSet('version', '')->assertSet('baseline', '')->assertSet('parent', '');
        Livewire::withQueryParams(['novel' => $a])->test(SearchLab::class)->assertSet('novel', $a)->assertSet('branch', '')->assertSet('pov', '')->assertSet('context', '')->assertSet('result', null);
    }

    public function test_foreign_missing_and_malformed_contexts_are_rejected(): void
    {
        $id = $this->book();
        $foreign = $this->book('不属于当前作者');
        $owner = User::factory()->create();
        Novel::whereKey($foreign)->update(['owner_id' => $owner->id]);
        foreach (['999999999', 'abc', '9223372036854775808', $foreign] as $bad) {
            $this->get('/admin/story-hub?novel='.$bad)->assertNotFound();
            $this->get('/admin/novel-context?page=Workspace&novel='.$bad)->assertNotFound();
        }
        $this->get('/admin/story-hub?novel[]=1')->assertNotFound();
        $this->get('/admin/review-center?version[]=1')->assertNotFound();
        $this->getJson('/admin/novel-context?page=https://example.com&novel='.$id)->assertUnprocessable();
    }

    public function test_switch_and_new_story_page_preserve_authentication(): void
    {
        $this->get('/admin/novel-context?page=Workspace')->assertRedirect('/admin/login');
        $this->get('/admin/story-hub')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create());
        $this->get('/admin/story-hub')->assertForbidden();
        $this->get('/admin/novel-context?page=Workspace')->assertForbidden();
    }

    public function test_actual_canonical_cursor_and_legacy_references_are_same_book(): void
    {
        $f = CanonicalFixture::create();
        $this->actor = $f['actor'];
        $this->actor();
        $commit = CanonicalFixture::commit($f);
        $ctx = app(NovelNavigationService::class)->identity($this->actor, $f['novel']);
        $this->assertSame(1, $ctx['baseline']['cursor']);
        $this->assertSame((string) $f['revision'], $ctx['baseline']['id']);
        $this->assertSame((int) Novel::find($f['novel'])->state_version, $ctx['state_version']);
        $this->get('/admin/novels/'.$f['novel'].'/workspace')->assertOk()->assertSee('连续提交至第1章');
        $b = $this->book('导航乙书');
        $step = (string) DB::table('generation_steps')->where('novel_id', $f['novel'])->value('id');
        $run = (string) DB::table('generation_runs')->where('novel_id', $f['novel'])->value('id');
        foreach ([['review-center', 'version', $f['body']['id']], ['extraction-ledger', 'version', $f['body']['id']], ['revision-desk', 'review', $f['review']['id']], ['context-inspector', 'step', $step], ['run-center', 'run', $run], ['recovery-desk', 'run', $run]] as [$path, $key, $value]) {
            $this->get('/admin/'.$path.'?'.$key.'='.$value)->assertOk()->assertSee('当前小说：');
            $this->get('/admin/'.$path.'?novel='.$b.'&'.$key.'='.$value)->assertNotFound();
        }
        Livewire::test(ContextInspector::class)->set('novel', $b)->set('step', $step)->call('inspect')->assertSet('detail', null)->assertSee('阶段不属于当前小说');
    }

    public function test_book_title_is_escaped_and_sidebar_keeps_explicit_context(): void
    {
        $id = $this->book('<script>不可执行</script>');
        $this->get('/admin/story-hub?novel='.$id)->assertOk()->assertSee('&lt;script&gt;不可执行&lt;/script&gt;', false)->assertDontSee('<script>不可执行</script>', false)->assertSee('canonical-commit-center?novel='.$id, false);
        $this->get('/admin/novels/'.$id.'/workspace')->assertOk()->assertSee('memory-library?novel='.$id, false);
    }
}
