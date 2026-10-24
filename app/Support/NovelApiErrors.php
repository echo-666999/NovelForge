<?php

namespace App\Support;

use App\Exceptions\NovelProblem;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class NovelApiErrors
{
    public static function render(Throwable $e, Request $request)
    {
        if (! $request->is('api/v1/*')) {
            return null;
        }
        $status = $e instanceof NovelProblem ? $e->status : ($e instanceof ValidationException ? 422 : ($e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500));
        $code = $e instanceof NovelProblem ? $e->errorCode : match ($status) {
            401 => 'AUTH_REQUIRED', 403 => 'FORBIDDEN', 404 => 'NOT_FOUND', 419 => 'CSRF_MISMATCH', 422 => 'VALIDATION_FAILED', default => 'INTERNAL_ERROR'
        };

        return response()->json([
            'error' => ['code' => $code, 'message' => $e instanceof NovelProblem ? $e->getMessage() : ($status === 500 ? '内部错误，请提供请求编号。' : '请求未通过校验或访问检查。'),
                'fields' => $e instanceof ValidationException ? $e->errors() : (object) [], 'retryable' => false, 'suggested_action' => $status === 409 ? '刷新内容并检查版本或操作键。' : '检查输入和登录状态。'],
            'trace_id' => $request->attributes->get('trace_id'), 'request_id' => (string) Str::uuid(), 'version' => 'v1',
        ], $status);
    }
}
