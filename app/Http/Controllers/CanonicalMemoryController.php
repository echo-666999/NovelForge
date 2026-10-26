<?php

namespace App\Http\Controllers;

use App\Exceptions\NovelProblem;
use App\Services\CanonicalMemoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** 正式派生状态与显式重试沿用管理员同书授权、CSRF及请求幂等。 */
final class CanonicalMemoryController extends Controller
{
    public function __invoke(Request $r, CanonicalMemoryService $s)
    {
        $actor = $r->user() ?? throw new NovelProblem('AUTH_REQUIRED', '请先登录。', 401);
        $novel = $r->route('novel');
        if ($r->isMethod('post')) {
            $d = $r->except('_token');
            Validator::make(['d' => $d], ['d' => 'required|array:reason'])->validate();
            $data = $s->retry($actor, $novel, $r->route('task'), $d['reason'] ?? '', $r->header('Idempotency-Key', ''));
        } else {
            $data = $s->dashboard($actor, $novel);
        }

        return response()->json(['data' => $data, 'request_id' => (string) Str::uuid(), 'version' => 'v1'])->header('Cache-Control', 'private, no-store');
    }
}
