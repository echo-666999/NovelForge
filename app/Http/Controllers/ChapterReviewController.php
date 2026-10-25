<?php

namespace App\Http\Controllers;

use App\Exceptions\NovelProblem;
use App\Services\ChapterReviewService;
use App\Services\ReviewPipelineService;
use App\Services\ReviewPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** 同源会话、CSRF及管理员/同书授权沿用既有中间件与服务。 */
final class ChapterReviewController extends Controller
{
    public function __invoke(Request $request)
    {
        $actor = $request->user() ?? throw new NovelProblem('AUTH_REQUIRED', '请先登录。', 401);
        $action = $request->route('reviewAction');
        $novel = $request->route('novel');
        $id = $request->route('review');
        $post = $request->isMethod('post');
        $status = 200;
        if ($action === 'policies') {
            $data = app(ReviewPolicyService::class)->listing($actor);
        } elseif (in_array($action, ['save', 'publish', 'retire'], true)) {
            $data = app(ReviewPolicyService::class)->execute($actor, $action, $request->except('_token'), $request->header('Idempotency-Key', ''));
            $status = 201;
        } elseif ($action === 'start') {
            $data = app(ReviewPipelineService::class)->start($actor, $novel, $request->except('_token'), $request->header('Idempotency-Key', ''));
            $status = 202;
        } elseif ($action === 'decide') {
            $data = app(ChapterReviewService::class)->decide($actor, $novel, $id, $request->except('_token'), $request->header('Idempotency-Key', ''));
            $status = 201;
        } else {
            $s = app(ChapterReviewService::class);
            $data = $post ? $s->review($actor, $novel, $request->except('_token'), $request->header('Idempotency-Key', '')) : ($id ? $s->read($actor, $novel, $id) : $s->listing($actor, $novel));
            $status = $post ? 201 : 200;
        }

        return response()->json(['data' => $data, 'request_id' => (string) Str::uuid(), 'version' => 'v1'], $status)->header('Cache-Control', 'private, no-store');
    }
}
