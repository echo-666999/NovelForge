<?php

namespace App\Http\Controllers;

use App\Exceptions\NovelProblem;
use App\Services\ExportService;
use App\Services\ReadingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** E08统一会话鉴权及响应信封；用户不能传文件路径或替换书级归属。 */
final class ReadingExportController extends Controller
{
    public function __invoke(Request $request, ReadingService $reader, ExportService $exports)
    {
        $actor = $request->user() ?? throw new NovelProblem('AUTH_REQUIRED', '请先登录。', 401);
        $action = $request->route('readingAction');
        $novel = (string) $request->route('novel');
        $id = (string) $request->route('export');
        $d = $request->except('_token');
        $key = $request->header('Idempotency-Key', '');
        if ($action === 'download' || $action === 'manifest') {
            return $exports->download($actor, $id, $action === 'manifest');
        }
        if ($action === 'observations') {
            Validator::make($d, ['page' => 'sometimes|integer|min:1|max:100000'])->validate();
        }
        $data = match ($action) {
            'read' => $reader->snapshot($actor, $novel, $d),
            'compare' => $reader->compare($actor, $novel, (string) $request->input('left', ''), (string) $request->input('right', '')),
            'observe' => $reader->observe($actor, $novel, $d, $key),
            'observations' => $reader->observations($actor, $novel, (int) $request->query('page', 1)),
            'list' => $exports->listing($actor, $novel, $d),
            'create' => $exports->create($actor, $d, $key),
            'rebuild' => $exports->rebuild($actor, $id, $key),
            'delete' => (function () use ($exports, $actor, $id, $key) {
                $exports->deleteFile($actor, $id, $key);

                return ['deleted' => true];
            })(),
            default => $exports->read($actor, $id),
        };

        return response()->json(['data' => $data, 'request_id' => (string) Str::uuid(), 'version' => 'v1'], in_array($action, ['create', 'rebuild'], true) ? 202 : ($action === 'observe' ? 201 : 200))->header('Cache-Control', 'private, no-store');
    }
}
