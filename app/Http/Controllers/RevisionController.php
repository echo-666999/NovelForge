<?php

namespace App\Http\Controllers;

use App\Exceptions\NovelProblem;
use App\Services\NovelIntentService;
use App\Services\RevisionPipelineService;
use App\Services\RevisionService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** E05沿用同源会话和CSRF，所有读写由服务校验管理员与小说归属。 */
final class RevisionController extends Controller
{
    public function __invoke(Request $r, RevisionService $s)
    {
        $actor = $r->user() ?? throw new NovelProblem('AUTH_REQUIRED', '请先登录。', 401);
        $novel = $r->route('novel');
        // 在解析任何修订载荷前拒绝非管理员或跨书访问。
        app(NovelIntentService::class)->novel($actor, $novel);
        $id = $r->route('revision');
        $action = $r->route('revisionAction');
        $d = $r->except('_token');
        $key = $r->header('Idempotency-Key', '');
        $status = $r->isMethod('post') ? 201 : 200;
        $data = match ($action) {
            'start' => app(RevisionPipelineService::class)->start($actor, $novel, $id, $d, $key),'propose' => $s->propose($actor, $novel, $id, $d, $key),'decide' => $s->decide($actor, $novel, $id, $d, $key),'confirm' => $s->confirm($actor, $novel, $id, $d, $key), 'assess' => $s->assess($actor, $novel, $id, $d, $key),default => $r->isMethod('post') ? $s->create($actor, $novel, $d, $key) : ($id ? $s->read($actor, $novel, $id) : $s->listing($actor, $novel))
        };

        return response()->json(['data' => $data, 'request_id' => (string) Str::uuid(), 'version' => 'v1'], $action === 'start' ? 202 : $status)->header('Cache-Control', 'private, no-store');
    }
}
