<?php

namespace Tests\Feature;

use App\Filament\Pages\AuditCenter;
use App\Filament\Pages\Billing;
use App\Filament\Pages\CanonicalCommitCenter;
use App\Filament\Pages\CanonicalMemoryCenter;
use App\Filament\Pages\ContextInspector;
use App\Filament\Pages\MemoryLibrary;
use App\Filament\Pages\RecoveryDesk;
use App\Filament\Pages\ReviewCenter;
use App\Filament\Pages\RevisionDesk;
use App\Filament\Pages\RunCenter;
use App\Filament\Pages\SearchLab;
use App\Filament\Resources\NovelResource\Pages\DraftEditor;
use App\Filament\Resources\NovelResource\Pages\PlanningCandidates;
use App\Filament\Support\JsonFields;
use App\Models\User;
use App\Services\CanonicalMemoryService;
use App\Services\DraftingService;
use App\Services\NovelIntentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\CanonicalFixture;
use Tests\Support\PlanningFixture;
use Tests\Support\ReviewFixture;
use Tests\Support\RevisionFixture;
use Tests\Support\RollingPlanFixture;
use Tests\TestCase;

/** UI07只使用隔离PostgreSQL和真实离线夹具，候选与正式状态分别断言。 */
class ProductionUiTest extends TestCase
{
    use RefreshDatabase;

    private function actor(?User $user = null): void
    {
        Http::preventStrayRequests();
        $this->actingAs($user ?? User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** 从语义键定位字段，不用固定数组下标掩盖JSONB键顺序差异。 */
    private function field(array $node, string $root, array $keys): string
    {
        $path = 'trees.'.$root;
        foreach ($keys as $key) {
            $i = array_search($key, array_column($node['children'], 'key'), true);
            $this->assertNotFalse($i);
            $node = $node['children'][$i]['node'];
            $path .= '.children.'.$i.'.node';
        }

        return $path;
    }

    public function test_thirteen_pages_render_structured_evidence_without_writes(): void
    {
        $f = RollingPlanFixture::create();
        $this->actor($f['actor']);
        $id = $f['book']['novel']['id'];
        $tables = ['novel_commands', 'generation_runs', 'reviews', 'revision_tasks', 'canonical_commits', 'ai_requests', 'billing_commands'];
        $before = [];
        foreach ($tables as $t) {
            $before[$t] = DB::table($t)->count();
        }
        foreach ([DraftEditor::class, PlanningCandidates::class] as $page) {
            Livewire::test($page, ['record' => $id])->assertOk()->assertSee('nf-production-screen', false)->assertDontSee('<pre', false)->assertDontSee('资料读取失败');
        }
        foreach ([ReviewCenter::class, RevisionDesk::class, CanonicalCommitCenter::class, MemoryLibrary::class, CanonicalMemoryCenter::class, SearchLab::class, ContextInspector::class, RunCenter::class, RecoveryDesk::class] as $page) {
            Livewire::test($page)->set('novel', $id)->assertOk()->assertSee('nf-production-screen', false)->assertDontSee('<pre', false)->assertDontSee('完整配置 JSON')->assertDontSee('资料读取失败');
        }
        foreach ([Billing::class, AuditCenter::class] as $page) {
            Livewire::test($page)->assertOk()->assertSee('nf-production-screen', false)->assertDontSee('<pre', false)->assertDontSee('完整配置 JSON')->assertDontSee('资料读取失败');
        }
        foreach ($tables as $t) {
            $this->assertSame($before[$t], DB::table($t)->count(), $t);
        }Http::assertNothingSent();
    }

    /** 默认展开的浅对象可以收起再展开，字段输入在切换后保持。 */
    public function test_default_open_object_toggle_preserves_field_input(): void
    {
        $this->actor();
        $page = Livewire::test(ReviewCenter::class);
        $path = $this->field($page->get('trees.output'), 'output', ['scores', 'plot']);
        $score = $this->field($page->get('trees.output'), 'output', ['scores', 'plot', 'score']);
        $page->set(JsonFields::binding($score.'.value'), '4')
            ->call('toggleProductionNode', $path);
        $this->assertFalse($page->get('expandedJson')[$path]);
        $page->call('toggleProductionNode', $path);
        $this->assertTrue($page->get('expandedJson')[$path]);
        $page->assertSet(JsonFields::binding($score.'.value'), '4');
        Http::assertNothingSent();
    }

    public function test_review_field_edits_are_saved_and_identical_retry_reuses_record(): void
    {
        $f = ReviewFixture::create();
        $this->actor($f['actor']);
        $page = Livewire::test(ReviewCenter::class)->set('novel', $f['novel'])->set('version', $f['body']['id'])->set('extraction', $f['extraction']['id'])->set('policy', $f['policy']['id'])->set('output', json_encode(ReviewFixture::output($f)))->set('reason', 'UI07逐字段离线评分');
        $path = $this->field($page->get('trees.output'), 'output', ['scores', 'plot', 'score']);
        $page->set(JsonFields::binding($path.'.value'), '5')->call('review')->assertHasNoErrors()->assertSet('detail.output.scores.plot.score', 5);
        $id = $page->get('detail.id');
        $page->call('review')->assertHasNoErrors()->assertSet('detail.id', $id);
        $this->assertDatabaseCount('reviews', 1);
        $this->assertSame(4, $page->get('detail.output.scores.character.score'));
        $this->assertDatabaseCount('canonical_commits', 0);
        Http::assertNothingSent();
    }

    public function test_invalid_field_never_submits_previous_valid_payload_and_import_keeps_draft(): void
    {
        $f = ReviewFixture::create();
        $this->actor($f['actor']);
        $page = Livewire::test(ReviewCenter::class)->set('novel', $f['novel'])->set('version', $f['body']['id'])->set('extraction', $f['extraction']['id'])->set('policy', $f['policy']['id'])->set('output', json_encode(ReviewFixture::output($f)))->set('reason', '错误字段不得退回旧响应');
        $path = $this->field($page->get('trees.output'), 'output', ['scores', 'plot', 'score']);
        $page->set(JsonFields::binding($path.'.value'), '不是整数')->call('review')->assertHasErrors($path.'.value');
        $this->assertDatabaseCount('reviews', 0);
        $tree = $page->get('trees.output');
        $page->set('productionImports.output', '{"scores":1,"scores":2}')->call('importProductionPayload', 'output')->assertHasErrors('productionImports.output');
        $this->assertSame($tree, $page->get('trees.output'));
        Http::assertNothingSent();
    }

    public function test_policy_fields_save_publish_retire_and_old_content_stays_immutable(): void
    {
        $f = ReviewFixture::create();
        $this->actor($f['actor']);
        $page = Livewire::test(ReviewCenter::class)->set('novel', $f['novel'])->set('policyKey', 'ui07.review')->set('style', $f['style'])->set('reason', '相同原因但不同策略动作');
        $path = $this->field($page->get('trees.policy_json'), 'policy_json', ['minimums', 'plot']);
        $page->set(JsonFields::binding($path.'.value'), '4')->call('policyAction', 'save')->assertHasNoErrors();
        $id = $page->get('policy');
        $stored = DB::table('review_policy_versions')->find($id);
        $page->call('policyAction', 'publish')->assertHasNoErrors()->call('policyAction', 'retire')->assertHasNoErrors();
        $this->assertSame($stored->content_hash, DB::table('review_policy_versions')->find($id)->content_hash);
        $this->assertSame(['published', 'retired'], DB::table('review_policy_states')->where('policy_version_id', $id)->orderBy('id')->pluck('status')->all());
        Http::assertNothingSent();
    }

    public function test_revision_scope_patch_order_and_explicit_adoption_use_frozen_sources(): void
    {
        $f = RevisionFixture::create();
        $this->actor($f['actor']);
        $d = RevisionFixture::payload($f);
        $page = Livewire::test(RevisionDesk::class)->set('novel', $f['novel'])->set('review', $f['review']['id'])->set('issues', implode(',', $d['issue_ids']))->set('scopeJson', json_encode($d['allowed_scope']))->set('protectedJson', json_encode($d['preserve_quotes']))->set('reason', $d['reason'])->call('createTask')->assertHasNoErrors();
        $task = $page->get('selected');
        $this->assertNotSame('', $task);
        $page->call('createTask')->assertSet('selected', $task);
        $this->assertDatabaseCount('revision_tasks', 1);
        $page->set('patchJson', json_encode(RevisionFixture::output($f)));
        $path = $this->field($page->get('trees.patch_json'), 'patch_json', ['patches']);
        $page->call('changeJsonRow', $path, 0, 'down');
        $node = data_get($page->get('trees'), substr($path, 6));
        $this->assertSame('second', JsonFields::value($node)[0]->key);
        $page->call('changeJsonRow', $path, 0, 'down')->call('propose')->assertHasNoErrors();
        $proposal = $page->get('proposal');
        $page->call('propose')->assertSet('proposal', $proposal);
        $this->assertDatabaseCount('revision_proposals', 1);
        $page->set('patchKeys', ['first'])->set('reason', '只明确采纳第一条补丁')->call('decide', 'accept')->assertHasNoErrors();
        $this->assertNotSame($f['body']['id'], $page->get('detail.decision.target_version_id'));
        $this->assertSame($f['body']['body'], DB::table('chapter_versions')->where('id', $f['body']['id'])->value('body'));
        $this->assertDatabaseCount('canonical_commits', 0);
        $page->set('selected', '')->assertSet('detail', null)->assertSet('patchKeys', []);
        Http::assertNothingSent();
    }

    public function test_planning_fields_start_and_retry_preserve_the_original_task(): void
    {
        $f = PlanningFixture::create();
        $this->actor($f['actor']);
        $output = PlanningFixture::intake($f);
        $route = PlanningFixture::route($f, 'intake', $output);
        $page = Livewire::test(PlanningCandidates::class, ['record' => $f['novel']])->set('route', $route)->set('policy', $f['billing']['policy_id'])->set('prices', implode(',', $f['billing']['price_version_ids']))->set('offlineOutput', json_encode($output))->set('reason', '字段化规划任务');
        $before = DB::table('generation_runs')->count();
        $page->call('startCandidate')->assertHasNoErrors();
        $id = $page->get('selected');
        $this->assertNotSame('', $id);
        $page->call('startCandidate')->assertSet('selected', $id);
        $this->assertSame($before + 1, DB::table('generation_runs')->count());
        $this->assertDatabaseCount('canonical_commits', 0);
        Http::assertNothingSent();
    }

    public function test_budget_decimal_text_and_duplicate_save_are_not_float_or_extra_records(): void
    {
        $this->actor();
        $page = Livewire::test(Billing::class)->set('policyPayload', json_encode(['key' => 'ui07.budget', 'expected_version' => 0, 'currency' => 'USD', 'limits' => array_fill_keys(['request', 'chapter', 'run', 'book', 'day'], '123.4500000001'), 'warning_percent' => 80, 'stop_percent' => 100, 'timezone' => 'UTC', 'reason' => '仅用于离线预算字段测试']));
        $page->call('saveBilling', 'policy')->assertHasNoErrors()->call('saveBilling', 'policy')->assertHasNoErrors();
        $this->assertDatabaseCount('budget_policies', 1);
        $p = DB::table('budget_policies')->first();
        $this->assertSame('123.4500000001', json_decode($p->limits, true)['request']);
        Http::assertNothingSent();
    }

    public function test_typed_path_and_cross_book_reference_are_rejected(): void
    {
        $f = RollingPlanFixture::create();
        $this->actor($f['actor']);
        $foreignBook = app(NovelIntentService::class)->execute($f['actor'], 'create', null, ['title' => '禁止泄漏的外书', 'genre' => '悬疑', 'background' => '私有', 'premise_raw' => '私有', 'target_chapters' => 1, 'length_policy' => ['min' => 2000, 'max' => 3000]], 'ui07-foreign-book');
        $foreign = $foreignBook['novel']['id'];
        DB::table('novels')->where('id', $foreign)->update(['owner_id' => User::factory()->create()->id]);
        $page = Livewire::test(ReviewCenter::class)->set('novel', $f['book']['novel']['id']);
        $this->assertArrayNotHasKey((string) $foreign, $page->instance()->productionChoices('book'));
        $page->call('addProductionField', 'trees.novel', 'anything')->assertHasErrors();
        $this->assertSame($f['book']['novel']['id'], $page->get('novel'));
        Http::assertNothingSent();
    }

    /** 非法筛选不是空账本；失败仍保留输入并能由用户明确重置。 */
    public function test_read_failure_keeps_filter_and_does_not_claim_empty_or_zero(): void
    {
        $this->actor();
        Livewire::test(Billing::class)->set('currency', 'U')->assertSee('资料读取失败')->assertSet('currency', 'U')->assertDontSee('暂无费用记录')->call('resetProductionFilters')->assertSet('currency', '')->assertSee('暂无费用记录');
        Http::assertNothingSent();
    }

    /** 使用真实新候选正文验证emoji码点，不在UTF16位置或重复引文中猜测。 */
    public function test_review_reads_actual_body_and_locates_unique_unicode_quote_without_writes(): void
    {
        $f = ReviewFixture::create();
        $this->actor($f['actor']);
        $b = app(DraftingService::class)->save($f['actor'], $f['novel'], 'chapter', $f['body']['node_id'], ['plan_id' => $f['plan']['id'], 'source_version_id' => $f['body']['id'], 'expected_version' => $f['body']['version'], 'title' => 'Unicode评分定位', 'body' => '甲😀乙'.$f['body']['body'], 'reason' => '只用于离线UI证据码点验证'], (string) Str::uuid());
        $page = Livewire::test(ReviewCenter::class)->set('novel', $f['novel'])->set('version', $b['id'])->call('loadReviewSource')->assertSet('reviewSource.id', $b['id']);
        $path = $this->field($page->get('trees.output'), 'output', ['scores', 'plot', 'evidence']);
        $quote = $this->field($page->get('trees.output'), 'output', ['scores', 'plot', 'evidence', 'quote']);
        $before = [DB::table('chapter_versions')->count(), DB::table('reviews')->count(), DB::table('ai_requests')->count()];
        $page->set(JsonFields::binding($quote.'.value'), '😀乙')->call('locateReviewQuote', $path)->assertHasNoErrors();
        $span = JsonFields::value(data_get($page->get('trees'), substr($path, 6)));
        $this->assertSame(1, $span->start);
        $this->assertSame(3, $span->end);
        $page->set(JsonFields::binding($quote.'.value'), '许砚')->call('locateReviewQuote', $path)->assertHasErrors($path);
        $this->assertSame($before, [DB::table('chapter_versions')->count(), DB::table('reviews')->count(), DB::table('ai_requests')->count()]);
        Http::assertNothingSent();
    }

    /** 正式摘要与候选正文分别显示，读取派生资料不得推进正式章或再发模型请求。 */
    public function test_canonical_memory_and_formal_body_are_structured_readonly(): void
    {
        $f = CanonicalFixture::create(true, true);
        CanonicalFixture::commit($f);
        $this->actor($f['actor']);
        $memory = app(CanonicalMemoryService::class);
        $memory->discover();
        $memory->consume((string) DB::table('canonical_memory_tasks')->value('id'));
        $before = [DB::table('canonical_commits')->count(), DB::table('story_events')->count(), DB::table('ai_requests')->count()];
        Livewire::test(CanonicalMemoryCenter::class)->set('novel', $f['novel'])->assertSee('正式分层摘要只读')->assertDontSee('<pre', false)->assertDontSee('资料读取失败');
        Livewire::test(DraftEditor::class, ['record' => $f['novel']])->set('selected', $f['body']['id'])->call('inspect')->assertSet('formalDetail.id', $f['body']['id'])->assertSee('正式正文只读区');
        $this->assertSame($before, [DB::table('canonical_commits')->count(), DB::table('story_events')->count(), DB::table('ai_requests')->count()]);
        Http::assertNothingSent();
    }
}
