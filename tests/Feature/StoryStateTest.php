<?php

namespace Tests\Feature;

use App\Exceptions\NovelProblem;
use App\Generation\StageResult;
use App\Models\Novel;
use App\Models\User;
use App\Services\GenerationService;
use App\Services\NovelIntentService;
use App\Services\StoryStateService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\QueuedRunFixture;
use Tests\TestCase;

/** D01 仅使用离线运行产物验证候选状态，绝不调用模型或提交正式事实。 */
class StoryStateTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $text = "甲😀乙\r\n他以为许砚已死。许砚还活着。 "): array
    {
        Http::preventStrayRequests();
        $f = QueuedRunFixture::create();
        $g = app(GenerationService::class);
        $claim = $g->claim($f['actor'], $f['novelId'], $f['runId'], 'first');
        $g->complete($f['actor'], $f['novelId'], $f['runId'], $claim, new StageResult('succeeded', ['text' => $text]));
        $f['artifact'] = (string) DB::table('generation_artifacts')->where('run_id', $f['runId'])->value('id');
        $s = app(StoryStateService::class);
        $f['source'] = $s->registerArtifact($f['actor'], $f['novelId'], $f['artifact']);
        $f['hash'] = hash('sha256', StoryStateService::normalize($text));
        $f['evidence'] = $s->evidence($f['actor'], $f['novelId'], $f['source'], $f['hash'], 1, 2, '😀');
        $f['event'] = ['evidence_id' => $f['evidence'], 'definition_version_id' => $f['person']['definition']['id'], 'fact_kind' => 'fact', 'action' => 'set', 'chapter_sequence' => 1, 'narrative_order' => 1, 'attribute' => 'life_status', 'value' => 'alive', 'confidence' => 'confirmed', 'story_time' => [], 'reason' => '离线候选回放测试'];

        return $f;
    }

    private function reject(callable $callback, string $code): void
    {
        try {
            $callback();
            $this->fail('应拒绝非法操作：'.$code);
        } catch (NovelProblem $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    public function test_unicode_evidence_normalization_and_stale_source_are_exact(): void
    {
        $f = $this->fixture();
        $s = app(StoryStateService::class);
        $this->assertSame($f['source'], $s->registerArtifact($f['actor'], $f['novelId'], $f['artifact']));
        $this->assertSame("甲😀乙\n他以为许砚已死。许砚还活着。 ", DB::table('source_documents')->value('body'));
        foreach ([[1, 3, '😀'], [3, 7, '😀'], [-1, 2, '😀'], [1, 1, ''], [1, 200, '😀'], [1, 2, '乙']] as [$start, $end, $quote]) {
            $this->reject(fn () => $s->evidence($f['actor'], $f['novelId'], $f['source'], $f['hash'], $start, $end, $quote), 'INVALID_EVIDENCE');
        }
        $this->reject(fn () => $s->evidence($f['actor'], $f['novelId'], $f['source'], str_repeat('a', 64), 1, 2, '😀'), 'INVALID_EVIDENCE');
        $this->reject(fn () => StoryStateService::normalize("\xFF"), 'INVALID_BODY');
        $this->assertSame(" e\u{0301} ", StoryStateService::normalize(" e\u{0301} "));
        $this->assertDatabaseCount('evidence_spans', 1);
        Http::assertNothingSent();
    }

    public function test_all_fact_kinds_are_separate_and_replay_hash_is_deterministic(): void
    {
        $f = $this->fixture();
        $s = app(StoryStateService::class);
        $before = Novel::find($f['novelId'])->state_version;
        $ids = [];
        foreach (StoryStateService::KINDS as $i => $kind) {
            $d = array_replace($f['event'], ['fact_kind' => $kind, 'narrative_order' => $i + 1, 'value' => $kind === 'fact' ? 'alive' : 'dead', 'holder_id' => in_array($kind, ['fact', 'hidden_truth']) ? null : $f['person']['definition']['entity_id']]);
            $ids[] = $s->propose($f['actor'], $f['novelId'], $d);
        }
        $a = $s->replay($f['actor'], $f['novelId'], $ids, 1);
        $b = $s->replay($f['actor'], $f['novelId'], array_reverse($ids), 1);
        $this->assertSame($a, $b);
        foreach (StoryStateService::KINDS as $kind) {
            $this->assertCount(1, $a['state'][$kind]);
        }
        $this->assertSame('alive', array_values($a['state']['fact'])[0]['value']);
        $this->assertSame('dead', array_values($a['state']['belief'])[0]['value']);
        $this->assertFalse($a['canonical']);
        $this->assertSame($before, Novel::find($f['novelId'])->state_version);
        $this->assertDatabaseCount('story_events', 0);
    }

    public function test_json_key_order_is_irrelevant_but_list_order_is_preserved(): void
    {
        $f = $this->fixture();
        $s = app(StoryStateService::class);
        $a = $s->propose($f['actor'], $f['novelId'], array_replace($f['event'], ['value' => ['z' => [1, 2], 'a' => ['y' => 1, 'b' => 2]]]));
        $b = $s->propose($f['actor'], $f['novelId'], array_replace($f['event'], ['value' => ['a' => ['b' => 2, 'y' => 1], 'z' => [1, 2]]]));
        $c = $s->propose($f['actor'], $f['novelId'], array_replace($f['event'], ['value' => ['a' => ['b' => 2, 'y' => 1], 'z' => [2, 1]]]));
        $hash = fn ($id) => DB::table('proposed_events')->where('id', $id)->value('content_hash');
        $this->assertSame($hash($a), $hash($b));
        $this->assertNotSame($hash($a), $hash($c));
        $this->assertNotEmpty($s->replay($f['actor'], $f['novelId'], [$a], 1)['state_hash']);
        $this->reject(fn () => $s->propose($f['actor'], $f['novelId'], array_replace($f['event'], ['value' => 0.5])), 'VALIDATION_FAILED');
    }

    public function test_correction_is_append_only_and_rebuild_restores_corrupt_projection(): void
    {
        $f = $this->fixture();
        $s = app(StoryStateService::class);
        $a = $s->propose($f['actor'], $f['novelId'], $f['event']);
        $b = $s->propose($f['actor'], $f['novelId'], array_replace($f['event'], ['action' => 'replace', 'supersedes_id' => $a, 'chapter_sequence' => 2, 'value' => 'dead']));
        $c = $s->propose($f['actor'], $f['novelId'], array_replace($f['event'], ['action' => 'revoke', 'supersedes_id' => $b, 'chapter_sequence' => 3, 'value' => null]));
        $one = $s->replay($f['actor'], $f['novelId'], [$a, $b, $c], 1);
        $this->assertSame('alive', array_values($one['state']['fact'])[0]['value']);
        $two = $s->rebuild($f['actor'], $f['novelId'], [$a, $b, $c], 2);
        $this->assertSame('dead', array_values($two['state']['fact'])[0]['value']);
        DB::table('state_snapshots')->update(['payload' => '{"summary":"伪造摘要"}']);
        DB::table('entity_state_versions')->delete();
        $rebuilt = $s->rebuild($f['actor'], $f['novelId'], [$a, $b, $c], 2);
        $this->assertSame($two['state_hash'], $rebuilt['state_hash']);
        $this->assertDatabaseCount('entity_state_versions', 1);
        $this->assertSame([], $s->replay($f['actor'], $f['novelId'], [$a, $b, $c], 3)['state']['fact']);
        $this->assertDatabaseCount('proposed_events', 3);
        $this->reject(fn () => $s->replay($f['actor'], $f['novelId'], [$b], 2), 'INCOMPLETE_CORRECTION');
        $this->reject(fn () => $s->replay($f['actor'], $f['novelId'], [$a, $a], 1), 'INVALID_STREAM');
        $other = $s->propose($f['actor'], $f['novelId'], $f['event']);
        $this->reject(fn () => $s->replay($f['actor'], $f['novelId'], [$a, $other], 1), 'AMBIGUOUS_ORDER');
    }

    public function test_pending_events_do_not_become_state_and_cross_scope_references_fail(): void
    {
        $f = $this->fixture();
        $s = app(StoryStateService::class);
        $a = $s->propose($f['actor'], $f['novelId'], array_replace($f['event'], ['confidence' => 'uncertain']));
        $r = $s->replay($f['actor'], $f['novelId'], [$a], 1);
        $this->assertSame([$a], $r['pending_event_ids']);
        $this->assertSame([], $r['state']['fact']);
        $this->reject(fn () => $s->propose($f['actor'], $f['novelId'], array_replace($f['event'], ['fact_kind' => 'belief'])), 'INVALID_HOLDER');
        $other = User::factory()->create();
        $other->is_admin = true;
        $this->reject(fn () => $s->replay($other, $f['novelId'], [$a], 1), 'NOT_FOUND');
        $book = app(NovelIntentService::class)->execute($f['actor'], 'create', null, ['title' => '另一小说', 'genre' => '悬疑', 'background' => '测试', 'premise_raw' => '另一独立故事', 'target_chapters' => 10, 'length_policy' => ['min' => 2000, 'max' => 3000]], 'd01-other');
        $otherNovel = $book['novel']['id'];
        $this->reject(fn () => $s->registerArtifact($f['actor'], $otherNovel, $f['artifact']), 'NOT_FOUND');
        $this->reject(fn () => $s->evidence($f['actor'], $otherNovel, $f['source'], $f['hash'], 1, 2, '😀'), 'NOT_FOUND');
        $this->reject(fn () => $s->replay($f['actor'], $otherNovel, [$a], 1), 'NOT_FOUND');
        DB::table('entities')->where('id', $f['person']['definition']['entity_id'])->update(['retired' => true]);
        $this->reject(fn () => $s->propose($f['actor'], $f['novelId'], $f['event']), 'UNAPPROVED_ENTITY');
        // 停用不删除历史候选证据，旧流仍可回放。
        $this->assertSame($r['state_hash'], $s->replay($f['actor'], $f['novelId'], [$a], 1)['state_hash']);
    }

    public function test_new_body_cannot_rebind_old_extraction_and_failed_proposal_is_atomic(): void
    {
        $f = $this->fixture();
        $s = app(StoryStateService::class);
        $a = $s->propose($f['actor'], $f['novelId'], $f['event']);
        $s->assertSource($f['actor'], $f['novelId'], [$a], $f['source'], $f['hash']);
        $g = app(GenerationService::class);
        $claim = $g->claim($f['actor'], $f['novelId'], $f['runId'], 'second');
        $text = '甲😀乙，修改后的新正文';
        $g->complete($f['actor'], $f['novelId'], $f['runId'], $claim, new StageResult('succeeded', ['text' => $text]));
        $artifact = (string) DB::table('generation_artifacts')->where('step_id', $claim['id'])->value('id');
        $source = $s->registerArtifact($f['actor'], $f['novelId'], $artifact);
        $this->reject(fn () => $s->assertSource($f['actor'], $f['novelId'], [$a], $source, hash('sha256', $text)), 'STALE_EXTRACTION');
        $this->reject(fn () => $s->propose($f['actor'], $f['novelId'], array_replace($f['event'], ['action' => 'replace', 'supersedes_id' => $a, 'chapter_sequence' => 2, 'fact_kind' => 'hidden_truth'])), 'INVALID_CORRECTION');
        $this->assertDatabaseCount('proposed_events', 1);
        $this->assertDatabaseCount('proposed_state_patches', 1);
        $this->assertDatabaseCount('source_documents', 2);
        $s->assertSource($f['actor'], $f['novelId'], [$a], $f['source'], $f['hash']);
    }

    public function test_database_rejects_forged_evidence_and_wrong_entity_version(): void
    {
        $f = $this->fixture();
        $s = app(StoryStateService::class);
        foreach ([['quote' => '伪造引文'], ['source_hash' => str_repeat('a', 64)], ['end_offset' => 9000]] as $change) {
            try {
                $span = (array) DB::table('evidence_spans')->first();
                unset($span['id']);
                DB::transaction(fn () => DB::table('evidence_spans')->insert(array_replace($span, $change)));
                $this->fail('数据库应拒绝绕过服务的错误证据');
            } catch (QueryException $e) {
                $this->assertStringContainsString('invalid evidence', $e->getMessage());
            }
        }
        $s->propose($f['actor'], $f['novelId'], $f['event']);
        $event = (array) DB::table('proposed_events')->first();
        unset($event['id']);
        try {
            DB::transaction(fn () => DB::table('proposed_events')->insert(array_replace($event, ['entity_id' => 9223372036854775807])));
            $this->fail('数据库应验证实体与版本所属关系');
        } catch (QueryException $e) {
            $this->assertStringContainsString('23503', $e->getMessage());
        }
        $this->assertDatabaseCount('proposed_events', 1);
        $this->assertDatabaseCount('evidence_spans', 1);
    }

    public function test_database_guards_immutability_formal_entry_and_chinese_comments(): void
    {
        $f = $this->fixture();
        $s = app(StoryStateService::class);
        $a = $s->propose($f['actor'], $f['novelId'], $f['event']);
        foreach (['source_documents', 'evidence_spans', 'proposed_events', 'proposed_state_patches'] as $table) {
            foreach (['update', 'delete'] as $action) {
                try {
                    DB::transaction(fn () => $action === 'update' ? DB::table($table)->update(['novel_id' => $f['novelId']]) : DB::table($table)->delete());
                    $this->fail('不可变记录不得修改');
                } catch (QueryException $e) {
                    $this->assertStringContainsString('immutable', strtolower($e->getMessage()));
                }
            }
        }
        try {
            DB::transaction(fn () => DB::table('story_events')->insert(['novel_id' => $f['novelId'], 'proposed_event_id' => $a, 'revision_id' => 1, 'commit_id' => 1, 'chapter_version_id' => 1, 'evidence_id' => $f['evidence'], 'narrative_order' => 1, 'payload' => '{}']));
            $this->fail('缺少正式提交证明不得写正式事实');
        } catch (QueryException $e) {
            $this->assertStringContainsString('canonical commit write proof required', $e->getMessage());
        }
        foreach (['source_documents', 'evidence_spans', 'proposed_events', 'proposed_state_patches', 'story_events', 'state_snapshots', 'entity_state_versions'] as $table) {
            $comments = DB::select('SELECT obj_description(c.oid) AS table_comment,col_description(c.oid,a.attnum) AS column_comment FROM pg_class c JOIN pg_attribute a ON a.attrelid=c.oid WHERE c.oid=to_regclass(?) AND a.attnum>0 AND NOT a.attisdropped', [$table]);
            $this->assertNotEmpty($comments);
            foreach ($comments as $comment) {
                $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $comment->table_comment ?? '');
                $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $comment->column_comment ?? '');
            }
        }
    }
}
