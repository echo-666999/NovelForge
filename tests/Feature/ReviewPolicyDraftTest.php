<?php

namespace Tests\Feature;

use App\Exceptions\NovelProblem;
use App\Models\User;
use App\Review\ReviewContract;
use App\Services\AuthoringConfigurationService;
use App\Services\ChapterReviewService;
use App\Services\ReviewPolicyService;
use App\Support\ConfigurationExamples;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\ReviewFixture;
use Tests\TestCase;

/** 草稿录入与发布门禁分开验证，所有生命周期操作仅作用于隔离测试库。 */
class ReviewPolicyDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_unpublished_style_can_be_saved_but_policy_publication_stays_guarded(): void
    {
        Http::preventStrayRequests();
        $actor = User::factory()->create(['is_admin' => true]);
        $cfg = app(AuthoringConfigurationService::class);
        $service = app(ReviewPolicyService::class);
        $payload = ConfigurationExamples::all()['style'];
        $wrong = $cfg->execute($actor, 'style', 'save', $payload, 'wrong-stage');
        $payload['key'] = 'draft.body';
        $payload['stage'] = 'drafting.scene';
        $style = $cfg->execute($actor, 'style', 'save', $payload, 'body');
        $policyPayload = ['key' => 'draft.review', 'expected_version' => 0, 'style_version_id' => $style['id'], 'content' => ReviewContract::defaults(), 'reason' => '测试草稿保存'];
        $policy = $service->execute($actor, 'save', $policyPayload, 'save');
        $this->assertSame('draft', $policy['status']);
        $this->assertSame($policy, $service->execute($actor, 'save', $policyPayload, 'save'));
        $this->assertDatabaseCount('review_policy_versions', 1);
        $this->assertDatabaseCount('review_policy_states', 0);
        $publish = ['policy_version_id' => $policy['id'], 'reason' => '隔离测试发布检查'];
        $reject = function (callable $fn) {
            try {
                $fn();
                $this->fail('未发布或错误文风应被拒绝');
            } catch (NovelProblem $e) {
                $this->assertSame('POLICY_STYLE_MISMATCH', $e->errorCode);
            }
        };
        $reject(fn () => $service->execute($actor, 'publish', $publish, 'publish'));
        $cfg->execute($actor, 'style', 'test', ['version_id' => $style['id']], 'test');
        $second = $policyPayload;
        $second['key'] = 'tested.review';
        $this->assertSame('draft', $service->execute($actor, 'save', $second, 'save-tested')['status']);
        $reject(fn () => $service->execute($actor, 'publish', $publish, 'publish'));
        $wrongPayload = $policyPayload;
        $wrongPayload['key'] = 'wrong.review';
        $wrongPayload['style_version_id'] = $wrong['id'];
        $reject(fn () => $service->execute($actor, 'save', $wrongPayload, 'wrong'));
        $cfg->execute($actor, 'style', 'publish', ['version_id' => $style['id']], 'publish-style');
        $this->assertSame('published', $service->execute($actor, 'publish', $publish, 'publish')['status']);
        $cfg->execute($actor, 'style', 'retire', ['version_id' => $style['id']], 'retire-style');
        $secondPolicy = $service->listing($actor)[0];
        $reject(fn () => $service->execute($actor, 'publish', ['policy_version_id' => $secondPolicy['id'], 'reason' => '退役引用应拒绝'], 'retired'));
        $this->assertSame($policy['content_hash'], $service->read($actor, $policy['id'])['content_hash']);
        $this->assertDatabaseCount('ai_requests', 0);
        Http::assertNothingSent();
    }

    /** 文风退役阻断新评审，但不能破坏历史结果的冻结核验。 */
    public function test_new_review_rechecks_style_without_invalidating_history(): void
    {
        $f = ReviewFixture::create();
        $old = ReviewFixture::review($f);
        app(AuthoringConfigurationService::class)->execute($f['actor'], 'style', 'retire', ['version_id' => $f['style']], 'retire');
        $this->assertTrue(app(ChapterReviewService::class)->read($f['actor'], $f['novel'], $old['id'])['current_eligible']);
        try {
            ReviewFixture::review($f);
            $this->fail('退役文风不能用于新评审');
        } catch (NovelProblem $e) {
            $this->assertSame('POLICY_STYLE_MISMATCH', $e->errorCode);
        }
    }
}
