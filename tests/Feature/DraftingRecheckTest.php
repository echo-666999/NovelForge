<?php

namespace Tests\Feature;

use App\Drafting\DraftExecutor;
use App\Exceptions\NovelProblem;
use App\Filament\Resources\NovelResource\Pages\DraftEditor;
use App\Services\DraftingService;
use App\Services\GenerationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\CanonicalFixture;
use Tests\Support\DraftingFixture;
use Tests\TestCase;

/** E02复核使用真实离线正式来源，编辑与展示不得反向改写正式历史。 */
class DraftingRecheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_formal_body_is_visible_and_read_only_when_a_new_candidate_is_edited(): void
    {
        $f = CanonicalFixture::create();
        $commit = CanonicalFixture::commit($f);
        $service = app(DraftingService::class);
        $list = $service->listing($f['actor'], $f['novel']);
        $item = collect($list['items'])->firstWhere('id', $f['body']['node_id']);
        $this->assertSame($f['body']['id'], $item['formal']['id'] ?? null);
        $this->assertSame('committed', $item['status']);
        $this->assertSame('available', $list['formal_status']);
        $this->assertCount(1, $service->listing($f['actor'], $f['novel'], ['status' => 'committed'])['items']);
        $this->actingAs($f['actor']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(DraftEditor::class, ['record' => $f['novel']])
            ->set('selected', $f['body']['id'])->call('inspect')
            ->assertSet('formalDetail.id', $f['body']['id'])
            ->assertSee('正式正文只读区')->assertDontSee('尚未实施正式提交')
            ->set('body', $f['body']['body'].'他另存了一份待审草稿。')->call('autoSave')
            ->assertSet('detail.version', 2)->assertSet('formalDetail.id', $f['body']['id']);
        $list = $service->listing($f['actor'], $f['novel']);
        $item = collect($list['items'])->firstWhere('id', $f['body']['node_id']);
        $this->assertNotSame($item['formal']['id'], $item['candidate']['id']);
        $this->assertSame($f['body']['body_hash'], $item['formal']['body_hash']);
        $this->assertSame('not_reviewed', $item['candidate']['review_status']);
        $this->assertSame(1, DB::table('novels')->where('id', $f['novel'])->value('state_version'));
        $this->assertSame($f['body']['id'], (string) DB::table('chapters')->where('id', $f['body']['node_id'])->value('current_canonical_version_id'));
        $this->assertDatabaseCount('canonical_commits', 1);
        $this->assertSame($commit['id'], $item['formal']['commit_id']);
        $this->getJson('/api/v1/novels/'.$f['novel'].'/drafts?status=committed')
            ->assertOk()->assertJsonPath('data.items.0.formal.id', $f['body']['id'])
            ->assertJsonPath('data.items.0.candidate.id', $item['candidate']['id']);
        $this->assertSame($f['body']['body'], $service->formal($f['actor'], $f['novel'], $f['body']['node_id'])['body']);
        Http::assertNothingSent();
    }

    /** 正文哈希相同不代表来源版本相同；标题换版仍使已冻结后续场景失效。 */
    public function test_normalized_noop_keeps_version_but_title_only_edit_invalidates_frozen_transition(): void
    {
        $f = DraftingFixture::create(true);
        $service = app(DraftingService::class);
        $body = implode("\n", array_slice(explode("\n", DraftingFixture::body()), 0, 25));
        $first = DraftingFixture::generate($f, $body);
        $d = DraftingFixture::payload($f, $body);
        $run = $service->start($f['actor'], $f['novel'], array_replace($d, ['scene_id' => $f['second']['id']]), 'freeze-second');
        $edit = ['plan_id' => $f['plan']['id'], 'source_version_id' => $first['id'], 'expected_version' => 1, 'body' => str_replace("\n", "\r\n", $first['body']), 'title' => $first['title'], 'reason' => '规范换行相同的自动保存'];
        $noop = $service->save($f['actor'], $f['novel'], 'scene', $first['node_id'], $edit, 'noop');
        $this->assertSame($first['id'], $noop['id']);
        $this->assertDatabaseCount('scene_versions', 1);
        $changed = $service->save($f['actor'], $f['novel'], 'scene', $first['node_id'], array_replace($edit, ['title' => '封记与册页的新标题']), 'title-only');
        $this->assertNotSame($first['id'], $changed['id']);
        $this->assertSame($first['body_hash'], $changed['body_hash']);
        $row = DB::table('generation_runs')->find($run['run_id']);
        try {
            $service->verifyFrozen($row, json_decode($row->frozen_config, true));
            $this->fail('同文换版也必须重新冻结后续衔接');
        } catch (NovelProblem $e) {
            $this->assertSame('DEPENDENCY_MISMATCH', $e->errorCode);
        }
        $this->assertSame(0, DB::table('ai_requests')->where('run_id', $run['run_id'])->count());
        $this->assertSame($first['title'], $service->read($f['actor'], $f['novel'], 'scene', $first['id'])['title']);
        Http::assertNothingSent();
    }

    /** 返回期间作者已另存候选时，旧生成结果只留证据，不覆盖作者新版本。 */
    public function test_completed_gateway_response_is_quarantined_after_concurrent_candidate_edit(): void
    {
        $f = DraftingFixture::create();
        $first = DraftingFixture::generate($f);
        $service = app(DraftingService::class);
        $d = array_replace(DraftingFixture::payload($f), ['expected_candidate_version' => 1]);
        $run = $service->start($f['actor'], $f['novel'], $d, 'regenerate');
        $generation = app(GenerationService::class);
        $claim = $generation->claim($f['actor'], $f['novel'], $run['run_id'], 'scene');
        $reply = (new DraftExecutor)->execute($claim['input']);
        $this->assertSame('succeeded', $reply->status);
        $edited = $service->save($f['actor'], $f['novel'], 'scene', $first['node_id'], ['plan_id' => $f['plan']['id'], 'source_version_id' => $first['id'], 'expected_version' => 1, 'body' => $first['body'].'他先将原稿另存。', 'title' => $first['title'], 'reason' => '生成返回前作者保存候选'], 'author-edit');
        $generation->complete($f['actor'], $f['novel'], $run['run_id'], $claim, $reply);
        $step = DB::table('generation_steps')->find($claim['id']);
        $this->assertSame('quarantined', $step->status);
        $this->assertSame('DEPENDENCY_MISMATCH', $step->error_code);
        $this->assertSame('scene_evidence', DB::table('generation_artifacts')->where('run_id', $run['run_id'])->value('type'));
        $this->assertSame(1, DB::table('ai_requests')->where('run_id', $run['run_id'])->count());
        $this->assertSame(0, DB::table('scene_versions')->where('run_id', $run['run_id'])->count());
        $this->assertDatabaseCount('scene_versions', 2);
        $this->assertSame($edited['body_hash'], $service->read($f['actor'], $f['novel'], 'scene', $edited['id'])['body_hash']);
        $this->assertSame(0, DB::table('novels')->where('id', $f['novel'])->value('state_version'));
        $this->assertDatabaseCount('story_events', 0);
        Http::assertNothingSent();
    }
}
