<?php

namespace Tests\Feature;

use App\Exceptions\NovelProblem;
use App\Services\ReviewPolicyService;
use App\Services\RevisionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RevisionFixture as F;
use Tests\TestCase;

/** 已冻结任务沿用原策略，退役仅阻止新任务，不静默改用新阈值。 */
class RevisionFrozenPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_retired_policy_rejects_new_task_but_existing_task_reassesses_with_exact_frozen_policy(): void
    {
        $f = F::create();
        $s = app(RevisionService::class);
        $t = F::task($f);
        $this->assertDatabaseCount('revision_task_issues', 1);
        $this->assertDatabaseCount('revision_task_facts', 1);
        try {
            DB::transaction(fn () => DB::table('revision_task_issues')->insert(['novel_id' => $f['novel'], 'task_id' => $t['id'], 'review_id' => '999999', 'issue_id' => '999999']));
            $this->fail('不能关联另一评审的问题');
        } catch (QueryException $err) {
            $this->assertNotEmpty($err->getMessage());
        }
        app(ReviewPolicyService::class)->execute($f['actor'], 'retire', ['policy_version_id' => $f['policy']['id'], 'reason' => '只停止新任务采用，不修改冻结任务'], 'retire');
        try {
            F::task($f);
            $this->fail('新任务不能采用退役策略');
        } catch (NovelProblem $e) {
            $this->assertSame('UNPUBLISHED_REFERENCE', $e->errorCode);
        }
        $adopted = F::adopt($f, $t);
        $new = F::updated($f, $adopted);
        $done = $s->assess($f['actor'], $f['novel'], $t['id'], F::assessment($new), 'frozen-assess');
        $this->assertSame('accepted', $done['status']);
        $this->assertSame($f['policy']['id'], $done['input']['policy_id']);
        $this->assertDatabaseCount('chapter_versions', 2);
        $this->assertDatabaseCount('story_events', 0);
    }

    public function test_external_state_change_after_adoption_invalidates_task_before_reextraction_or_review(): void
    {
        $f = F::create();
        $s = app(RevisionService::class);
        $t = F::task($f);
        $adopted = F::adopt($f, $t);
        $new = F::updated($f, $adopted);
        DB::table('novels')->where('id', $f['novel'])->increment('state_version');
        $this->assertTrue($s->read($f['actor'], $f['novel'], $t['id'])['stale']);
        try {
            $s->assess($f['actor'], $f['novel'], $t['id'], F::assessment($new), 'stale-state');
            $this->fail('正式状态改变后不能沿用旧修订输入');
        } catch (NovelProblem $e) {
            $this->assertSame('DEPENDENCY_MISMATCH', $e->errorCode);
        }
        $this->assertDatabaseCount('revision_validations', 0);
        $this->assertDatabaseCount('chapter_versions', 2);
    }
}
