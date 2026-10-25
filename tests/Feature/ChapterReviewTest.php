<?php

namespace Tests\Feature;

use App\Exceptions\NovelProblem;
use App\Filament\Pages\ReviewCenter;
use App\Models\User;
use App\Review\ReviewContract;
use App\Review\StyleSignals;
use App\Services\ChapterReviewService;
use App\Services\DraftingService;
use App\Services\ReviewPolicyService;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ExtractionFixture as E;
use Tests\Support\ReviewFixture as F;
use Tests\TestCase;

/** 七维、硬门禁、证据和策略真实PostgreSQL验收，不包含真实模型语义结论。 */
class ChapterReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_seven_dimensions_and_thresholds_preserve_history_and_formal_gate(): void
    {
        $f = F::create();
        $s = app(ChapterReviewService::class);
        $d = F::payload($f);
        $r = $s->review($f['actor'], $f['novel'], $d, 'first');
        $this->assertSame('pass', $r['display_status']);
        $this->assertTrue($r['current_eligible']);
        $this->assertCount(7, $r['report']['scores']);
        $this->assertEquals(4, $r['report']['average']);
        $this->assertSame($r, $s->review($f['actor'], $f['novel'], $d, 'first'));
        $low = F::output($f);
        $low['scores']['plot']['score'] = 2;
        $blocked = F::review($f, $low);
        $this->assertSame('block', $blocked['report']['verdict']);
        $this->assertTrue($blocked['report']['hard_pass']);
        $avg = F::output($f, 3);
        $this->assertFalse(F::review($f, $avg)['report']['literary_pass']);
        $boundary = F::output($f);
        $boundary['scores']['plot']['score'] = 3;
        $this->assertTrue(F::review($f, $boundary)['current_eligible']);
        $this->assertSame(3, $r['report']['thresholds']['max_revision_rounds']);
        $this->assertFalse($r['report']['human_acceptance']);
        $this->assertDatabaseCount('story_events', 0);
        $this->assertDatabaseCount('chapter_versions', 1);
        $human = F::review($f, extra: ['reviewer_type' => 'human']);
        $this->assertTrue($human['report']['naturalness_confirmed_by_human']);
        $this->assertFalse($human['report']['human_acceptance']);
        Http::assertNothingSent();
    }

    public function test_high_literary_scores_and_human_rating_never_override_failed_extraction_or_blocking_issue(): void
    {
        $f = F::create();
        $bad = E::event($f);
        $bad['evidence']['quote'] = '伪造引文';
        $f['extraction'] = E::extract($f, E::output([$bad]));
        $r = F::review($f, F::output($f, 5), ['reviewer_type' => 'human']);
        $this->assertFalse($r['report']['hard_pass']);
        $this->assertTrue($r['report']['literary_pass']);
        $this->assertSame('block', $r['report']['verdict']);
        $this->assertFalse($r['report']['naturalness_confirmed_by_human']);
        $i = collect($r['issues'])->firstWhere('origin', 'hard');
        try {
            app(ChapterReviewService::class)->decide($f['actor'], $f['novel'], $r['id'], ['issue_id' => $i['id'], 'decision' => 'accepted', 'reason' => '高分不能豁免'], 'waive');
            $this->fail('硬门禁不能豁免');
        } catch (NovelProblem $e) {
            $this->assertSame('HARD_ISSUE_NOT_WAIVABLE', $e->errorCode);
        }
        try {
            DB::transaction(fn () => DB::table('review_issue_decisions')->insert(['novel_id' => $f['novel'], 'review_id' => $r['id'], 'issue_id' => $i['id'], 'decision' => 'accepted', 'actor_id' => $f['actor']->id, 'reason' => '数据库也必须阻止']));
            $this->fail('数据库不能绕过');
        } catch (QueryException $e) {
            $this->assertStringContainsString('cannot be waived', $e->getMessage());
        }
        $this->assertDatabaseCount('story_events', 0);
    }

    public function test_forged_offsets_missing_dimensions_unknown_fields_and_model_blocking_produce_locatable_failure(): void
    {
        $f = F::create();
        $p = F::output($f);
        $p['scores']['plot']['evidence']['start'] = 1;
        $r = F::review($f, $p);
        $this->assertSame('block', $r['report']['verdict']);
        $this->assertSame([], $r['report']['scores']);
        $this->assertSame('INVALID_REVIEW_EVIDENCE', $r['issues'][0]['details']['code']);
        $bounds = F::output($f);
        $bounds['scores']['plot']['score'] = 6;
        $this->assertFalse(F::review($f, $bounds)['current_eligible']);
        $p = F::output($f);
        unset($p['scores']['pace']);
        $this->assertFalse(F::review($f, $p)['current_eligible']);
        $p = F::output($f);
        $p['verdict'] = 'pass';
        $this->assertFalse(F::review($f, $p)['current_eligible']);
        $p = F::output($f);
        $p['issues'][] = ['category' => 'causality', 'severity' => 'blocking', 'message' => '明确阻塞因果断裂', 'suggested_fix' => '补充这一动作之前的必要因果，不重写全章', 'evidence' => $p['scores']['plot']['evidence']];
        $r = F::review($f, $p);
        $this->assertFalse($r['current_eligible']);
        $this->assertNotNull($r['issues'][0]['evidence_id']);
        $this->assertSame($p['issues'][0]['evidence']['quote'], $r['issues'][0]['quote']);
    }

    public function test_policy_lifecycle_explicit_lower_version_and_exceptions_do_not_mutate_old_results(): void
    {
        $f = F::create();
        $old = F::review($f, F::output($f, 3));
        $f['policy'] = F::policy($f, ['average' => '3.0', 'exceptions' => ['与此同时']], version: 1);
        $new = F::review($f, F::output($f, 3));
        $this->assertTrue($new['current_eligible']);
        $this->assertFalse(app(ChapterReviewService::class)->read($f['actor'], $f['novel'], $old['id'])['current_eligible']);
        $this->assertEquals(3.8, $old['report']['thresholds']['average']);
        app(ReviewPolicyService::class)->execute($f['actor'], 'retire', ['policy_version_id' => $f['policy']['id'], 'reason' => '只停止新评审采用'], 'retire');
        $this->assertTrue(app(ChapterReviewService::class)->read($f['actor'], $f['novel'], $new['id'])['current_eligible']);
        try {
            F::review($f);
            $this->fail('退役策略不能新用');
        } catch (NovelProblem $e) {
            $this->assertSame('STALE_REVIEW_INPUT', $e->errorCode);
        }
        try {
            DB::transaction(fn () => DB::table('reviews')->where('id', $old['id'])->update(['body_hash' => str_repeat('0', 64)]));
            $this->fail('历史不可变');
        } catch (QueryException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function test_body_edit_or_new_extraction_stales_old_review_without_rebinding(): void
    {
        $f = F::create();
        $old = F::review($f);
        $fresh = E::extract($f, E::output([E::event($f)]));
        $this->assertTrue(app(ChapterReviewService::class)->read($f['actor'], $f['novel'], $old['id'])['stale']);
        $f['extraction'] = $fresh;
        $r = F::review($f);
        $this->assertTrue($r['current_eligible']);
        app(DraftingService::class)->save($f['actor'], $f['novel'], 'chapter', $f['body']['node_id'], ['plan_id' => $f['body']['plan_id'], 'source_version_id' => $f['body']['id'], 'body' => $f['body']['body'].'\n补充动作。', 'title' => $f['body']['title'], 'expected_version' => 1, 'reason' => '修改正文必须重提取与复评'], (string) Str::uuid());
        $read = app(ChapterReviewService::class)->read($f['actor'], $f['novel'], $r['id']);
        $this->assertTrue($read['stale']);
        $this->assertFalse($read['current_eligible']);
        $this->assertSame('stale', $read['display_status']);
        $this->assertSame($r['body_hash'], $read['body_hash']);
    }

    public function test_literal_style_signals_are_only_warnings_with_exact_codepoint_locations_and_exceptions(): void
    {
        $body = '😀与此同时，甲走进屋。与此同时，甲收起纸。与此同时，甲推开窗。“事情就是这样。”“事情就是这样。”';
        $p = ReviewContract::defaults();
        $s = app(StyleSignals::class);
        $issues = $s->inspect($body, $p, [], null);
        $this->assertContains('dialogue', array_column($issues, 'category'));
        $this->assertContains('repetition', array_column($issues, 'category'));
        foreach ($issues as $i) {
            $this->assertSame('warning', $i['severity']);
            $e = $i['evidence'];
            $this->assertSame($e['quote'], mb_substr($body, $e['start'], $e['end'] - $e['start']));
        }
        $p['exceptions'] = ['与此同时'];
        $other = $s->inspect($body, $p, [], null);
        $this->assertFalse(collect($other)->contains(fn ($i) => str_contains($i['message'], '字面信号')));
        $same = $s->inspect(str_repeat('春日的风吹过长廊。', 5), ReviewContract::defaults(), [], str_repeat('春日的风吹过长廊。', 5));
        $this->assertTrue(collect($same)->contains(fn ($i) => str_contains($i['message'], '相邻候选章')));
    }

    public function test_http_ui_authorization_decisions_and_chinese_schema_comments(): void
    {
        $f = F::create();
        $url = '/api/v1/novels/'.$f['novel'].'/reviews';
        $d = F::payload($f);
        $this->postJson($url, $d)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson($url)->assertForbidden();
        // 项目只允许一个管理员；不能伪造第二管理员来构造跨书夹具。
        $this->actingAs($f['actor'])->getJson('/api/v1/novels/999999/reviews')->assertNotFound();
        $r = $this->actingAs($f['actor'])->withHeader('Idempotency-Key', 'http-review')->postJson($url, $d)->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $this->postJson($url, array_replace($d, ['reason' => '不同原因']))->assertConflict();
        $this->getJson('/api/v1/novels/999999/reviews/'.$r['id'])->assertNotFound();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ReviewCenter::class)->set('novel', $f['novel'])->set('selected', $r['id'])->call('inspect')->assertSet('detail.current_eligible', true)->assertSee('情节推进')->assertSee($f['body']['body_hash']);
        Livewire::test(ReviewCenter::class)->set('novel', $f['novel'])->set('policy', $f['policy']['id'])->call('loadPolicy')->assertSet('style', $f['style'])->assertSee('文风与评审策略版本');
        $warning = collect($r['issues'])->firstWhere('origin', 'rule');
        $this->assertNotNull($warning);
        $this->withHeader('Idempotency-Key', 'decision')->postJson($url.'/'.$r['id'].'/decisions', ['issue_id' => $warning['id'], 'decision' => 'accepted', 'reason' => '阅读后接受此非阻塞提示'])->assertCreated();
        $this->assertTrue(app(ChapterReviewService::class)->read($f['actor'], $f['novel'], $r['id'])['current_eligible']);
        foreach (['review_policy_versions', 'review_policy_states', 'reviews', 'review_issues', 'review_issue_decisions'] as $table) {
            $c = DB::selectOne('SELECT count(*) AS n,count(col_description(attrelid,attnum)) AS c FROM pg_attribute WHERE attrelid=?::regclass AND attnum>0 AND NOT attisdropped', [$table]);
            $this->assertSame($c->n, $c->c);
            $this->assertNotNull(DB::selectOne('SELECT obj_description(?::regclass) AS c', [$table])->c);
        }
        Http::assertNothingSent();
    }
}
