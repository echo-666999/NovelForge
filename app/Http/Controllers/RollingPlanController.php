<?php

namespace App\Http\Controllers;

use App\Exceptions\NovelProblem;
use App\Services\RollingPlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RollingPlanController extends Controller
{
    public function __invoke(Request $r, RollingPlanService $s)
    {
        if (! $r->user()) {
            throw new NovelProblem('AUTH_REQUIRED', '请先登录。', 401);
        }
        $id = $r->route('novel');
        $command = $r->route('planCommand');
        // 写命令使用服务层幂等和乐观版本，读接口同样核对小说所有权。
        $data = match ($command) {
            'list' => $s->listing($r->user(), $id, $r->query()), 'read' => $s->read($r->user(), $id, $r->route('plan')), default => $s->execute($r->user(), $id, $command, $r->except('_token'), $r->header('Idempotency-Key', ''))
        };

        return response()->json(['data' => $data, 'request_id' => (string) Str::uuid(), 'version' => 'v1'], $command === 'save' ? 201 : 200);
    }
}
