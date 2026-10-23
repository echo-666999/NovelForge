<?php

namespace Tests\Feature;

use App\Exceptions\NovelProblem;
use App\Filament\Resources\NovelResource\Pages\RollingPlans;
use App\Models\Chapter;
use App\Models\Novel;
use App\Models\PlanFreeze;
use App\Models\PlanValidation;
use App\Models\RequirementCoverage;
use App\Models\RollingPlan;
use App\Models\Scene;
use App\Models\StoryArc;
use App\Models\User;
use App\Services\NovelIntentService;
use App\Services\RollingPlanService;
use App\Services\StructureService;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\BlueprintFixture;
use Tests\Support\RollingPlanFixture;
use Tests\TestCase;

class RollingPlanTest extends TestCase
{
    use RefreshDatabase;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->f = RollingPlanFixture::create();
        $this->actingAs($this->f['actor']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function id(): string
    {
        return $this->f['book']['novel']['id'];
    }

    private function base(array $d = []): array
    {
        return $d + ['expected_version' => Novel::find($this->id())->row_version, 'reason' => '验证计划门禁'];
    }

    private function runPlan(string $cmd, array $d = []): array
    {
        return app(RollingPlanService::class)->execute($this->f['actor'], $this->id(), $cmd, $this->base($d), (string) Str::uuid());
    }

    private function save(?array $d = null): array
    {
        return $this->runPlan('save', $d ?? $this->f['plan_payload'])['plan'];
    }

    private function check(array $p): array
    {
        return $this->runPlan('validate', ['rolling_plan_id' => $p['id']])['validation'];
    }

    private function freeze(array $p, array $c): array
    {
        return $this->runPlan('freeze', ['rolling_plan_id' => $p['id'], 'validation_id' => $c['id'], 'report_hash' => $c['report_hash']]);
    }

    private function codes(array $p): array
    {
        return array_column($p['report']['blockers'], 'code');
    }

    private function change(string $type, $node, array $extra): void
    {
        app(StructureService::class)->execute($this->f['actor'], $this->id(), 'node', $this->base(array_replace($node->toArray(), ['type' => $type, 'node_id' => $node->id], $extra)), (string) Str::uuid());
    }

    public function test_first_ten_have_complete_budget_and_coverage_then_freeze_without_generating(): void
    {
        $p = $this->save();
        $this->assertTrue($p['report']['passed'], json_encode($p['report']['blockers']));
        $this->assertSame(range(1, 10), $p['report']['detailed_chapters']);
        $c = $this->check($p);
        $this->freeze($p, $c);
        $r = app(RollingPlanService::class)->read($this->f['actor'], $this->id(), $p['id']);
        $this->assertSame('frozen', $r['display_status']);
        $this->assertFalse($r['generation_eligible']);
        $intent = app(NovelIntentService::class)->snapshot($this->f['actor'], Novel::find($this->id()));
        $this->assertTrue($intent['freeze_gate']['allowed']);
        foreach ($intent['requirements'] as $req) {
            $this->assertSame('covered', $req['coverage_status']);
            $this->assertCount(3, $req['coverage_evidence']);
            $this->assertStringContainsString('/plans?selectedId=', $req['plan_url']);
        }
        $this->assertSame(0, Novel::find($this->id())->state_version);
        $this->get('/admin/novels/'.$this->id().'/intent')->assertOk()->assertSee('查看承接计划与证据');
        $this->assertCount(1, app(RollingPlanService::class)->listing($this->f['actor'], $this->id(), ['status' => 'frozen'])['items']);
        $this->assertCount(0, app(RollingPlanService::class)->listing($this->f['actor'], $this->id(), ['status' => 'ready'])['items']);
    }

    public function test_budget_mismatch_blocks_freeze(): void
    {
        $s = $this->f['scene']->fresh();
        $this->change('scene', $s, ['details' => array_replace($s->details, ['target_words' => 2400])]);
        $p = $this->save();
        $this->assertContains('BUDGET_MISMATCH', $this->codes($p));
        $c = $this->check($p);
        $this->expectException(NovelProblem::class);
        $this->expectExceptionMessage('场景计划仍有阻塞项');
        $this->freeze($p, $c);
    }

    public function test_missing_scene_fields_and_unapproved_pov_block(): void
    {
        $s = $this->f['scene']->fresh();
        $d = $s->details;
        unset($d['emotion_end'],$d['time_end']);
        $d['pov_version_id'] = '999999';
        $this->change('scene', $s, ['details' => $d]);
        $p = $this->save();
        $this->assertContains('SCENE_FIELD_MISSING', $this->codes($p));
        $this->assertContains('POV_INVALID', $this->codes($p));
    }

    public function test_missing_coverage_and_broken_ancestry_block(): void
    {
        $d = $this->f['plan_payload'];
        $d['coverages'] = array_values(array_filter($d['coverages'], fn ($c) => $c['type'] !== 'chapter'));
        $p = $this->save($d);
        $this->assertContains('REQUIREMENT_UNCOVERED', $this->codes($p));
        $other = Chapter::where('novel_id', $this->id())->where('sequence', 2)->first();
        $d = $this->f['plan_payload'];
        foreach ($d['coverages'] as &$c) {
            if ($c['type'] === 'chapter') {
                $c['node_id'] = $other->id;
            }
        } unset($c);
        $this->assertContains('REQUIREMENT_UNCOVERED', $this->codes($this->save($d)));
    }

    public function test_bad_evidence_rolls_back_new_outline_and_plan(): void
    {
        $count = DB::table('outline_versions')->count();
        $d = $this->f['plan_payload'];
        $d['coverages'][0]['quote'] = '伪造引文';
        try {
            $this->save($d);
            $this->fail();
        } catch (NovelProblem $e) {
            $this->assertSame('EVIDENCE_INVALID', $e->errorCode);
        }
        $this->assertDatabaseCount('rolling_plans', 0);
        $this->assertDatabaseCount('outline_versions', $count);
    }

    public function test_plan_and_evidence_are_immutable_in_database(): void
    {
        $p = $this->save();
        foreach (['rolling_plans' => ['reason' => '改历史'], 'requirement_coverages' => ['quote' => '改证据']] as $table => $data) {
            try {
                DB::transaction(fn () => DB::table($table)->update($data));
                $this->fail();
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
        }
        $this->assertSame($p['content_hash'], RollingPlan::find($p['id'])->content_hash);
    }

    public function test_freeze_blocks_legacy_editor_direct_sql_and_scene_insertion(): void
    {
        $p = $this->save();
        $this->freeze($p, $this->check($p));
        try {
            $this->change('scene', $this->f['scene']->fresh(), ['title' => '绕过冻结']);
            $this->fail();
        } catch (NovelProblem $e) {
            $this->assertSame('PLAN_FROZEN', $e->errorCode);
        }
        try {
            DB::transaction(fn () => Scene::whereKey($this->f['scene']->id)->update(['title' => '直接改']));
            $this->fail();
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        try {
            DB::transaction(function () {
                $s = $this->f['scene']->fresh()->replicate();
                $s->sequence = 2;
                $s->save();
            });
            $this->fail();
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
    }

    public function test_edit_after_validation_makes_report_stale_and_retains_history(): void
    {
        $p = $this->save();
        $c = $this->check($p);
        $this->change('scene', $this->f['scene']->fresh(), ['title' => '新的场景名']);
        $r = app(RollingPlanService::class)->read($this->f['actor'], $this->id(), $p['id']);
        $this->assertSame('stale', $r['display_status']);
        try {
            $this->freeze($p, $c);
            $this->fail();
        } catch (NovelProblem $e) {
            $this->assertSame('VALIDATION_STALE', $e->errorCode);
        }
        $this->assertTrue(PlanValidation::find($c['id'])->report['passed']);
        $next = $this->save();
        $this->assertTrue($next['report']['passed']);
        $this->assertNotSame($p['outline_version_id'], $next['outline_version_id']);
    }

    public function test_generic_title_and_skeleton_are_not_detailed_plan(): void
    {
        $this->change('chapter', $this->f['chapter']->fresh(), ['title' => '第1章', 'status' => 'skeleton']);
        $this->assertContains('CHAPTER_INCOMPLETE', $this->codes($this->save()));
    }

    public function test_split_merge_and_reorder_preserve_budgets_and_old_snapshot(): void
    {
        $p = $this->save();
        $s = $this->f['scene']->fresh();
        $replacement = function ($name, $min, $target, $max) use ($s) {
            return ['title' => $name, 'objective' => $name.'的独立目标', 'details' => array_replace($s->details, ['min_words' => $min, 'target_words' => $target, 'max_words' => $max, 'required_events' => $name.'的事件'])];
        };
        $a = $replacement('查封档案', 1000, 1200, 1500);
        $b = $replacement('核验铜钥匙', 1000, 1300, 1500);
        $split = $this->runPlan('split', ['chapter_id' => $s->parent_id, 'scene_ids' => [$s->id], 'replacements' => [$a, $b]]);
        $this->assertTrue($s->fresh()->retired);
        $this->assertCount(2, $split['scene_ids']);
        $this->runPlan('reorder', ['chapter_id' => $s->parent_id, 'scene_ids' => array_reverse($split['scene_ids'])]);
        $this->assertSame(array_reverse($split['scene_ids']), Scene::where('parent_id', $s->parent_id)->where('retired', false)->orderBy('sequence')->pluck('id')->all());
        $merged = $this->runPlan('merge', ['chapter_id' => $s->parent_id, 'scene_ids' => $split['scene_ids'], 'replacements' => [$replacement('证据汇总', 2000, 2500, 3000)]]);
        $this->assertCount(1, $merged['scene_ids']);
        $this->assertSame($p['content_hash'], RollingPlan::find($p['id'])->content_hash);
        $this->assertSame($s->id, collect($p['nodes'])->firstWhere('scene_id', $s->id)['scene_id']);
        $this->assertSame('stale', app(RollingPlanService::class)->read($this->f['actor'], $this->id(), $p['id'])['display_status']);
    }

    public function test_duplicate_scene_padding_is_blocked(): void
    {
        $s = $this->f['scene']->fresh();
        $r = ['title' => '重复', 'objective' => $s->objective, 'details' => array_replace($s->details, ['min_words' => 1000, 'target_words' => 1250, 'max_words' => 1500])];
        $this->runPlan('split', ['chapter_id' => $s->parent_id, 'scene_ids' => [$s->id], 'replacements' => [$r, $r]]);
        $this->assertContains('DUPLICATE_SCENE', $this->codes($this->save(array_replace($this->f['plan_payload'], ['coverages' => []]))));
    }

    public function test_api_auth_ownership_idempotency_and_stale_version(): void
    {
        $url = '/api/v1/novels/'.$this->id().'/plans/save';
        $d = $this->base($this->f['plan_payload']);
        $one = $this->withHeader('Idempotency-Key', 'same')->postJson($url, $d)->assertCreated()->json('data.plan.id');
        $this->withHeader('Idempotency-Key', 'same')->postJson($url, $d)->assertCreated()->assertJsonPath('data.plan.id', $one);
        $this->withHeader('Idempotency-Key', 'same')->postJson($url, array_replace($d, ['reason' => '不同']))->assertStatus(409);
        $this->withHeader('Idempotency-Key', 'other')->postJson($url, $d)->assertStatus(409);
        $this->actingAs(User::factory()->create(['is_admin' => false]))->getJson('/api/v1/novels/'.$this->id().'/plans/'.$one)->assertForbidden();
        auth()->logout();
        $this->getJson('/api/v1/novels/'.$this->id().'/plans')->assertUnauthorized();
    }

    public function test_formal_state_redirects_to_history_change_without_fake_commits(): void
    {
        Novel::whereKey($this->id())->update(['state_version' => 1]);
        try {
            $this->save();
            $this->fail();
        } catch (NovelProblem $e) {
            $this->assertSame('HISTORY_CHANGE_REQUIRED', $e->errorCode);
        }
        $this->expectException(NovelProblem::class);
        $this->expectExceptionMessage('正式历史结构不可直接修改');
        $this->change('chapter', $this->f['chapter']->fresh(), ['title' => '修改正式章']);
    }

    public function test_livewire_actual_save_check_freeze_and_http_page(): void
    {
        $ui = Livewire::test(RollingPlans::class, ['record' => $this->id()])->assertOk();
        $ui->mountAction('savePlan')->fillForm($this->base($this->f['plan_payload']) + ['command_key' => 'ui-save'])->callMountedAction()->assertHasNoActionErrors();
        $p = RollingPlan::first();
        $this->assertNotNull($p);
        $ui->mountAction('validatePlan')->fillForm($this->base(['rolling_plan_id' => $p->id]) + ['command_key' => 'ui-check'])->callMountedAction()->assertHasNoActionErrors();
        $c = PlanValidation::first();
        $this->assertTrue($c->report['passed']);
        $ui->mountAction('freezePlan')->fillForm($this->base(['rolling_plan_id' => $p->id, 'validation_id' => $c->id, 'report_hash' => $c->report_hash]) + ['command_key' => 'ui-freeze'])->callMountedAction()->assertHasNoActionErrors();
        $this->assertDatabaseCount('plan_freezes', 1);
        $this->get('/admin/novels/'.$this->id().'/plans')->assertOk();
    }

    public function test_hundred_chapter_skeleton_first_ten_and_next_window_are_separate(): void
    {
        $this->f = RollingPlanFixture::create(100, $this->f['actor']);
        $p = $this->save();
        $this->assertTrue($p['report']['passed'], json_encode($p['report']['blockers']));
        $this->assertCount(100, collect($p['nodes'])->where('node_type', 'chapter'));
        $this->freeze($p, $this->check($p));
        $d = array_replace($this->f['plan_payload'], ['start_chapter' => 11, 'end_chapter' => 15]);
        $next = $this->save($d);
        $this->assertContains('SCENE_MISSING', $this->codes($next));
        $this->assertContains('CHAPTER_INCOMPLETE', $this->codes($next));
        $sample = $this->f['scene']->fresh();
        foreach (Chapter::where('novel_id', $this->id())->whereBetween('sequence', [11, 15])->get() as $ch) {
            $this->change('chapter', $ch, ['status' => 'detailed', 'details' => ['min_words' => 2000, 'target_words' => 2500, 'max_words' => 3000]]);
            app(StructureService::class)->execute($this->f['actor'], $this->id(), 'node', $this->base(['type' => 'scene', 'parent_id' => $ch->id, 'source_version_id' => $ch->source_version_id, 'sequence' => 1, 'title' => '后续场景'.$ch->sequence, 'objective' => '本章独立目标', 'status' => 'detailed', 'details' => $sample->details]), (string) Str::uuid());
        }
        $ready = $this->save($d);
        $this->assertTrue($ready['report']['passed'], json_encode($ready['report']['blockers']));
        $this->assertSame(range(11, 15), $ready['report']['detailed_chapters']);
        $this->assertSame('frozen', app(RollingPlanService::class)->read($this->f['actor'], $this->id(), $p['id'])['display_status']);
        $this->freeze($ready, $this->check($ready));
    }

    public function test_unicode_evidence_uses_codepoints_and_api_returns_coverage(): void
    {
        $ch = $this->f['chapter']->fresh();
        $this->change('chapter', $ch, ['summary' => '甲😀乙']);
        $d = $this->f['plan_payload'];
        foreach ($d['coverages'] as &$c) {
            if ($c['type'] === 'chapter') {
                $c['quote'] = '😀';
                $c['start_offset'] = 1;
                $c['end_offset'] = 2;
            }
        } unset($c);
        $p = $this->save($d);
        $this->assertTrue($p['report']['passed']);
        $this->getJson('/api/v1/novels/'.$this->id().'/requirements')->assertOk()->assertJsonPath('data.items.0.coverage_status', 'covered');
        $bad = $d;
        foreach ($bad['coverages'] as &$c) {
            if ($c['type'] === 'chapter') {
                $c['end_offset'] = 3;
            }
        } unset($c);
        try {
            $this->save($bad);
            $this->fail();
        } catch (NovelProblem $e) {
            $this->assertSame('EVIDENCE_INVALID', $e->errorCode);
        }
    }

    public function test_cross_book_references_and_database_forged_evidence_are_rejected(): void
    {
        $other = BlueprintFixture::create($this->f['actor']);
        $d = $this->f['plan_payload'];
        $d['coverages'][0]['node_id'] = StoryArc::where('novel_id', $other['book']['novel']['id'])->value('id');
        try {
            $this->save($d);
            $this->fail();
        } catch (NovelProblem $e) {
            $this->assertSame('NOT_FOUND', $e->errorCode);
        }
        $p = $this->save();
        // 直接写入不能向已封存版本补证据，也不能伪造已通过的冻结凭证。
        try {
            DB::transaction(function () use ($p) {
                $c = RequirementCoverage::where('rolling_plan_id', $p['id'])->first()->replicate();
                $c->quote = '伪造';
                $c->save();
            });
            $this->fail();
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $c = $this->check($p);
        try {
            DB::transaction(fn () => PlanFreeze::create(['novel_id' => $this->id(), 'rolling_plan_id' => $p['id'], 'validation_id' => $c['id'], 'report_hash' => str_repeat('0', 64), 'reason' => '伪造', 'actor_id' => $this->f['actor']->id, 'created_at' => now()]));
            $this->fail();
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
    }

    public function test_budget_range_policy_and_split_rollback(): void
    {
        $s = $this->f['scene']->fresh();
        try {
            $this->runPlan('split', ['chapter_id' => $s->parent_id, 'scene_ids' => [$s->id], 'replacements' => [['title' => '甲', 'objective' => '甲', 'details' => ['min_words' => 1, 'target_words' => 1, 'max_words' => 1]], ['title' => '乙', 'objective' => '乙', 'details' => ['min_words' => 1, 'target_words' => 1, 'max_words' => 1]]]]);
            $this->fail();
        } catch (NovelProblem $e) {
            $this->assertSame('BUDGET_MISMATCH', $e->errorCode);
        }
        $this->assertFalse($s->fresh()->retired);
        $this->change('chapter', $this->f['chapter']->fresh(), ['details' => ['min_words' => 1000, 'target_words' => 500, 'max_words' => 4000]]);
        $codes = $this->codes($this->save());
        $this->assertContains('BUDGET_RANGE_INVALID', $codes);
        $this->assertContains('LENGTH_POLICY_MISMATCH', $codes);
    }

    public function test_changed_requirements_and_retired_world_reference_cannot_use_old_pass(): void
    {
        $p = $this->save();
        $c = $this->check($p);
        $intent = app(NovelIntentService::class);
        $r = $intent->requirements(Novel::find($this->id()))[0];
        $intent->execute($this->f['actor'], 'requirement', $this->id(), $this->base(array_replace($r, ['content' => $r['content'].' 新要求', 'author_decision' => '重新确认'])), (string) Str::uuid());
        $r = app(RollingPlanService::class)->read($this->f['actor'], $this->id(), $p['id']);
        $this->assertContains('BLUEPRINT_STALE', $this->codes($r));
        try {
            $this->freeze($p, $c);
            $this->fail();
        } catch (NovelProblem $e) {
            $this->assertSame('VALIDATION_STALE', $e->errorCode);
        }
    }
}
