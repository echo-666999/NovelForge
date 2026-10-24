<?php

namespace App\Http\Middleware;

use App\Exceptions\NovelProblem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** 服务端生成追踪号；请求结束清除连接上下文，禁止长驻进程串号。 */
final class OperationTrace
{
    public function handle(Request $request, Closure $next)
    {
        $trace = (string) Str::uuid();
        $request->attributes->set('trace_id', $trace);
        // 审计原因只取显式字段，绝不复制完整表单或请求正文。
        $payload = $request->all();
        if (is_string($request->input('payload_json')) && strlen($request->input('payload_json')) <= 50000) {
            $decoded = json_decode($request->input('payload_json'), true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
        $reason = is_string($payload['reason'] ?? null) ? mb_substr($payload['reason'], 0, 2000) : '';
        if (is_string($payload['api_key'] ?? null) && $payload['api_key'] !== '') {
            $reason = str_replace($payload['api_key'], '[REDACTED]', $reason);
        }
        DB::select("SELECT set_config('novelforge.reason',?,false)", [$reason]);
        DB::select("SELECT set_config('novelforge.trace_id',?,false)", [$trace]);
        try {
            $response = $next($request);
            $response->headers->set('X-Trace-Id', $trace);
            // Laravel路由管线可能已把异常转换为响应，仍需记录失败而不是只依赖catch。
            if ($response->getStatusCode() >= 400 && $request->user()?->is_admin && ! $request->isMethod('GET')) {
                $this->rejected($request, $trace, 'HTTP_'.$response->getStatusCode());
            }

            return $response;
        } catch (Throwable $e) {
            // 失败命令的事务已回滚；仅追加稳定错误码，不记录正文、密钥或异常原文。
            if ($request->user()?->is_admin && ! $request->isMethod('GET')) {
                $this->rejected($request, $trace, $e instanceof NovelProblem ? $e->errorCode : 'REQUEST_FAILED');
            }
            throw $e;
        } finally {
            DB::select("SELECT set_config('novelforge.trace_id','',false),set_config('novelforge.reason','',false)");
        }
    }

    /** 失败动作只有在确认归属后才关联运行，越权失败不泄漏其他书的标识。 */
    private function rejected(Request $request, string $trace, string $code): void
    {
        $run = null;
        $id = (string) $request->route('run', '');
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $id) && (strlen($id) < 19 || strcmp($id, '9223372036854775807') <= 0)) {
            $run = DB::table('generation_runs as r')->join('novels as n', 'n.id', '=', 'r.novel_id')->join('run_traces as t', 't.run_id', '=', 'r.id')->where('r.id', $id)->where('n.owner_id', $request->user()->id)->first(['r.id', 'r.novel_id', 't.trace_id']);
        }
        DB::table('audit_events')->insert(['trace_id' => $run?->trace_id ?? $trace, 'operation_trace_id' => $trace, 'novel_id' => $run?->novel_id, 'run_id' => $run?->id, 'actor_id' => $request->user()->id,
            'object_type' => 'http_command', 'object_id' => 'request', 'action' => 'rejected', 'outcome' => $code, 'high_risk' => true, 'reason' => '命令未完成，请按同操作追踪号核对响应及持久状态']);
    }
}
