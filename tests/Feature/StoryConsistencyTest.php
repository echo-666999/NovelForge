<?php

namespace Tests\Feature;

use App\Exceptions\NovelProblem;
use App\Filament\Pages\StoryTimeline;
use App\Models\Novel;
use App\Models\User;
use App\Services\NovelIntentService;
use App\Services\StoryConsistencyService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\StoryConsistencyFixture as F;
use Tests\TestCase;

/** 验证双时间、知识路径与确定性约束，所有结果仍为候选。 */
class StoryConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function save(array $f, array $p, int $expected = 0): array
    {
        return app(StoryConsistencyService::class)->save($f['actor'], $f['novelId'], $p + ['expected_version' => $expected, 'reason' => '作者确认测试候选与结构化规则'], (string) Str::uuid());
    }

    private function codes(array $r): array
    {
        return array_column($r['report']['issues'], 'code');
    }

    private function rejected(callable $fn, string $code): void
    {
        try {
            $fn();
            $this->fail('非法输入应拒绝');
        } catch (NovelProblem $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    private function rule(array $f, string $kind, string $subject, ?string $target, int $limit): array
    {
        return ['kind' => $kind, 'rule_version_id' => $f['rule'][0]['id'], 'subject_id' => $subject, 'target_id' => $target, 'limit_value' => $limit];
    }

    public function test_flashback_unknown_and_impossible_travel_have_distinct_outcomes(): void
    {
        $f = F::create();
        $a = F::event($f, 1);
        $b = F::event($f, 2);
        $flashback = $this->save($f, ['entries' => [F::entry($a, 1000), F::entry($b, 0)], 'dependencies' => [['predecessor_id' => $b, 'successor_id' => $a, 'relation_type' => 'before']]]);
        $this->assertSame('consistent', $flashback['report']['status']);
        $unknown = $this->save($f, ['entries' => [F::entry($a, null)]], 1);
        $this->assertSame('ambiguous', $unknown['report']['status']);
        $this->assertNull($unknown['payload']['entries'][0]['time']['start']);
        $from = $f['location'][0]['entity_id'];
        $to = $f['location'][1]['entity_id'];
        $p = ['entries' => [F::entry($a, 0, extra: ['activity' => true, 'location_id' => $from]), F::entry($b, 100, extra: ['activity' => true, 'location_id' => $to])], 'rules' => [$this->rule($f, 'travel', $from, $to, 600)]];
        $bad = $this->save($f, $p, 2);
        $this->assertSame('conflict', $bad['report']['status']);
        $this->assertContains('TRAVEL_TOO_FAST', $this->codes($bad));
        $this->assertSame('许砚', $bad['report']['issues'][0]['evidence'][0]['quote']);
        $this->assertNotEmpty($bad['report']['issues'][0]['recheck_event_ids']);
        $p['entries'][1]['time']['start'] = $p['entries'][1]['time']['end'] = 700;
        $this->assertSame('consistent', $this->save($f, $p, 3)['report']['status']);
        $this->assertSame(0, Novel::find($f['novelId'])->state_version);
        $this->assertDatabaseCount('story_events', 0);
    }

    public function test_ranges_absolute_dates_mixed_clocks_and_dependency_cycles(): void
    {
        $f = F::create();
        $a = F::event($f, 1);
        $b = F::event($f, 2);
        $p = ['entries' => [F::entry($a, 0, 100), F::entry($b, 50, 150)], 'dependencies' => [['predecessor_id' => $a, 'successor_id' => $b, 'relation_type' => 'before']]];
        $this->assertSame('ambiguous', $this->save($f, $p)['report']['status']);
        $p['entries'] = [['event_id' => $a, 'time' => ['kind' => 'exact', 'clock' => 'utc', 'start' => '2026-10-24T08:00:00+08:00', 'end' => '2026-10-24T00:00:00+00:00']], F::entry($b, 60)];
        $this->assertSame('ambiguous', $this->save($f, $p, 1)['report']['status']);
        $p['dependencies'][] = ['predecessor_id' => $b, 'successor_id' => $a, 'relation_type' => 'depends_on'];
        $this->rejected(fn () => $this->save($f, $p, 2), 'DEPENDENCY_CYCLE');
        $p['dependencies'] = [];
        $p['entries'][0]['time']['start'] = '2026-02-30T00:00:00+00:00';
        $this->rejected(fn () => $this->save($f, $p, 2), 'INVALID_TIME');
        $p['entries'] = [['event_id' => $a, 'time' => ['kind' => 'unknown', 'start' => 0]]];
        $this->rejected(fn () => $this->save($f, $p, 2), 'INVALID_TIME');
        $this->assertDatabaseCount('story_context_versions', 2);
    }

    public function test_death_activity_and_explicit_revival_are_checked_on_actual_time(): void
    {
        $f = F::create();
        $dead = F::event($f, 1, ['attribute' => 'life_status', 'value' => 'dead']);
        $action = F::event($f, 2);
        $alive = F::event($f, 3, ['attribute' => 'life_status', 'value' => 'alive']);
        $p = ['entries' => [F::entry($dead, 10), F::entry($action, 30, extra: ['activity' => true])]];
        $this->assertContains('ACTION_AFTER_DEATH', $this->codes($this->save($f, $p)));
        $p['entries'][1] = F::entry($action, 0, extra: ['activity' => true]);
        $this->assertSame('consistent', $this->save($f, $p, 1)['report']['status']);
        $p['entries'][1] = F::entry($action, 30, extra: ['activity' => true]);
        $p['entries'][] = F::entry($alive, 20);
        $this->assertContains('UNEXPLAINED_REVIVAL', $this->codes($this->save($f, $p, 2)));
        $p['entries'][2]['revival_rule_id'] = $f['rule'][0]['id'];
        $p['rules'] = [$this->rule($f, 'revival', $f['characters'][0]['entity_id'], null, 1)];
        $this->assertSame('consistent', $this->save($f, $p, 3)['report']['status']);
    }

    public function test_location_and_ability_limits_are_not_inferred_from_narrative(): void
    {
        $f = F::create();
        $a = F::event($f, 1);
        $b = F::event($f, 2);
        $entry = ['activity' => true, 'ability_id' => $f['ability'][0]['entity_id'], 'ability_units' => 4];
        $p = ['entries' => [F::entry($a, 0, extra: $entry + ['location_id' => $f['location'][0]['entity_id']]), F::entry($b, 0, extra: ['activity' => true, 'location_id' => $f['location'][1]['entity_id']])], 'rules' => [$this->rule($f, 'ability', $f['characters'][0]['entity_id'], $f['ability'][0]['entity_id'], 3)]];
        $r = $this->save($f, $p);
        $this->assertContains('INCOMPATIBLE_LOCATIONS', $this->codes($r));
        $this->assertContains('ABILITY_EXCEEDED', $this->codes($r));
        $p['entries'] = [F::entry($a, 0, extra: array_replace($entry, ['ability_units' => 3]))];
        $this->assertSame('consistent', $this->save($f, $p, 1)['report']['status']);
        $p['rules'] = [];
        $this->assertContains('ABILITY_LIMIT_UNKNOWN', $this->codes($this->save($f, $p, 2)));
    }

    public function test_unique_item_ownership_requires_transfer_and_belief_is_separate(): void
    {
        $f = F::create();
        $one = $f['characters'][0]['entity_id'];
        $two = $f['characters'][1]['entity_id'];
        $a = F::event($f, 1, ['definition_version_id' => $f['item'][0]['id'], 'attribute' => 'owner_id', 'value' => $one]);
        $b = F::event($f, 2, ['definition_version_id' => $f['item'][0]['id'], 'attribute' => 'owner_id', 'value' => $two]);
        $p = ['entries' => [F::entry($a, 0), F::entry($b, 0)]];
        $this->assertContains('DOUBLE_OWNERSHIP', $this->codes($this->save($f, $p)));
        $p['entries'][1] = F::entry($b, 10);
        $this->assertContains('OWNERSHIP_TRANSFER_MISSING', $this->codes($this->save($f, $p, 1)));
        $p['entries'][1]['previous_owner_id'] = $one;
        $this->assertSame('consistent', $this->save($f, $p, 2)['report']['status']);
        $belief = F::event($f, 3, ['attribute' => 'life_status', 'value' => 'dead', 'fact_kind' => 'belief', 'holder_id' => $one]);
        $action = F::event($f, 4);
        $this->assertSame('consistent', $this->save($f, ['entries' => [F::entry($belief, 0), F::entry($action, 10, extra: ['activity' => true])]], 3)['report']['status']);
    }

    public function test_secret_requires_acquisition_and_telling_requires_prior_knowledge(): void
    {
        $f = F::create();
        $one = $f['characters'][0]['entity_id'];
        $two = $f['characters'][1]['entity_id'];
        $fact = F::event($f, 1, ['fact_kind' => 'hidden_truth', 'value' => '封记被调换']);
        $acquire = F::event($f, 2);
        $told = F::event($f, 3, ['definition_version_id' => $f['characters'][1]['id']]);
        $p = ['entries' => [F::entry($fact, 0), F::entry($acquire, 10), F::entry($told, 20)], 'knowledge' => [['knower_id' => $two, 'fact_event_id' => $fact, 'acquired_event_id' => $told, 'method' => 'told', 'source_knower_id' => $one]]];
        $bad = $this->save($f, $p);
        $this->assertContains('KNOWLEDGE_PATH_MISSING', $this->codes($bad));
        $svc = app(StoryConsistencyService::class);
        $this->assertSame([], $svc->pov($f['actor'], $f['novelId'], $bad['id'], $two, 3)['knowledge']);
        $p['knowledge'][] = ['knower_id' => $one, 'fact_event_id' => $fact, 'acquired_event_id' => $acquire, 'method' => 'witness'];
        $r = $this->save($f, $p, 1);
        $this->assertSame('consistent', $r['report']['status']);
        $this->assertSame([], $svc->pov($f['actor'], $f['novelId'], $r['id'], $two, 2)['knowledge']);
        $known = $svc->pov($f['actor'], $f['novelId'], $r['id'], $two, 3)['knowledge'];
        $this->assertSame('封记被调换', $known[0]['value']);
        $this->assertSame('told', $known[0]['method']);
        $this->assertSame([], $svc->pov($f['actor'], $f['novelId'], $r['id'], $one, 1)['knowledge']);
        $p['knowledge'][1]['method'] = 'inference';
        $inferred = $this->save($f, $p, 2);
        $this->assertSame('inference', $svc->pov($f['actor'], $f['novelId'], $inferred['id'], $two, 3)['knowledge'][0]['knowledge_kind']);
    }

    public function test_relationship_changes_need_objective_event_and_pov_path(): void
    {
        $f = F::create();
        $one = $f['characters'][0]['entity_id'];
        $two = $f['characters'][1]['entity_id'];
        $event = F::event($f, 1);
        $acquire = F::event($f, 2);
        $p = ['entries' => [F::entry($event, 0), F::entry($acquire, 1)], 'relationships' => [['event_id' => $event, 'from_entity_id' => $one, 'to_entity_id' => $two, 'relation_type' => '信任', 'state' => '谨慎合作']]];
        $r = $this->save($f, $p);
        $s = app(StoryConsistencyService::class);
        $this->assertSame([], $s->pov($f['actor'], $f['novelId'], $r['id'], $one, 2)['relationships']);
        $p['knowledge'] = [['knower_id' => $one, 'fact_event_id' => $event, 'acquired_event_id' => $acquire, 'method' => 'witness']];
        $r = $this->save($f, $p, 1);
        $this->assertSame('谨慎合作', $s->pov($f['actor'], $f['novelId'], $r['id'], $one, 2)['relationships'][0]['state']);
        $p['relationships'][0]['from_entity_id'] = $two;
        $p['relationships'][0]['to_entity_id'] = $one;
        $this->assertContains('RELATION_EVENT_INVALID', $this->codes($this->save($f, $p, 2)));
    }

    public function test_api_idempotence_version_checks_ownership_and_no_formal_mutation(): void
    {
        $f = F::create();
        $a = F::event($f, 1);
        $url = '/api/v1/novels/'.$f['novelId'].'/story-contexts';
        $this->getJson($url)->assertUnauthorized();
        $p = ['entries' => [F::entry($a, 0)], 'expected_version' => 0, 'reason' => '接口测试'];
        $r = $this->actingAs($f['actor'])->postJson($url, $p, ['Idempotency-Key' => 'd02'])->assertOk()->json('data');
        $this->postJson($url, $p, ['Idempotency-Key' => 'd02'])->assertOk()->assertJsonPath('data.id', $r['id']);
        $this->postJson($url, $p, ['Idempotency-Key' => 'd02-new'])->assertConflict()->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->postJson($url, array_replace($p, ['reason' => '不同']), ['Idempotency-Key' => 'd02'])->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->getJson($url.'/'.$r['id'])->assertOk()->assertJsonPath('data.canonical', false);
        $this->getJson($url.'/'.$r['id'].'/pov?knower_id='.$f['characters'][0]['entity_id'].'&chapter=1')->assertOk()->assertJsonCount(0, 'data.knowledge');
        $other = User::factory()->create();
        $other->is_admin = true;
        $this->actingAs($other)->getJson($url.'/'.$r['id'])->assertNotFound();
        $this->assertDatabaseCount('story_context_versions', 1);
        $this->assertDatabaseCount('story_events', 0);
        $this->assertTrue(DB::table('audit_events')->where('object_type', 'story_context_versions')->exists());
    }

    public function test_filament_empty_state_editing_filters_and_historical_versions(): void
    {
        $f = F::create();
        $a = F::event($f, 1);
        $b = F::event($f, 2, ['fact_kind' => 'hidden_truth', 'value' => '<script>秘密</script>']);
        $page = Livewire::actingAs($f['actor'])->test(StoryTimeline::class)->assertSee('请选择小说')->set('novel', $f['novelId'])->set('eventIds', [$a, $b])->set('reason', '页面离线验证')->call('createContext')->assertSee('待确认');
        $old = $page->get('context');
        $page->set('event', $a)->set('timeKind', 'exact')->set('start', '0')->call('reviseTime');
        $this->assertNotSame($old, $page->get('context'));
        $page->set('secretsOnly', true)->assertDontSeeHtml('<script>秘密</script>');
        $this->assertCount(1, $page->instance()->visibleEvents());
        $page->set('context', $old)->call('inspect');
        $this->assertNull($page->get('detail')['payload']['entries'][0]['time']['start']);
        $page->set('novel', '999999')->call('reviseTime')->assertSee('请先读取当前小说');
        $this->assertDatabaseCount('story_context_versions', 2);
    }

    public function test_pending_and_conflicting_acquisitions_never_become_usable_pov(): void
    {
        $f = F::create();
        $dead = F::event($f, 1, ['attribute' => 'life_status', 'value' => 'dead']);
        $secret = F::event($f, 2, ['fact_kind' => 'hidden_truth', 'value' => '秘密']);
        $acquire = F::event($f, 3);
        $p = ['entries' => [F::entry($dead, 0), F::entry($secret, 1), F::entry($acquire, 2, extra: ['activity' => false])], 'knowledge' => [['knower_id' => $f['characters'][0]['entity_id'], 'fact_event_id' => $secret, 'acquired_event_id' => $acquire, 'method' => 'witness']]];
        $r = $this->save($f, $p);
        $this->assertContains('ACTION_AFTER_DEATH', $this->codes($r));
        $this->assertSame(0, $r['report']['validated_knowledge_count']);
        $s = app(StoryConsistencyService::class);
        $pov = $s->pov($f['actor'], $f['novelId'], $r['id'], $f['characters'][0]['entity_id'], 3);
        $this->assertFalse($pov['usable']);
        $this->assertSame([], $pov['knowledge']);
        $p['entries'] = [F::entry($secret, 1), F::entry($acquire, null)];
        $r = $this->save($f, $p, 1);
        $this->assertSame('ambiguous', $r['report']['status']);
        $this->assertSame([], $s->pov($f['actor'], $f['novelId'], $r['id'], $f['characters'][0]['entity_id'], 3)['knowledge']);
    }

    public function test_rule_and_event_scope_duplicates_and_stale_relations_are_rejected(): void
    {
        $f = F::create();
        $a = F::event($f, 1);
        $p = ['entries' => [F::entry($a, 0)], 'rules' => [$this->rule($f, 'ability', $f['characters'][0]['entity_id'], $f['ability'][0]['entity_id'], 3)]];
        $p['rules'][] = $p['rules'][0];
        $this->rejected(fn () => $this->save($f, $p), 'DUPLICATE_CONTEXT_ROW');
        $p['rules'] = [$p['rules'][0]];
        $p['rules'][0]['rule_version_id'] = $f['characters'][0]['id'];
        $this->rejected(fn () => $this->save($f, $p), 'INVALID_RULE');
        $other = app(NovelIntentService::class)->execute($f['actor'], 'create', null, ['title' => '另一小说', 'genre' => '悬疑', 'background' => '测试', 'premise_raw' => '独立故事', 'target_chapters' => 10, 'length_policy' => ['min' => 2000, 'max' => 3000]], 'd02-other');
        $this->rejected(fn () => app(StoryConsistencyService::class)->save($f['actor'], $other['novel']['id'], ['entries' => [F::entry($a, 0)], 'expected_version' => 0, 'reason' => '禁止跨书'], 'other'), 'NOT_FOUND');
        $this->assertDatabaseCount('story_context_versions', 0);
    }

    public function test_hidden_death_is_checked_without_promoting_it_into_character_knowledge(): void
    {
        $f = F::create();
        $dead = F::event($f, 1, ['fact_kind' => 'hidden_truth', 'attribute' => 'life_status', 'value' => 'dead']);
        $action = F::event($f, 2);
        $entries = [F::entry($dead, 0), F::entry($action, 1)];
        foreach ($entries as &$entry) {
            $entry['time'] = ['kind' => 'exact', 'clock' => 'utc', 'start' => $entry['event_id'] === $dead ? '2026-10-24T00:00:00Z' : '2026-10-24T00:00:01Z', 'end' => $entry['event_id'] === $dead ? '2026-10-24T00:00:00+00:00' : '2026-10-24T00:00:01+00:00'];
        }
        unset($entry);
        $r = $this->save($f, ['entries' => $entries]);
        $this->assertContains('ACTION_AFTER_DEATH', $this->codes($r));
        $pov = app(StoryConsistencyService::class)->pov($f['actor'], $f['novelId'], $r['id'], $f['characters'][0]['entity_id'], 1);
        $this->assertSame([], $pov['knowledge']);
        $this->assertIsString($r['events'][0]['novel_id']);
        $this->assertIsString($r['events'][0]['definition_version_id']);
        $this->assertStringContainsString('T', $r['events'][0]['created_at']);
    }

    public function test_database_immutability_scope_constraints_and_all_chinese_comments(): void
    {
        $f = F::create();
        $a = F::event($f, 1);
        $r = $this->save($f, ['entries' => [F::entry($a, 0)]]);
        foreach (['story_context_versions', 'story_time_entries'] as $table) {
            try {
                DB::transaction(fn () => DB::table($table)->update(['novel_id' => $f['novelId']]));
                $this->fail('封存后不可修改');
            } catch (QueryException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage());
            }
        }
        try {
            DB::transaction(fn () => DB::table('event_dependencies')->insert(['novel_id' => $f['novelId'], 'context_id' => $r['id'], 'predecessor_id' => $a, 'successor_id' => $a, 'relation_type' => 'before']));
            $this->fail('封存后不能追加子记录');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
        foreach (['story_context_versions', 'story_time_entries', 'event_dependencies', 'knowledge_facts', 'relationships', 'consistency_rules'] as $table) {
            foreach (DB::select('SELECT obj_description(c.oid) AS t,col_description(c.oid,a.attnum) AS c FROM pg_class c JOIN pg_attribute a ON a.attrelid=c.oid WHERE c.oid=to_regclass(?) AND a.attnum>0 AND NOT a.attisdropped', [$table]) as $comment) {
                $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $comment->t ?? '');
                $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $comment->c ?? '');
            }
        }
    }
}
