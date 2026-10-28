<?php

namespace Tests\Feature;

use App\Exceptions\NovelProblem;
use App\Filament\Pages\ExportCenter;
use App\Filament\Pages\FormalReader;
use App\Jobs\BuildFrozenExport;
use App\Models\User;
use App\Services\DraftingService;
use App\Services\ExportService;
use App\Services\NovelIntentService;
use App\Services\ReadingService;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\CanonicalFixture as F;
use Tests\TestCase;

/** E08使用真实正式提交链、PostgreSQL约束和隔离私有文件，不调用真实模型。 */
class ReadingExportTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(bool $commit = true): array
    {
        Storage::fake('local');
        $f = F::create();
        if ($commit) {
            F::commit($f);
        }
        $this->actingAs($f['actor']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $f;
    }

    private function input(array $f, string $format = 'txt', int $to = 1): array
    {
        return ['novel_id' => $f['novel'], 'revision_id' => $f['revision'], 'range' => ['type' => 'range', 'from' => 1, 'to' => $to], 'format' => $format];
    }

    private function bytes(array $job): string
    {
        return Storage::disk('local')->get(DB::table('export_files')->where('export_job_id', $job['id'])->value('path'));
    }

    public function test_formal_reader_never_falls_back_to_draft_and_explicit_draft_is_labelled(): void
    {
        $f = $this->fixture(false);
        $r = app(ReadingService::class);
        $p = $this->input($f);
        $this->assertSame([1], $r->snapshot($f['actor'], $f['novel'], $p)['missing']);
        unset($p['revision_id']);
        $p['scope'] = 'draft';
        $draft = $r->snapshot($f['actor'], $f['novel'], $p);
        $this->assertNull($draft['revision_id']);
        $this->assertSame($f['body']['body'], $draft['items'][0]['body']);
        F::commit($f);
        $formal = $r->snapshot($f['actor'], $f['novel'], $this->input($f));
        $this->assertSame($f['body']['id'], $formal['items'][0]['chapter_version_id']);
        $this->assertNotNull($formal['items'][0]['commit_id']);
        Http::assertNothingSent();
    }

    public function test_both_formats_freeze_order_titles_and_bodies_without_later_chapter_or_title_leakage(): void
    {
        $f = $this->fixture();
        $s = app(ExportService::class);
        $a = $s->create($f['actor'], $this->input($f), 'freeze-txt');
        $b = $s->create($f['actor'], $this->input($f, 'md'), 'freeze-md');
        $next = F::nextChapter($f);
        F::commit($next, 'commit-next');
        DB::table('novels')->where('id', $f['novel'])->update(['title' => '冻结之后的新书名']);
        foreach ([$a, $b] as $job) {
            $s->process($job['id']);
            $ready = $s->read($f['actor'], $job['id']);
            $text = $this->bytes($job);
            $this->assertSame('ready', $ready['status']);
            $this->assertSame(hash('sha256', $text), $ready['file']['checksum']);
            $this->assertStringContainsString($f['body']['body'], $text);
            $this->assertStringNotContainsString('冻结之后的新书名', $text);
            $this->assertStringNotContainsString($next['body']['title'], $text);
            $this->assertSame(1, count($ready['manifest']['items']));
            $this->assertSame($f['body']['title'], $ready['manifest']['items'][0]['title']);
        }
        $two = $s->create($f['actor'], $this->input($f, 'txt', 2), 'two');
        $s->process($two['id']);
        $text = $this->bytes($two);
        $this->assertLessThan(strrpos($text, $next['body']['body']), strpos($text, $f['body']['body']));
        $this->assertDatabaseCount('canonical_commits', 2);
        Http::assertNothingSent();
    }

    public function test_missing_chapter_and_cross_book_revision_are_rejected_without_jobs(): void
    {
        $f = $this->fixture();
        $s = app(ExportService::class);
        try {
            $s->create($f['actor'], $this->input($f, 'txt', 2), 'missing');
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('range', $e->errors());
        }
        $other = app(NovelIntentService::class)->execute($f['actor'], 'create', null, ['title' => '另一本书', 'genre' => '悬疑', 'background' => '隔离测试', 'premise_raw' => '不含可导出正文', 'target_chapters' => 5, 'length_policy' => ['min' => 2000, 'max' => 3000]], 'other')['novel']['id'];
        $p = $this->input($f);
        $p['novel_id'] = $other;
        try {
            $s->create($f['actor'], $p, 'foreign');
            $this->fail();
        } catch (NovelProblem $e) {
            $this->assertSame('NOT_FOUND', $e->errorCode);
        }
        $this->assertDatabaseCount('export_jobs', 0);
    }

    public function test_idempotency_freezes_original_response_and_rejects_different_input(): void
    {
        $f = $this->fixture();
        $s = app(ExportService::class);
        $a = $s->create($f['actor'], $this->input($f), 'same');
        $this->assertSame($a['id'], $s->create($f['actor'], $this->input($f), 'same')['id']);
        try {
            $s->create($f['actor'], $this->input($f, 'md'), 'same');
            $this->fail();
        } catch (NovelProblem $e) {
            $this->assertSame('IDEMPOTENCY_CONFLICT', $e->errorCode);
        }
        $this->assertDatabaseCount('export_jobs', 1);
        $this->assertDatabaseCount('export_items', 1);
    }

    public function test_expiry_and_deleted_bytes_rebuild_exactly_without_removing_formal_history(): void
    {
        $f = $this->fixture();
        $s = app(ExportService::class);
        $a = $s->create($f['actor'], $this->input($f), 'original');
        $s->process($a['id']);
        $bytes = $this->bytes($a);
        $this->travel(31)->days();
        $this->get('/api/v1/exports/'.$a['id'].'/download')->assertStatus(410);
        $b = $s->rebuild($f['actor'], $a['id'], 'rebuild');
        $s->process($b['id']);
        $this->assertSame($a['manifest_hash'], $b['manifest_hash']);
        $this->assertSame($bytes, $this->bytes($b));
        $this->assertSame($b['id'], $s->rebuild($f['actor'], $a['id'], 'rebuild')['id']);
        $this->travelBack();
        $s->deleteFile($f['actor'], $a['id'], 'delete-file');
        $s->deleteFile($f['actor'], $a['id'], 'delete-file');
        $this->assertSame('expired', $s->read($f['actor'], $a['id'])['status']);
        try {
            $s->deleteFile($f['actor'], $b['id'], 'delete-file');
            $this->fail();
        } catch (NovelProblem $e) {
            $this->assertSame('IDEMPOTENCY_CONFLICT', $e->errorCode);
        }
        $this->assertDatabaseCount('canonical_commits', 1);
        $this->assertDatabaseCount('chapter_versions', 1);
        $this->assertDatabaseCount('export_files', 2);
        $this->get('/api/v1/exports/'.$a['id'].'/manifest')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_download_checks_content_hash_and_does_not_return_private_path(): void
    {
        $f = $this->fixture();
        $s = app(ExportService::class);
        $job = $s->create($f['actor'], $this->input($f), 'download');
        $s->process($job['id']);
        $this->get('/api/v1/exports/'.$job['id'].'/download')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $path = DB::table('export_files')->where('export_job_id', $job['id'])->value('path');
        $this->getJson('/api/v1/exports/'.$job['id'])->assertOk()->assertDontSee($path);
        Storage::disk('local')->put($path, '损坏');
        $this->getJson('/api/v1/exports/'.$job['id'].'/download')->assertStatus(409)->assertJsonPath('error.code', 'FILE_HASH_MISMATCH');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/exports/'.$job['id'].'/download')->assertUnauthorized();
    }

    public function test_duplicate_real_database_queue_messages_create_only_one_private_file(): void
    {
        $f = $this->fixture();
        $s = app(ExportService::class);
        $job = $s->create($f['actor'], $this->input($f), 'queue');
        // 两条真实数据库队列消息，消费者走标准Job处理；不依赖共享Redis。
        foreach ([1, 2] as $i) {
            BuildFrozenExport::dispatch($job['id'])->onConnection('database')->onQueue('export');
        }
        $this->assertDatabaseCount('jobs', 2);
        foreach ([1, 2] as $i) {
            Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'export', '--once' => true, '--tries' => 1]);
        }
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('export_files', 1);
        $this->assertSame('ready', $s->read($f['actor'], $job['id'])['status']);
        $this->assertCount(1, Storage::disk('local')->files('exports'));
        Http::assertNothingSent();
    }

    public function test_failed_private_write_is_preserved_and_rebuild_uses_same_manifest(): void
    {
        $f = $this->fixture();
        $s = app(ExportService::class);
        $job = $s->create($f['actor'], $this->input($f), 'failure');
        $manager = Storage::getFacadeRoot();
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andThrow(new \RuntimeException('secret-probe-must-not-leak'));
        $disk->shouldReceive('delete')->once()->andReturn(true);
        try {
            Storage::shouldReceive('disk')->with('local')->andReturn($disk);
            $s->process($job['id']);
        } finally {
            Storage::swap($manager);
        }
        $failed = $s->read($f['actor'], $job['id']);
        $this->assertSame('failed', $failed['status']);
        $this->assertSame('EXPORT_BUILD_FAILED', $failed['error_code']);
        $next = $s->rebuild($f['actor'], $job['id'], 'repair');
        $s->process($next['id']);
        $this->assertSame('ready', $s->read($f['actor'], $next['id'])['status']);
        $this->assertSame('failed', $s->read($f['actor'], $job['id'])['status']);
        // 模拟旧消费者写文件时租约过期；旧令牌不能提交，补投后新尝试可成功。
        $lost = $s->create($f['actor'], $this->input($f), 'expired-lease');
        Queue::fake();
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturnUsing(function () use ($s, $f, $lost) {
            $this->assertSame('building', $s->read($f['actor'], $lost['id'])['status']);
            DB::table('export_jobs')->where('id', $lost['id'])->update(['lease_until' => now()->subSecond()]);
            $this->assertSame(1, $s->publish()['recovered']);

            return true;
        });
        $disk->shouldReceive('delete')->once()->andReturn(true);
        try {
            Storage::shouldReceive('disk')->with('local')->andReturn($disk);
            $s->process($lost['id']);
        } finally {
            Storage::swap($manager);
        }
        $this->assertSame('queued', $s->read($f['actor'], $lost['id'])['status']);
        $this->assertFalse(DB::table('export_files')->where('export_job_id', $lost['id'])->exists());
        $s->process($lost['id']);
        $this->assertSame('ready', $s->read($f['actor'], $lost['id'])['status']);

    }

    public function test_unicode_draft_export_observation_diff_and_html_are_safe(): void
    {
        $f = $this->fixture();
        $body = "甲😀乙\n𠮷字与中文，标点。\n<script>alert(1)</script>";
        $new = app(DraftingService::class)->save($f['actor'], $f['novel'], 'chapter', $f['body']['node_id'], ['plan_id' => $f['plan']['id'], 'source_version_id' => $f['body']['id'], 'expected_version' => 1, 'body' => $body, 'title' => '𠮷字与😀的新章名', 'reason' => '仅候选Unicode安全测试'], 'edit');
        $s = app(ExportService::class);
        $p = $this->input($f);
        unset($p['revision_id']);
        $p['scope'] = 'draft';
        $p['options'] = ['filename' => "../危险/文件\r\n名"];
        $job = $s->create($f['actor'], $p, 'unicode');
        $s->process($job['id']);
        $ready = $s->read($f['actor'], $job['id']);
        $this->assertStringContainsString('【草稿】', $this->bytes($job));
        $this->assertStringContainsString($body, $this->bytes($job));
        $this->assertDoesNotMatchRegularExpression('/[\r\n\/\\\\]/', $ready['file']['filename']);
        $r = app(ReadingService::class);
        $d = ['chapter_version_id' => $new['id'], 'kind' => 'issue', 'quote' => '😀', 'start_offset' => 1, 'end_offset' => 2, 'note' => '保留精确码点'];
        $mark = $r->observe($f['actor'], $f['novel'], $d, 'issue');
        $this->assertSame($mark, $r->observe($f['actor'], $f['novel'], $d, 'issue'));
        try {
            $r->observe($f['actor'], $f['novel'], array_replace($d, ['end_offset' => 3]), 'bad-quote');
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quote', $e->errors());
        }
        try {
            $r->observe($f['actor'], $f['novel'], ['chapter_version_id' => $new['id'], 'kind' => 'issue', 'quote' => $body, 'start_offset' => 0, 'end_offset' => mb_strlen($body) + 1, 'note' => '越界终点不能截断后蒙混通过'], 'out-of-range');
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quote', $e->errors());
        }
        $diff = $r->compare($f['actor'], $f['novel'], $f['body']['id'], $new['id']);
        $this->assertTrue($diff['changed']);
        $this->assertStringContainsString('😀', $diff['added']);
        Livewire::test(FormalReader::class, ['novel' => $f['novel']])->set('scope', 'draft')->assertSee('&lt;script&gt;', false)->assertDontSeeHtml('<script>alert(1)</script>');
        $this->assertDatabaseCount('canonical_commits', 1);
    }

    public function test_authorization_forbidden_and_owner_mismatch_deny_private_sources(): void
    {
        $f = $this->fixture();
        $s = app(ExportService::class);
        $job = $s->create($f['actor'], $this->input($f), 'auth');
        $this->get('/admin/formal-reader?novel='.$f['novel'].'&chapter[]=1')->assertNotFound();
        $other = User::factory()->create(['is_admin' => false]);
        $this->actingAs($other)->getJson('/api/v1/exports/'.$job['id'])->assertForbidden();
        DB::table('novels')->where('id', $f['novel'])->update(['owner_id' => $other->id]);
        $this->actingAs($f['actor'])->getJson('/api/v1/exports/'.$job['id'])->assertNotFound();
    }

    public function test_database_comments_and_immutable_manifest_are_enforced(): void
    {
        $f = $this->fixture();
        $s = app(ExportService::class);
        $job = $s->create($f['actor'], $this->input($f), 'immutable');
        foreach (['export_jobs', 'export_items', 'export_files', 'reading_observations'] as $table) {
            $tableComment = DB::selectOne('SELECT obj_description(?::regclass) AS c', [$table])->c;
            $this->assertMatchesRegularExpression('/\p{Han}/u', $tableComment);
            foreach (DB::select('SELECT col_description(attrelid,attnum) AS c FROM pg_attribute WHERE attrelid=?::regclass AND attnum>0 AND NOT attisdropped', [$table]) as $col) {
                $this->assertMatchesRegularExpression('/\p{Han}/u', $col->c ?? '');
            }
        }
        foreach (['export_jobs' => ['manifest' => '{}'], 'export_items' => ['title' => '篡改标题']] as $table => $update) {
            try {
                DB::transaction(fn () => DB::table($table)->update($update));
                $this->fail();
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame($job['manifest_hash'], $s->read($f['actor'], $job['id'])['manifest_hash']);
    }

    public function test_export_page_is_list_first_and_cancel_does_not_create_job(): void
    {
        $f = $this->fixture();
        Livewire::test(ExportCenter::class, ['novel' => $f['novel']])->assertSee('导出任务列表')->assertDontSeeHtml('id="nf-export-form"')->call('begin')->assertSet('editing', true)->assertSeeHtml('id="nf-export-form"')->call('preflight')->assertSet('preview.missing', [])->call('closeEditor')->assertSet('editing', false);
        Livewire::test(ExportCenter::class, ['novel' => $f['novel']])->call('begin')->set('form.range.to', 2)->call('preflight')->assertSet('preview.missing', [2])->assertSee('缺项：2');
        Livewire::test(FormalReader::class, ['novel' => $f['novel']])->set('chapter', 2)->assertSee('所选范围缺少正文');
        $this->assertDatabaseCount('export_jobs', 0);
        Livewire::test(ExportCenter::class, ['novel' => $f['novel']])->call('begin')->call('save')->assertHasNoErrors()->assertSet('editing', false);
        $this->assertDatabaseCount('export_jobs', 1);
        Livewire::test(FormalReader::class, ['novel' => $f['novel']])->call('observe', $f['body']['id'], 'read')->assertHasNoErrors()->assertSee('已读');
        $this->assertDatabaseCount('reading_observations', 1);
    }

    public function test_publish_is_recoverable_notification_and_api_is_asynchronous(): void
    {
        $f = $this->fixture();
        Queue::fake();
        $this->postJson('/api/v1/exports', $this->input($f), ['Idempotency-Key' => 'api'])->assertStatus(202)->assertJsonPath('data.status', 'queued');
        $this->assertDatabaseCount('export_jobs', 1);
        $result = app(ExportService::class)->publish();
        $this->assertSame(1, $result['published']);
        Queue::assertPushed(BuildFrozenExport::class);
        $this->getJson('/api/v1/novels/'.$f['novel'].'/exports?status=queued')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/v1/novels/'.$f['novel'].'/reading?range[type]=chapter&range[from]=1')->assertOk()->assertJsonPath('data.scope', 'formal');
    }

    /** 整卷和全书必须覆盖所声明范围，不能把已提交前缀冒充完整作品。 */
    public function test_book_and_volume_missing_ranges_are_complete_and_single_chapter_can_export(): void
    {
        $f = $this->fixture();
        $s = app(ExportService::class);
        $reader = app(ReadingService::class);
        $volume = (string) DB::table('volumes')->where('novel_id', $f['novel'])->orderBy('sequence')->value('id');
        $last = (int) DB::table('chapters')->where('novel_id', $f['novel'])->max('sequence');
        foreach ([['type' => 'book'], ['type' => 'volume', 'volume_id' => $volume]] as $i => $range) {
            $input = $this->input($f);
            $input['range'] = $range;
            $this->assertSame(range(2, $last), $reader->snapshot($f['actor'], $f['novel'], $input)['missing']);
            try {
                $s->create($f['actor'], $input, 'missing-whole-'.$i);
                $this->fail();
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('range', $e->errors());
            }
        }
        $this->assertDatabaseCount('export_jobs', 0);
        $input = $this->input($f);
        $input['range'] = ['type' => 'chapter', 'from' => 1];
        $job = $s->create($f['actor'], $input, 'single-chapter');
        $s->process($job['id']);
        $this->assertSame('ready', $s->read($f['actor'], $job['id'])['status']);
        $this->assertSame(1, $job['manifest']['from']);
        $this->assertSame(1, $job['manifest']['to']);
    }
}
