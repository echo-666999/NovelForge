<?php

namespace Tests\Feature;

use App\Context\ContextComposer;
use App\Exceptions\NovelProblem;
use App\Filament\Pages\ContextInspector;
use App\Generation\StageResult;
use App\Models\Novel;
use App\Models\User;
use App\Services\AuthoringConfigurationService;
use App\Services\ContextAssemblyService;
use App\Services\ForeshadowLedgerService;
use App\Services\GenerationService;
use App\Services\HybridSearchService;
use App\Services\LayeredMemoryService;
use App\Services\RollingPlanService;
use App\Services\StoryConsistencyService;
use App\Services\StoryStateService;
use App\Support\ConfigurationExamples;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ProviderFixture;
use Tests\Support\RollingPlanFixture;
use Tests\Support\StoryConsistencyFixture;
use Tests\TestCase;

/** D06 使用真实 PostgreSQL、离线路由和作者确认数据，不调用任何真实模型。 */
class ContextAssemblyTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        $provider = ProviderFixture::create();
        $f = RollingPlanFixture::create(owner: $provider['actor']);
        $actor = $f['actor'];
        $novel = $f['book']['novel']['id'];
        $command = fn ($action, $data) => app(RollingPlanService::class)->execute($actor, $novel, $action, $data + ['expected_version' => Novel::find($novel)->row_version, 'reason' => '上下文验收'], (string) Str::uuid());
        $plan = $command('save', $f['plan_payload'])['plan'];
        $check = $command('validate', ['rolling_plan_id' => $plan['id']])['validation'];
        $command('freeze', ['rolling_plan_id' => $plan['id'], 'validation_id' => $check['id'], 'report_hash' => $check['report_hash']]);
        $examples = ConfigurationExamples::all();
        $release = function ($kind, $data) use ($actor) {
            $s = app(AuthoringConfigurationService::class);
            $v = $s->execute($actor, $kind, 'save', $data, (string) Str::uuid());
            $s->execute($actor, $kind, 'test', ['version_id' => $v['id']], (string) Str::uuid());

            return $s->execute($actor, $kind, 'publish', ['version_id' => $v['id']], (string) Str::uuid());
        };
        $prompt = $release('prompt', $examples['prompt']);
        $style = $release('style', $examples['style']);
        $route = $examples['route'];
        $route['content'] = array_replace($route['content'], ['prompt_version_id' => $prompt['id'], 'style_version_id' => $style['id'], 'primary_model_version_id' => $provider['model']['version_id']]);
        $route = $release('route', $route);
        $config = ['route_version_id' => $route['id'], 'variables' => ['topic' => '故乡']];
        $g = app(GenerationService::class);
        $run = $g->start($actor, $novel, ['expected_version' => Novel::find($novel)->row_version, 'outline_version_id' => $plan['outline_version_id'], 'configuration' => $config, 'billing' => $provider['payload']['billing'], 'nodes' => [['key' => 'first', 'stage' => 'test.echo', 'depends_on' => [], 'input' => ['text' => '前文']], ['key' => 'second', 'stage' => 'test.echo', 'depends_on' => ['first'], 'input' => ['text' => '后文']]]], (string) Str::uuid())['run'];
        $claim = $g->claim($actor, $novel, $run['id'], 'first');
        $g->complete($actor, $novel, $run['id'], $claim, new StageResult('succeeded', ['text' => '许砚失去右手。']));
        $source = app(StoryStateService::class)->registerArtifact($actor, $novel, (string) DB::table('generation_artifacts')->where('step_id', $claim['id'])->value('id'));
        $evidence = app(StoryStateService::class)->evidence($actor, $novel, $source, hash('sha256', '许砚失去右手。'), 0, mb_strlen('许砚失去右手。'), '许砚失去右手。');
        app(LayeredMemoryService::class)->execute($actor, $novel, 'fact', ['branch_id' => $run['id'], 'expected_version' => Novel::find($novel)->row_version, 'reason' => '强制事实', 'source_version_id' => $source, 'evidence_id' => $evidence, 'category' => 'irreversible', 'statement' => '许砚失去右手。', 'introduced_chapter' => 1], (string) Str::uuid());
        $step = $g->claim($actor, $novel, $run['id'], 'second')['id'];
        $input = ['scope' => 'candidate', 'plan_id' => $plan['id'], 'scene_id' => $f['scene']->id, 'pov_id' => $f['person']['entity']['id'], 'configuration' => $config, 'billing' => $provider['payload']['billing'], 'safety_margin' => 100, 'expected_version' => Novel::find($novel)->row_version, 'reason' => '离线上下文验收'];

        $definition = $f['person']['definition']['id'];

        return compact('actor', 'novel', 'step', 'input', 'source', 'evidence', 'definition');
    }

    private function assemble(array $f, array $extra = [], ?string $key = null): array
    {
        return app(ContextAssemblyService::class)->assemble($f['actor'], $f['step'], array_replace($f['input'], $extra), $key ?? (string) Str::uuid());
    }

    public function test_frozen_manifest_keeps_required_facts_zones_hashes_and_capacity(): void
    {
        $f = $this->fixture();
        $a = $this->assemble($f, key: 'same');
        $this->assertSame('assembled', $a['status']);
        $this->assertFalse($a['formal_usable']);
        $this->assertSame(hash('sha256', $a['final_prompt']), $a['final_prompt_hash']);
        $this->assertStringContainsString('许砚失去右手。', $a['final_prompt']);
        $fact = collect($a['items'])->firstWhere('source_type', 'memory_facts');
        $this->assertTrue($fact['required']);
        $this->assertTrue($fact['selected']);
        $this->assertSame('作者事实（不证明角色知情）', $fact['zone']);
        $this->assertNotEmpty(collect($a['items'])->firstWhere('category', 'ending'));
        foreach ($a['items'] as $item) {
            $this->assertSame(hash('sha256', $item['text']), $item['text_hash']);
            $this->assertNotSame('', $item['source_version']);
        }
        $this->assertSame($a['id'], $this->assemble($f, key: 'same')['id']);
        $b = $this->assemble($f, ['safety_margin' => 8000]);
        $this->assertSame('over_capacity', $b['status']);
        $this->assertTrue(collect($b['items'])->firstWhere('source_type', 'memory_facts')['selected']);
        $this->assertSame(0, DB::table('ai_requests')->count());
        Http::assertNothingSent();
    }

    public function test_formal_scope_blocks_and_old_snapshot_survives_invalidation(): void
    {
        $f = $this->fixture();
        $a = $this->assemble($f);
        try {
            $this->assemble($f, ['scope' => 'formal']);
            $this->fail('正式范围不得回退到候选分支');
        } catch (NovelProblem $e) {
            $this->assertSame('FORMAL_CONTEXT_UNAVAILABLE', $e->errorCode);
        }
        app(LayeredMemoryService::class)->execute($f['actor'], $f['novel'], 'invalidate', ['branch_id' => $a['branch_id'], 'expected_version' => $f['input']['expected_version'], 'reason' => '验证失效', 'source_version_id' => $f['source']], 'invalidate');
        $old = app(ContextAssemblyService::class)->read($f['actor'], $f['step'], $a['id']);
        $this->assertSame('stale', $old['current_status']);
        $this->assertSame($a['final_prompt'], $old['final_prompt']);
        $new = $this->assemble($f, ['expected_version' => Novel::find($f['novel'])->row_version]);
        $this->assertContains('MISSING_REQUIRED_FACT_SOURCE', $new['issues']);
        $this->assertSame('missing_required', $new['status']);
    }

    public function test_authorized_api_ui_compare_and_reassembly(): void
    {
        $f = $this->fixture();
        $a = $this->assemble($f);
        $url = '/api/v1/generation-steps/'.$f['step'].'/context';
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['is_admin' => false]))->getJson($url)->assertForbidden();
        $this->actingAs($f['actor'])->getJson($url)->assertOk()->assertJsonPath('data.id', $a['id'])->assertHeader('Cache-Control', 'no-store, private');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($f['actor'])->test(ContextInspector::class)->set('step', $f['step'])->call('inspect')->assertSet('detail.id', $a['id'])->set('requiredOnly', true)->assertSee('许砚失去右手。')->call('reassemble')->assertHasNoErrors()->set('comparison', $a['id'])->call('compare')->assertSet('difference.same_prompt', true);
        $this->assertDatabaseCount('context_manifests', 2);
    }

    public function test_due_foreshadow_and_facts_do_not_depend_on_top_k(): void
    {
        $f = $this->fixture();
        $branch = (string) DB::table('generation_steps')->where('id', $f['step'])->value('run_id');
        $command = function ($service, $action, $data) use ($f) {
            return app($service)->execute($f['actor'], $f['novel'], $action, $data + ['expected_version' => Novel::find($f['novel'])->row_version, 'reason' => '强制注入验收'], (string) Str::uuid());
        };
        $plan = array_fill_keys(ForeshadowLedgerService::TEXT, '到期伏笔秘密');
        $bp = (string) DB::table('rolling_plans')->where('id', $f['input']['plan_id'])->value('blueprint_version_id');
        $plan += ['blueprint_version_id' => $bp, 'thread_id' => null, 'plant_start' => 1, 'plant_end' => 1, 'payoff_target' => 2, 'payoff_start' => 2, 'payoff_end' => 2, 'required' => true, 'importance' => 'major', 'secrecy' => 'author_only', 'reinforcements' => [], 'entity_version_ids' => [$f['definition']], 'knower_version_ids' => [], 'prerequisite_event_ids' => []];
        $outline = DB::table('rolling_plans')->where('id', $f['input']['plan_id'])->value('outline_version_id');
        $chapter = DB::table('plan_nodes')->where('outline_version_id', $outline)->where('node_type', 'chapter')->where('sequence', 2)->value('id');
        $f['input']['scene_id'] = (string) DB::table('plan_nodes')->where('parent_plan_node_id', $chapter)->where('node_type', 'scene')->value('scene_id');
        $foreshadow = $command(ForeshadowLedgerService::class, 'create', ['plan' => $plan]);
        $profile = $command(HybridSearchService::class, 'profile', ['name' => '上下文离线档案', 'encoder' => 'offline_hash_v1', 'dimensions' => 128, 'distance_metric' => 'cosine'])['profile_id'];
        $build = $command(HybridSearchService::class, 'build', ['branch_id' => $branch, 'profile_id' => $profile])['build_id'];
        $command(HybridSearchService::class, 'document', ['build_id' => $build, 'source_version_id' => $f['source'], 'evidence_id' => $f['evidence'], 'title' => '右手', 'aliases' => [], 'introduced_chapter' => 1]);
        $command(HybridSearchService::class, 'validate', ['build_id' => $build]);
        $command(HybridSearchService::class, 'activate', ['build_id' => $build]);
        $a = $this->assemble($f, ['expected_version' => Novel::find($f['novel'])->row_version, 'search' => ['build_id' => $build, 'profile_id' => $profile, 'query' => '右手', 'limit' => 1, 'vector' => false]]);
        $this->assertCount(1, $a['retrieval']['results']);
        $this->assertTrue($a['retrieval']['degraded']);
        $due = collect($a['items'])->firstWhere('category', 'foreshadow');
        $this->assertSame($foreshadow['plan']['id'], $due['source_id']);
        $this->assertTrue($due['required']);
        $this->assertTrue($due['selected']);
        $this->assertSame('作者计划且角色未知', $due['zone']);
        $this->assertTrue(collect($a['items'])->firstWhere('source_type', 'memory_facts')['selected']);
    }

    public function test_future_acquisition_stays_out_of_pov_zone_and_known_fact_is_injected(): void
    {
        $f = $this->fixture();
        $s = app(StoryStateService::class);
        $events = [];
        foreach ([['secret', 1], ['past_acquisition', 1], ['future_acquisition', 2]] as $n => [$attribute, $chapter]) {
            $events[] = $s->propose($f['actor'], $f['novel'], ['evidence_id' => $f['evidence'], 'definition_version_id' => $f['definition'], 'fact_kind' => 'fact', 'action' => 'set', 'chapter_sequence' => $chapter, 'narrative_order' => $n + 1, 'attribute' => $attribute, 'value' => '右手受伤秘密', 'confidence' => 'confirmed', 'story_time' => [], 'reason' => 'POV 知情边界测试']);
        }
        $payload = ['expected_version' => 0, 'reason' => '未来获知不得提前', 'entries' => array_map(fn ($id, $n) => StoryConsistencyFixture::entry($id, $n * 10), $events, array_keys($events)), 'knowledge' => [['knower_id' => $f['input']['pov_id'], 'fact_event_id' => $events[0], 'acquired_event_id' => $events[2], 'method' => 'witness']]];
        $context = app(StoryConsistencyService::class)->save($f['actor'], $f['novel'], $payload, 'future');
        $a = $this->assemble($f, ['context_id' => $context['id']]);
        $pov = collect($a['items'])->firstWhere('category', 'knowledge_relationships');
        $this->assertSame([], json_decode($pov['text'], true)['knowledge']);
        $payload['expected_version'] = 1;
        $payload['knowledge'][0]['acquired_event_id'] = $events[1];
        $context = app(StoryConsistencyService::class)->save($f['actor'], $f['novel'], $payload, 'past');
        $b = $this->assemble($f, ['context_id' => $context['id']]);
        $povFacts = collect($b['items'])->filter(fn ($i) => $i['category'] === 'key_fact' && str_starts_with($i['zone'], 'POV'));
        $this->assertCount(1, $povFacts);
        $this->assertSame($events[0], $povFacts->first()['source_id']);
        $this->assertTrue($povFacts->first()['required']);

        // 同书另一候选分支的时间线也不能借作者区进入当前上下文。
        $g = app(GenerationService::class);
        $outline = (string) DB::table('rolling_plans')->where('id', $f['input']['plan_id'])->value('outline_version_id');
        $other = $g->start($f['actor'], $f['novel'], ['expected_version' => Novel::find($f['novel'])->row_version, 'outline_version_id' => $outline, 'nodes' => [['key' => 'other', 'stage' => 'test.echo', 'depends_on' => [], 'input' => ['text' => '另一分支秘密']]]], 'other-branch')['run'];
        $claim = $g->claim($f['actor'], $f['novel'], $other['id'], 'other');
        $g->complete($f['actor'], $f['novel'], $other['id'], $claim, new StageResult('succeeded', ['text' => '另一分支秘密']));
        $source = $s->registerArtifact($f['actor'], $f['novel'], (string) DB::table('generation_artifacts')->where('step_id', $claim['id'])->value('id'));
        $evidence = $s->evidence($f['actor'], $f['novel'], $source, hash('sha256', '另一分支秘密'), 0, mb_strlen('另一分支秘密'), '另一分支秘密');
        $event = $s->propose($f['actor'], $f['novel'], ['evidence_id' => $evidence, 'definition_version_id' => $f['definition'], 'fact_kind' => 'fact', 'action' => 'set', 'chapter_sequence' => 1, 'narrative_order' => 1, 'attribute' => 'foreign_secret', 'value' => '另一分支秘密', 'confidence' => 'confirmed', 'story_time' => [], 'reason' => '分支隔离']);
        $foreign = app(StoryConsistencyService::class)->save($f['actor'], $f['novel'], ['expected_version' => 2, 'reason' => '分支隔离', 'entries' => [StoryConsistencyFixture::entry($event, 0)]], 'foreign-context');
        $blocked = $this->assemble($f, ['context_id' => $foreign['id'], 'expected_version' => Novel::find($f['novel'])->row_version]);
        $this->assertContains('INVALID_KNOWLEDGE_SOURCE', $blocked['issues']);
        $this->assertSame('missing_required', $blocked['status']);
        $this->assertStringNotContainsString('另一分支秘密', $blocked['final_prompt']);
    }

    public function test_database_rejects_mutation_and_all_columns_have_comments(): void
    {
        $f = $this->fixture();
        $a = $this->assemble($f);
        $missing = DB::selectOne("SELECT count(*) AS n FROM pg_attribute WHERE attrelid='context_manifests'::regclass AND attnum>0 AND NOT attisdropped AND col_description(attrelid,attnum) IS NULL");
        $this->assertSame(0, (int) $missing->n);
        $this->expectException(QueryException::class);
        DB::table('context_manifests')->where('id', $a['id'])->update(['payload' => '{}']);
    }

    public function test_budget_counts_separate_reasoning_once_and_preserves_hard_constraints(): void
    {
        $items = [['category' => 'hard_constraint', 'zone' => '系统', 'text' => '甲', 'required' => true], ['category' => 'memory', 'zone' => '作者', 'text' => str_repeat('乙', 100), 'required' => false, 'score' => 0.1]];
        $c = app(ContextComposer::class);
        $included = $c->compose($items, 100, 10, 20, 'included', 5);
        $separate = $c->compose($items, 100, 10, 20, 'separate', 5);
        $this->assertSame(20, $separate['budget']['total_tokens'] - $included['budget']['total_tokens']);
        $this->assertSame('low_priority_capacity', $included['items'][1]['trim_reason']);
        $this->assertTrue($included['items'][0]['selected']);
        $this->assertSame('over_capacity', $c->compose($items, 1, 0, 0, 'none', 0)['status']);
        $duplicate = $c->compose([$items[0], $items[0] + [], array_replace($items[0], ['required' => false])], 1000, 0, 0, 'none', 0);
        $this->assertSame('duplicate', $duplicate['items'][2]['trim_reason']);
    }
}
