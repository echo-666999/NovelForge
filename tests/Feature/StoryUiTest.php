<?php

namespace Tests\Feature;

use App\Filament\Pages\ForeshadowLedger;
use App\Filament\Pages\StoryTimeline;
use App\Filament\Resources\NovelResource\Pages\Blueprints;
use App\Filament\Resources\NovelResource\Pages\CreateNovel;
use App\Filament\Resources\NovelResource\Pages\NovelIntent;
use App\Filament\Resources\NovelResource\Pages\RollingPlans;
use App\Filament\Resources\NovelResource\Pages\Structure;
use App\Filament\Resources\NovelResource\Pages\Workspace;
use App\Filament\Resources\NovelResource\Pages\WorldLibrary;
use App\Models\Chapter;
use App\Models\Novel;
use App\Models\User;
use App\Services\WorldService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\CanonicalFixture;
use Tests\Support\RollingPlanFixture;
use Tests\TestCase;

/** UI06只验证故事编辑与只读展示；全部夹具及正式提交证据均为隔离库离线数据。 */
class StoryUiTest extends TestCase
{
    use RefreshDatabase;

    private function actor($actor = null)
    {
        Http::preventStrayRequests();
        $actor ??= User::factory()->create(['is_admin' => true]);
        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $actor;
    }

    public function test_option_adapter_keeps_unicode_zero_escaping_and_selected_multi_values(): void
    {
        $this->actor();
        $html = Blade::render('<x-nf.story-select id="adapter" label="中文菜单" model="status" value="0"><option value="0">第零项</option><option value="x">&lt;script&gt;bad&lt;/script&gt;</option></x-nf.story-select>');
        $this->assertStringContainsString('第零项', $html);
        $this->assertStringContainsString('中文菜单', $html);
        $this->assertStringNotContainsString('<script>bad</script>', $html);
        $html = Blade::render('<x-nf.story-select id="multi" label="实际引用" model="references" :multiple="true" :value="[\'0\',\'2\']"><option value="0">甲</option><option value="1">乙</option><option value="2">丙</option></x-nf.story-select>');
        $this->assertSame(2, substr_count($html, 'checked'));
        $this->assertSame(3, substr_count($html, 'type="checkbox"'));
        $this->assertStringContainsString('wire:model.live="references"', $html);
        Http::assertNothingSent();
    }

    public function test_story_pages_use_structured_readonly_views_and_do_not_write_on_render(): void
    {
        $f = RollingPlanFixture::create();
        $this->actor($f['actor']);
        $id = $f['book']['novel']['id'];
        $before = DB::table('novel_commands')->count();
        $hashes = DB::table('outline_versions')->pluck('content_hash', 'id')->all();
        foreach ([Workspace::class, NovelIntent::class, Structure::class, RollingPlans::class, Blueprints::class, WorldLibrary::class] as $page) {
            Livewire::test($page, ['record' => $id])->assertSee('nf-story-screen', false)->assertDontSeeHtml('<pre');
        }
        Livewire::test(StoryTimeline::class)->set('novel', $id)->assertDontSeeHtml('<pre');
        Livewire::test(ForeshadowLedger::class)->set('novel', $id)->assertDontSee('正式提交尚未接入')->assertSee('暂无符合条件');
        $this->assertSame($before, DB::table('novel_commands')->count());
        $this->assertSame($hashes, DB::table('outline_versions')->pluck('content_hash', 'id')->all());
        $this->assertDatabaseCount('ai_requests', 0);
        Http::assertNothingSent();
    }

    public function test_hierarchy_expansion_focus_and_immutable_version_are_readonly(): void
    {
        $f = RollingPlanFixture::create();
        $this->actor($f['actor']);
        $id = $f['book']['novel']['id'];
        $page = Livewire::test(Structure::class, ['record' => $id]);
        $nodes = $page->instance()->state()['nodes'];
        $volume = collect($nodes)->firstWhere('type', 'volume');
        $page->call('toggleStoryBranch', 'volume', $volume['id'])->assertSet('treeOpen.volume-'.$volume['id'], true)
            ->call('focusStoryNode', 'scene', $f['scene']->id)->assertSet('type', 'scene')->assertSet('parent', 'chapter:'.$f['chapter']->id);
        $this->assertCount(1, $page->instance()->rows()->items());
        $page->set('versionId', $f['blueprint']['outline_version_id'])->assertActionHidden('node')->assertActionHidden('snapshot');
        $this->assertSame(0, Novel::find($id)->state_version);
        $this->assertDatabaseCount('canonical_commits', 0);
        Http::assertNothingSent();
    }

    public function test_blueprint_tabs_copy_to_new_version_without_mutating_history(): void
    {
        $f = RollingPlanFixture::create();
        $this->actor($f['actor']);
        $id = $f['book']['novel']['id'];
        $old = $f['blueprint'];
        $page = Livewire::test(Blueprints::class, ['record' => $id])->mountAction(TestAction::make('save')->arguments(['blueprint' => $old['id']]));
        $page->fillForm(['reason' => '分签表单保留历史', 'body' => ['logline' => '新的蓝图说明']])->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame($old['content_hash'], DB::table('blueprint_versions')->where('id', $old['id'])->value('content_hash'));
        $this->assertSame($old['content']['body']['logline'], json_decode(DB::table('blueprint_versions')->where('id', $old['id'])->value('content'), true)['body']['logline']);
        Http::assertNothingSent();
    }

    public function test_formal_evidence_is_read_from_active_baseline_and_structure_is_readonly(): void
    {
        $f = CanonicalFixture::create();
        CanonicalFixture::commit($f);
        $this->actor($f['actor']);
        $page = Livewire::test(ForeshadowLedger::class)->set('novel', $f['novel']);
        $ledger = $page->instance()->ledger();
        $item = collect($ledger['items'])->firstWhere('id', $f['foreshadow']['id']);
        $this->assertSame('connected', $ledger['formal_progress']);
        $this->assertSame(1, $item['evidence_count']);
        $this->assertSame(1, $item['first_actual_plant']);
        $page->assertDontSee('正式提交尚未接入')->assertDontSee('埋设、强化和回收证据均未成立');
        Livewire::test(Structure::class, ['record' => $f['novel']])->assertActionHidden('node');
        $this->assertDatabaseCount('canonical_commits', 1);
        Http::assertNothingSent();
    }

    public function test_create_wizard_keeps_original_fields_and_never_calls_model(): void
    {
        $this->actor();
        Livewire::test(CreateNovel::class)->assertSee('原始背景')->assertSee('剧情概述')->assertSee('提炼约束')->assertSee('确认歧义');
        $this->assertDatabaseCount('novels', 0);
        $this->assertDatabaseCount('ai_requests', 0);
        Http::assertNothingSent();
    }

    /** 原生覆盖选择器保存/检查/冻结后，结构只能读，伪造编辑也不能改历史。 */
    public function test_native_plan_fields_freeze_gate_and_no_overwrite(): void
    {
        $f = RollingPlanFixture::create(12);
        $this->actor($f['actor']);
        $id = $f['book']['novel']['id'];
        $page = Livewire::test(RollingPlans::class, ['record' => $id])->mountAction('savePlan')->fillForm($f['plan_payload'] + ['reason' => '实际节点覆盖字段'])->callMountedAction()->assertHasNoActionErrors();
        $plan = $page->get('selectedId');
        $this->assertNotSame('', $plan);
        $page->mountAction('validatePlan')->fillForm(['rolling_plan_id' => $plan, 'reason' => '检查实际覆盖'])->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('ready', $page->instance()->selected()['display_status']);
        $page->mountAction('freezePlan')->fillForm(['reason' => '作者确认冻结窗口'])->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('frozen', $page->instance()->selected()['display_status']);
        $structure = Livewire::test(Structure::class, ['record' => $id]);
        $this->assertTrue($structure->instance()->nodeFrozen('scene', $f['scene']->id));
        $future = Chapter::where('novel_id', $id)->where('sequence', 11)->firstOrFail();
        $this->assertFalse($structure->instance()->nodeFrozen('chapter', $future->id));
        $old = $f['scene']->fresh()->toArray();
        $structure->mountAction(TestAction::make('node')->arguments(['node_type' => 'scene', 'node' => $f['scene']->id]))->fillForm(['title' => '不可覆盖冻结场景', 'reason' => '校验冻结守卫'])->callMountedAction()->assertNotified('规划窗口已冻结，请走历史变更流程。');
        $this->assertSame($old, $f['scene']->fresh()->toArray());
        $this->assertDatabaseCount('plan_freezes', 1);
        Http::assertNothingSent();
    }

    /** 场景重排使用所属章节的实际场景选项，不再手填ID。 */
    public function test_native_scene_selector_resolves_repeater_parent_chapter(): void
    {
        $f = RollingPlanFixture::create();
        $this->actor($f['actor']);
        $id = $f['book']['novel']['id'];
        $commands = DB::table('novel_commands')->count();
        Livewire::test(RollingPlans::class, ['record' => $id])->mountAction('transform')->fillForm(['operation' => 'reorder', 'chapter_id' => $f['chapter']->id, 'ordered_scenes' => [['id' => $f['scene']->id]], 'replacements' => [], 'reason' => '保持单场景真实顺序'])->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame($commands + 1, DB::table('novel_commands')->count());
        $this->assertSame(1, $f['scene']->fresh()->sequence);
        $this->assertDatabaseCount('canonical_commits', 0);
        Http::assertNothingSent();
    }

    /** 新版批准不能挤掉仍有效的旧POV；未批准候选不能混入选项。 */
    public function test_pov_options_keep_approved_history_and_native_edit_keeps_reference(): void
    {
        $f = RollingPlanFixture::create();
        $this->actor($f['actor']);
        $id = $f['book']['novel']['id'];
        $old = $f['person']['definition'];
        $entity = $f['person']['entity']['id'];
        $run = fn ($command, $data) => app(WorldService::class)->execute($f['actor'], $id, $command, $data + ['expected_version' => Novel::find($id)->row_version, 'reason' => '历史POV引用验证'], (string) Str::uuid());
        $payload = $old['definition'];
        $payload['entity_id'] = $entity;
        $payload['description'] = '批准的新人物说明';
        $new = $run('definition', $payload)['definition'];
        $run('approve', ['definition_version_id' => $new['id']]);
        $payload['description'] = '尚未批准的人物候选';
        $candidate = $run('definition', $payload)['definition'];
        $page = Livewire::test(Structure::class, ['record' => $id]);
        $options = $page->instance()->povOptions();
        $this->assertArrayHasKey($old['id'], $options);
        $this->assertArrayHasKey($new['id'], $options);
        $this->assertArrayNotHasKey($candidate['id'], $options);
        $this->assertSame($old['id'], app(WorldService::class)->assertReference($f['actor'], $id, $old['id'])['definition_version_id']);
        $page->mountAction(TestAction::make('node')->arguments(['node_type' => 'scene', 'node' => $f['scene']->id]))->fillForm(['title' => '保留历史POV的场景', 'reason' => '只改标题，保持原批准引用'])->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('保留历史POV的场景', $f['scene']->fresh()->title);
        $this->assertSame($old['id'], $f['scene']->fresh()->details['pov_version_id']);
        Http::assertNothingSent();
    }
}
