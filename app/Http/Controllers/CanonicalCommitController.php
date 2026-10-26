<?php

namespace App\Http\Controllers;

use App\Exceptions\NovelProblem;
use App\Services\CanonicalCommitService;
use App\Services\NovelIntentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** 正式入口沿用同源会话、CSRF和所有权；路径归属不能被正文参数替换。 */
final class CanonicalCommitController extends Controller
{
    public function __invoke(Request $r, CanonicalCommitService $s)
    {
        $actor = $r->user() ?? throw new NovelProblem('AUTH_REQUIRED', '请先登录。', 401);
        $chapter = $r->route('chapter');
        $novel = $r->route('novel');
        if ($chapter !== null) {
            Validator::make(['chapter' => $chapter], ['chapter' => 'required|integer|min:1'])->validate();
            $novel = DB::table('chapters')->where('id', $chapter)->value('novel_id');
            if (! $novel) {
                throw new NovelProblem('NOT_FOUND', '章节不存在。', 404);
            }
        }
        $novel = (string) $novel;
        app(NovelIntentService::class)->novel($actor, $novel);
        $action = $r->route('canonicalAction');
        $d = $r->except('_token');
        $key = $r->header('Idempotency-Key', '');
        if ($chapter !== null && (($d['chapter_id'] ?? null) !== (string) $chapter || ($d['novel_id'] ?? null) !== $novel)) {
            throw new NovelProblem('NOT_FOUND', '路径和提交载荷归属不同。', 404);
        }
        if (in_array($action, ['state', 'replay'], true)) {
            Validator::make($d, ['revision_id' => 'sometimes|integer|min:1', 'chapter' => 'sometimes|integer|min:1'])->validate();
        }
        $data = match ($action) {
            'initialize' => $s->initialize($actor, $novel, $d, $key),
            'preflight' => $s->preflight($actor, $novel, $d, $key),
            'commit' => $s->commit($actor, $novel, $d, $key),
            'replay' => $s->replay($actor, $novel, $r->query('revision_id'), $r->filled('chapter') ? (int) $r->query('chapter') : null),
            'state' => $s->state($actor, $novel, $r->query('revision_id'), $r->filled('chapter') ? (int) $r->query('chapter') : null),
            default => $r->route('commit') ? $s->read($actor, $novel, $r->route('commit')) : $s->listing($actor, $novel, $d)
        };
        $status = $r->isMethod('post') ? 201 : 200;
        if ($action === 'commit' && $data['replayed']) {
            $status = 200;
        }

        return response()->json(['data' => $data, 'request_id' => (string) Str::uuid(), 'version' => 'v1'], $status)->header('Cache-Control', 'private, no-store');
    }
}
