<?php

namespace Tests\Feature;

use App\Exceptions\NovelProblem;
use App\Filament\Pages\RevisionDesk;
use App\Models\User;
use App\Services\ChapterReviewService;
use App\Services\RevisionService;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ExtractionFixture as E;
use Tests\Support\ReviewFixture as R;
use Tests\Support\RevisionFixture as F;
use Tests\TestCase;

/** 真实PostgreSQL受限修订、事实门禁、轮次与人工介入，不声称文学效果。 */
class RevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_adoption_reextracts_new_body_reviews_full_chapter_and_preserves_history(): void
    {
        $f = F::create();
        $s = app(RevisionService::class);
        // 已有人工作出非阻塞意见时，冻结JSON仍须可跨JSONB往返复核。
        app(ChapterReviewService::class)->decide($f['actor'], $f['novel'], $f['review']['id'], ['issue_id' => $f['review']['issues'][0]['id'], 'decision' => 'accepted', 'reason' => '保留非阻塞意见历史'], 'prior-decision');
        $t = F::task($f);
        $this->assertSame('draft', $t['status']);
        $this->assertSame(1, $t['automatic_round']);
        $this->assertNotEmpty($t['input']['preserve_facts']);
        $this->assertNotEmpty($t['input']['protected']);
        $p = ['offline_output' => F::output($f), 'reason' => '纯离线补丁'];
        $proposed = $s->propose($f['actor'], $f['novel'], $t['id'], $p, 'propose');
        $this->assertSame($proposed, $s->propose($f['actor'], $f['novel'], $t['id'], $p, 'propose'));
        $this->assertDatabaseCount('chapter_versions', 1);
        $d = ['kind' => 'accept', 'proposal_id' => $proposed['proposals'][0]['id'], 'patch_keys' => ['first'], 'expected_version' => 1, 'reason' => '明确仅接受第一项'];
        $adopted = $s->decide($f['actor'], $f['novel'], $t['id'], $d, 'adopt');
        $this->assertSame($adopted, $s->decide($f['actor'], $f['novel'], $t['id'], $d, 'adopt'));
        $this->assertSame('reviewing', $adopted['status']);
        $this->assertDatabaseCount('chapter_versions', 2);
        $new = F::updated($f, $adopted);
        $this->assertStringContainsString('第一处', $new['body']['body']);
        $this->assertStringNotContainsString('逐一核对', $new['body']['body']);
        $this->assertSame($f['body']['body'], $adopted['decision']['diff']['before']);
        $this->assertTrue(app(ChapterReviewService::class)->read($f['actor'], $f['novel'], $f['review']['id'])['stale']);
        // 即使先另行重提取并给高分，缺少E05事实保留验证仍然阻塞。
        $e = E::extract($new, E::output([E::event($new)]));
        $new['extraction'] = $e;
        $blocked = R::review($new, R::output($new, 5));
        $this->assertFalse($blocked['report']['hard_pass']);
        $done = $s->assess($f['actor'], $f['novel'], $t['id'], F::assessment($new), 'assess');
        $this->assertSame('accepted', $done['status'], json_encode($done['assessment']));
        $this->assertTrue($done['assessment']['report']['improved']);
        $this->assertTrue(app(ChapterReviewService::class)->read($f['actor'], $f['novel'], $done['assessment']['review_id'])['current_eligible']);
        $this->assertDatabaseCount('story_events', 0);
        Http::assertNothingSent();
    }

    public function test_out_of_scope_forged_overlap_and_protected_fact_changes_leave_failed_proposals_without_body_rewrite(): void
    {
        $f = F::create();
        $s = app(RevisionService::class);
        $t = F::task($f);
        $p = F::output($f);
        $p['patches'][0]['quote'] = '伪造';
        $bad = $s->propose($f['actor'], $f['novel'], $t['id'], ['offline_output' => $p, 'reason' => '错误引文不可采纳'], 'bad');
        $this->assertSame('manual_required', $bad['status']);
        $this->assertFalse($bad['proposals'][0]['report']['valid']);
        try {
            $s->decide($f['actor'], $f['novel'], $t['id'], ['kind' => 'accept', 'proposal_id' => $bad['proposals'][0]['id'], 'patch_keys' => ['first'], 'expected_version' => 1, 'reason' => '不能接受失败补丁'], 'bad-accept');
            $this->fail('无效补丁不能采纳');
        } catch (NovelProblem $e) {
            $this->assertSame('INVALID_SELECTION', $e->errorCode);
        }
        $s->decide($f['actor'], $f['novel'], $t['id'], ['kind' => 'abandon', 'expected_version' => 1, 'reason' => '保留失败后转人工'], 'abandon');
        $manual = F::task($f, 'manual');
        foreach (['outside', 'protected', 'overlap'] as $kind) {
            $p = F::output($f);
            if ($kind === 'outside') {
                $at = mb_strrpos($f['body']['body'], '第50处');
                $p['patches'] = [array_replace($p['patches'][0], ['start' => $at, 'end' => $at + 4, 'quote' => '第50处'])];
            } elseif ($kind === 'protected') {
                $p['patches'] = [array_replace($p['patches'][0], ['start' => mb_strpos($f['body']['body'], '铜钥匙'), 'end' => mb_strpos($f['body']['body'], '铜钥匙') + 3, 'quote' => '铜钥匙', 'replacement' => '石头'])];
            } else {
                $p['patches'][1]['start'] = $p['patches'][0]['start'];
            }
            try {
                $s->decide($f['actor'], $f['novel'], $manual['id'], ['kind' => 'manual', 'manual_output' => $p, 'expected_version' => 1, 'reason' => '手工编辑仍必须经过范围与事实门禁'], (string) Str::uuid());
                $this->fail('越界或破坏保留事实不得保存');
            } catch (NovelProblem $e) {
                $this->assertContains($e->errorCode, ['REVISION_OUT_OF_SCOPE', 'PRESERVED_QUOTE_CHANGED', 'INVALID_REVISION_SPAN']);
            }
        }
        $this->assertDatabaseCount('chapter_versions', 1);
    }

    public function test_default_three_automatic_rounds_cannot_be_reset_with_new_task_keys_and_manual_can_continue(): void
    {
        $f = F::create();
        $s = app(RevisionService::class);
        for ($i = 1; $i <= 3; $i++) {
            $t = F::task($f);
            $this->assertSame($i, $t['automatic_round']);
            $this->assertSame('draft', $t['status']);
            $s->decide($f['actor'], $f['novel'], $t['id'], ['kind' => 'reject', 'expected_version' => 1, 'reason' => '拒绝本轮也不归还自动文学轮次'], (string) Str::uuid());
        }
        $fourth = F::task($f);
        $this->assertSame('manual_required', $fourth['status']);
        $this->assertSame(4, $fourth['automatic_round']);
        try {
            $s->propose($f['actor'], $f['novel'], $fourth['id'], ['offline_output' => F::output($f), 'reason' => '不能第四轮自动调用'], 'fourth');
            $this->fail('上限不能绕过');
        } catch (NovelProblem $e) {
            $this->assertSame('REVISION_MANUAL_REQUIRED', $e->errorCode);
        }
        $t = $s->decide($f['actor'], $f['novel'], $fourth['id'], ['kind' => 'manual', 'manual_output' => ['patches' => [F::output($f)['patches'][0]]], 'expected_version' => 1, 'reason' => '达到自动上限后明确人工修改'], 'manual');
        $this->assertSame('reviewing', $t['status']);
        $this->assertDatabaseCount('chapter_versions', 2);
    }

    public function test_no_improvement_and_lost_structured_facts_require_manual_and_high_scores_cannot_bypass_preservation(): void
    {
        $f = F::create();
        $s = app(RevisionService::class);
        $t = F::task($f);
        $adopted = F::adopt($f, $t);
        $new = F::updated($f, $adopted);
        $done = $s->assess($f['actor'], $f['novel'], $t['id'], F::assessment($new, 3), 'unchanged');
        $this->assertSame('manual_required', $done['status']);
        $this->assertFalse($done['assessment']['report']['improved']);
        // 同正文修正提取响应可以复验，但不能用高分掩盖删改事实。
        $d = F::assessment($new, 5);
        $d['extraction_output']['events'][0]['value'] = '另一个事实';
        $bad = $s->assess($f['actor'], $f['novel'], $t['id'], $d, 'fact-lost');
        $this->assertSame('manual_required', $bad['status']);
        $r = app(ChapterReviewService::class)->read($f['actor'], $f['novel'], $bad['assessment']['review_id']);
        $this->assertTrue($r['report']['literary_pass']);
        $this->assertFalse($r['report']['hard_pass']);
        try {
            $s->confirm($f['actor'], $f['novel'], $t['id'], ['reason' => '高分也不能豁免事实保留失败'], 'deny-confirm');
            $this->fail('不能人工豁免硬错误');
        } catch (NovelProblem $err) {
            $this->assertSame('HARD_ISSUE_NOT_WAIVABLE', $err->errorCode);
        }
        $s->decide($f['actor'], $f['novel'], $t['id'], ['kind' => 'abandon', 'expected_version' => 2, 'reason' => '放弃保留失败历史'], 'stop');
        $this->assertDatabaseCount('chapter_versions', 2);
        $this->assertDatabaseCount('story_events', 0);
    }

    public function test_no_improvement_even_when_scores_pass_needs_explicit_human_confirmation_and_abandon_invalidates_review(): void
    {
        $f = F::create(4);
        $s = app(RevisionService::class);
        $t = F::task($f);
        $adopted = F::adopt($f, $t);
        $new = F::updated($f, $adopted);
        $done = $s->assess($f['actor'], $f['novel'], $t['id'], F::assessment($new, 4), 'same-pass-score');
        $this->assertSame('manual_required', $done['status']);
        $this->assertFalse($done['assessment']['report']['improved']);
        $confirmed = $s->confirm($f['actor'], $f['novel'], $t['id'], ['reason' => '本人对照全文确认非阻塞文学表达可接受，不修改原评分'], 'human');
        $this->assertSame('accepted', $confirmed['status']);
        $this->assertTrue($confirmed['assessment']['report']['manual_confirmation']);
        $this->assertDatabaseCount('reviews', 2);
        $stopped = $s->decide($f['actor'], $f['novel'], $t['id'], ['kind' => 'abandon', 'expected_version' => 2, 'reason' => '明确放弃已采纳候选但不删除历史'], 'abandon-accepted');
        $this->assertSame('rejected', $stopped['status']);
        $this->assertCount(2, $stopped['decisions']);
        $this->assertTrue(app(ChapterReviewService::class)->read($f['actor'], $f['novel'], $confirmed['assessment']['review_id'])['stale']);
        $this->assertDatabaseCount('chapter_versions', 2);
    }

    public function test_scene_and_explicit_chapter_scope_are_bound_to_actual_sources_and_bad_boundaries_are_rejected(): void
    {
        $f = F::create();
        $s = app(RevisionService::class);
        $body = $f['body']['body'];
        foreach (['scene', 'chapter'] as $kind) {
            $d = F::payload($f, 'manual');
            $d['allowed_scope'] = ['kind' => $kind, 'ranges' => [['start' => 0, 'end' => mb_strlen($body), 'quote' => $body]]];
            if ($kind === 'scene') {
                $d['allowed_scope']['scene_version_id'] = $f['body']['sources'][0]['id'];
            }$t = $s->create($f['actor'], $f['novel'], $d, (string) Str::uuid());
            $this->assertSame('draft', $t['status']);
            $s->decide($f['actor'], $f['novel'], $t['id'], ['kind' => 'reject', 'expected_version' => 1, 'reason' => '仅验证作者明确授权的范围'], (string) Str::uuid());
        }
        $d = F::payload($f, 'manual');
        $d['allowed_scope']['ranges'][0]['start']++;
        $d['allowed_scope']['ranges'][0]['quote'] = mb_substr($d['allowed_scope']['ranges'][0]['quote'], 1);
        try {
            $s->create($f['actor'], $f['novel'], $d, 'bad-boundary');
            $this->fail('不能伪造段落边界');
        } catch (NovelProblem $e) {
            $this->assertSame('INVALID_REVISION_SCOPE', $e->errorCode);
        }
        $d = F::payload($f, 'manual');
        $d['allowed_scope'] = ['kind' => 'scene', 'scene_version_id' => '999999', 'ranges' => [['start' => 0, 'end' => mb_strlen($body), 'quote' => $body]]];
        try {
            $s->create($f['actor'], $f['novel'], $d, 'bad-scene');
            $this->fail('不能引用无关场景');
        } catch (NovelProblem $e) {
            $this->assertSame('INVALID_REVISION_SCOPE', $e->errorCode);
        }
    }

    public function test_http_livewire_scope_preparation_manual_path_and_all_new_schema_comments(): void
    {
        $f = F::create();
        $url = '/api/v1/novels/'.$f['novel'].'/revisions';
        $d = F::payload($f);
        $this->postJson($url, $d)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson($url, $d)->assertForbidden();
        $t = $this->actingAs($f['actor'])->withHeader('Idempotency-Key', 'create-http')->postJson($url, $d)->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $this->postJson($url, array_replace($d, ['reason' => '不同输入']))->assertConflict();
        $this->getJson('/api/v1/novels/999999/revisions/'.$t['id'])->assertNotFound();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(RevisionDesk::class)->set('novel', $f['novel'])->set('review', $f['review']['id'])->call('prepare')->assertSee('受限修订')->set('selected', $t['id'])->call('inspect')->assertSet('detail.status', 'draft')->call('loadManual')->assertSet('manualText', $d['allowed_scope']['ranges'][0]['quote']);
        foreach (['revision_tasks', 'revision_task_issues', 'revision_task_facts', 'revision_proposals', 'revision_decisions', 'revision_validations', 'revision_assessments'] as $table) {
            $r = DB::selectOne('SELECT count(*) AS n,count(col_description(attrelid,attnum)) AS c FROM pg_attribute WHERE attrelid=?::regclass AND attnum>0 AND NOT attisdropped', [$table]);
            $this->assertSame($r->n, $r->c);
            $this->assertNotNull(DB::selectOne('SELECT obj_description(?::regclass) AS c', [$table])->c);
        }
        try {
            DB::transaction(fn () => DB::table('revision_tasks')->where('id', $t['id'])->update(['max_rounds' => 9]));
            $this->fail('任务冻结输入不可覆盖');
        } catch (QueryException $e) {
            $this->assertNotEmpty($e->getMessage());
        }Http::assertNothingSent();
    }
}
