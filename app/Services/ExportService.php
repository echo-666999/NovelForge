<?php

namespace App\Services;

use App\Exceptions\NovelProblem;
use App\Jobs\BuildFrozenExport;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** E08：数据库清单是持久意图，队列只是唤醒；所有文件仅由冻结来源生成。 */
final class ExportService
{
    private function hash(array $data): string
    {
        return hash('sha256', NovelIntentService::canonical($data));
    }

    private function commandKey(string $key): void
    {
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D']])->validate();
    }

    /** 同书行锁下冻结；同键重试先返回原任务，不再解析后来变化的正式指针。 */
    public function create(User $actor, array $d, string $key, ?array $frozen = null, ?string $parent = null): array
    {
        $this->commandKey($key);
        Validator::make(['d' => $d], ['d' => 'required|array:novel_id,revision_id,range,format,options,scope'])->validate();
        Validator::make($d, ['novel_id' => 'required|string', 'format' => 'required|in:txt,md', 'options' => 'sometimes|array:toc,volume_titles,author,synopsis,separator,filename', 'options.toc' => 'sometimes|boolean', 'options.volume_titles' => 'sometimes|boolean', 'options.author' => 'sometimes|string|max:200', 'options.synopsis' => 'sometimes|string|max:4000', 'options.separator' => 'sometimes|in:blank,rule', 'options.filename' => 'sometimes|string|max:100'])->validate();

        return DB::transaction(function () use ($actor, $d, $key, $frozen, $parent) {
            $novel = $d['novel_id'];
            app(NovelIntentService::class)->novel($actor, $novel, true);
            $where = ['actor_id' => $actor->id, 'novel_id' => $novel, 'command_key' => $key];
            $requestHash = $this->hash(['input' => $d, 'parent' => $parent]);
            if ($old = DB::table('export_jobs')->where($where)->first()) {
                if ($old->request_hash !== $requestHash) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '导出键已绑定不同请求。');
                }

                return $this->read($actor, (string) $old->id);
            }
            $m = $frozen ?? app(ReadingService::class)->snapshot($actor, $novel, $d);
            if ($m['missing'] || ! $m['items']) {
                throw ValidationException::withMessages(['range' => '缺少可导出的精确版本：'.implode('、', array_slice($m['missing'], 0, 100))]);
            }
            if (! $frozen) {
                $m['template_version'] = 'e08-text-v1';
                $m['frozen_at'] = now()->toISOString();
                $m['format'] = $d['format'];
                $m['options'] = array_replace(['toc' => true, 'volume_titles' => true, 'author' => '', 'synopsis' => '', 'separator' => 'blank', 'filename' => ''], $d['options'] ?? []);
                // 清单存版本身份而不是可变正文副本；正文必须按哈希重新核实。
                foreach ($m['items'] as &$item) {
                    unset($item['body']);
                }unset($item);
            }
            $id = (string) DB::table('export_jobs')->insertGetId($where + ['revision_id' => $m['revision_id'], 'scope' => $m['scope'], 'format' => $m['format'], 'manifest' => NovelIntentService::canonical($m), 'manifest_hash' => $this->hash($m), 'parent_job_id' => $parent, 'request_hash' => $requestHash]);
            foreach ($m['items'] as $item) {
                DB::table('export_items')->insert($item + ['novel_id' => $novel, 'export_job_id' => $id, 'revision_id' => $m['revision_id']]);
            }

            return $this->read($actor, $id);
        });
    }

    /** 过期或已删除文件从同一不可变清单生成新任务和新文件记录，不重选正文。 */
    public function rebuild(User $actor, string $id, string $key): array
    {
        $job = $this->read($actor, $id);

        return $this->create($actor, ['novel_id' => $job['novel_id'], 'format' => $job['format']], $key, $job['manifest'], $id);
    }

    /** 只返回公开任务字段，绝不将私有路径作为API下载地址。 */
    public function read(User $actor, string $id): array
    {
        Validator::make(['id' => $id], ['id' => 'required|integer|min:1|max:9223372036854775807'])->validate();
        app(NovelIntentService::class)->authorize($actor);
        $row = DB::table('export_jobs')->where('actor_id', $actor->id)->where('id', $id)->first() ?? throw new NovelProblem('NOT_FOUND', '导出任务不存在。', 404);
        app(NovelIntentService::class)->novel($actor, (string) $row->novel_id);
        $m = json_decode($row->manifest, true, flags: JSON_THROW_ON_ERROR);
        if ($this->hash($m) !== $row->manifest_hash) {
            throw new NovelProblem('MANIFEST_MISMATCH', '冻结清单校验失败。');
        }
        $file = DB::table('export_files')->where('export_job_id', $id)->first();
        $status = $file && ($file->deleted_at || now()->gte(Carbon::parse($file->expires_at))) ? 'expired' : $row->status;

        return ['id' => $id, 'run_id' => $id, 'run_type' => 'export', 'status_url' => '/api/v1/exports/'.$id, 'novel_id' => (string) $row->novel_id, 'revision_id' => $row->revision_id ? (string) $row->revision_id : null, 'scope' => $row->scope, 'format' => $row->format, 'status' => $status, 'error_code' => $row->error_code, 'manifest' => $m, 'manifest_hash' => $row->manifest_hash, 'parent_job_id' => $row->parent_job_id ? (string) $row->parent_job_id : null, 'created_at' => $row->created_at, 'file' => $file ? ['id' => (string) $file->id, 'filename' => $file->filename, 'checksum' => $file->checksum, 'byte_size' => $file->byte_size, 'expires_at' => $file->expires_at, 'deleted_at' => $file->deleted_at] : null];
    }

    /** 有界列表保留真实筛选；状态过期由时间和派生文件事实投影。 */
    public function listing(User $actor, string $novel, array $filters = []): array
    {
        app(NovelIntentService::class)->novel($actor, $novel);
        Validator::make($filters, ['page' => 'sometimes|integer|min:1|max:100000', 'format' => 'nullable|in:txt,md', 'status' => 'nullable|in:queued,building,ready,failed,expired', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from'])->validate();
        $q = DB::table('export_jobs as j')->leftJoin('export_files as f', 'f.export_job_id', '=', 'j.id')->where('j.novel_id', $novel)->where('j.actor_id', $actor->id);
        if (! empty($filters['format'])) {
            $q->where('j.format', $filters['format']);
        }
        $state = "CASE WHEN f.deleted_at IS NOT NULL OR f.expires_at <= CURRENT_TIMESTAMP THEN 'expired' ELSE j.status END";
        if (! empty($filters['status'])) {
            $q->whereRaw("($state)=?", [$filters['status']]);
        }
        if (! empty($filters['from'])) {
            $q->where('j.created_at', '>=', $filters['from'].'T00:00:00+00:00');
        }
        if (! empty($filters['to'])) {
            $q->where('j.created_at', '<', Carbon::parse($filters['to'], 'UTC')->addDay());
        }
        $total = $q->count();
        $page = (int) ($filters['page'] ?? 1);

        return ['rows' => $q->orderByDesc('j.id')->forPage($page, 25)->pluck('j.id')->map(fn ($id) => $this->read($actor, (string) $id))->all(), 'total' => $total, 'page' => $page];
    }

    /** 定时扫描可重复补投，投递失败只记固定码，持久queued意图不会丢失。 */
    public function publish(int $limit = 100): array
    {
        // 到期租约回到持久排队意图，旧消费者的令牌随后无法提交。
        $recovered = DB::table('export_jobs')->where('status', 'building')->where('lease_until', '<=', now())->update(['status' => 'queued', 'lease_token' => null, 'lease_until' => null, 'error_code' => 'LEASE_EXPIRED', 'updated_at' => now()]);
        $sent = 0;
        $failed = 0;
        foreach (DB::table('export_jobs')->where('status', 'queued')->orderBy('id')->limit(max(1, min($limit, 100)))->pluck('id') as $id) {
            try {
                BuildFrozenExport::dispatch((string) $id)->onQueue('export');
                $sent++;
            } catch (\Throwable) {
                $failed++;
                DB::table('export_jobs')->where('id', $id)->where('status', 'queued')->update(['error_code' => 'QUEUE_UNAVAILABLE', 'updated_at' => now()]);
            }
        }

        return ['published' => $sent, 'failed' => $failed, 'recovered' => $recovered];
    }

    /** 先持久化租约再构建；完成与失败均校验令牌，过期消费者不能覆盖新文件。 */
    public function process(string $id): void
    {
        $token = (string) Str::uuid();
        $row = DB::transaction(function () use ($id, $token) {
            $row = DB::table('export_jobs')->where('id', $id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'queued') {
                return null;
            }
            DB::table('export_jobs')->where('id', $id)->update(['status' => 'building', 'error_code' => null, 'lease_token' => $token, 'lease_until' => now()->addSeconds(120), 'updated_at' => now()]);

            return $row;
        });
        if (! $row) {
            return;
        }
        $path = null;
        try {
            $actor = User::findOrFail($row->actor_id);
            $job = $this->read($actor, $id);
            $m = $job['manifest'];
            if (($m['template_version'] ?? '') !== 'e08-text-v1') {
                throw new NovelProblem('TEMPLATE_UNAVAILABLE', '冻结模板版本不可用。');
            }
            $links = DB::table('export_items')->where('export_job_id', $id)->orderBy('sequence')->get();
            if ($links->count() !== count($m['items'])) {
                throw new NovelProblem('MANIFEST_MISMATCH', '冻结章节映射不完整。');
            }
            $bodies = [];
            foreach ($m['items'] as $i => $item) {
                $link = $links[$i];
                foreach (['chapter_id', 'chapter_version_id', 'commit_id', 'sequence', 'title', 'volume_id', 'volume_title', 'body_hash', 'character_count'] as $field) {
                    if ((string) $link->$field !== (string) $item[$field]) {
                        throw new NovelProblem('MANIFEST_MISMATCH', '清单章节映射失配。');
                    }
                }
                $body = DB::table('chapter_versions')->where('novel_id', $row->novel_id)->where('node_id', $item['chapter_id'])->where('id', $item['chapter_version_id'])->first() ?? throw new NovelProblem('SOURCE_UNAVAILABLE', '精确正文版本不可读。');
                app(ReadingService::class)->verifyBody($body);
                if ($body->body_hash !== $item['body_hash'] || $body->title !== $item['title']) {
                    throw new NovelProblem('BODY_HASH_MISMATCH', '冻结正文与版本不一致。');
                }
                if ($row->scope === 'formal' && ! DB::table('canonical_chapters')->where('novel_id', $row->novel_id)->where('revision_id', $row->revision_id)->where('commit_id', $item['commit_id'])->where('chapter_version_id', $item['chapter_version_id'])->where('body_hash', $item['body_hash'])->exists()) {
                    throw new NovelProblem('INVALID_FORMAL_SOURCE', '冻结正式映射不可验证。');
                }
                $bodies[] = $body->body;
            }
            $text = $this->render($m, $bodies);
            $path = 'exports/export-'.$id.'-'.$row->manifest_hash.'-'.$token.'.'.$row->format;
            if (! Storage::disk('local')->put($path, $text)) {
                throw new \RuntimeException('private write failed');
            }
            // 文件名不参与路径拼接；路径完全由服务端任务ID与清单哈希构造。
            $name = preg_replace('/[\p{Cc}<>:"\/\\\\|?*]/u', '_', ($m['options']['filename'] ?: $m['title']));
            $name = trim(mb_substr($name, 0, 80), '. ');
            if ($name === '') {
                $name = 'novel';
            }

            DB::transaction(function () use ($id, $row, $path, $name, $text, $token) {
                $current = DB::table('export_jobs')->where('id', $id)->lockForUpdate()->first();
                if ($current->status !== 'building' || $current->lease_token !== $token || now()->gte(Carbon::parse($current->lease_until))) {
                    throw new NovelProblem('EXPORT_LEASE_LOST', '导出执行租约已失效。');
                }
                DB::table('export_files')->insert(['novel_id' => $row->novel_id, 'export_job_id' => $id, 'path' => $path, 'filename' => $name.'.'.$row->format, 'checksum' => hash('sha256', $text), 'byte_size' => strlen($text), 'expires_at' => now()->addDays(30)]);
                DB::table('export_jobs')->where('id', $id)->update(['status' => 'ready', 'lease_token' => null, 'lease_until' => null, 'updated_at' => now()]);
            });
        } catch (\Throwable $e) {
            // 只清理本令牌的派生文件；诊断绝不包含正文、路径、SQL或认证信息。
            if ($path) {
                try {
                    Storage::disk('local')->delete($path);
                } catch (\Throwable) {
                }
            }
            DB::table('export_jobs')->where('id', $id)->where('status', 'building')->where('lease_token', $token)->update(['status' => 'failed', 'error_code' => $e instanceof NovelProblem ? $e->errorCode : 'EXPORT_BUILD_FAILED', 'lease_token' => null, 'lease_until' => null, 'updated_at' => now()]);
        }
    }

    /** 固定模板保留正文原字节；标题与目录属于外围格式，不重写正文。 */
    private function render(array $m, array $bodies): string
    {
        $md = $m['format'] === 'md';
        $o = $m['options'];
        $lines = [($md ? '# ' : '').($m['scope'] === 'draft' ? '【草稿】' : '').$m['title']];
        if ($o['author'] !== '') {
            $lines[] = '作者：'.$o['author'];
        }
        if ($o['synopsis'] !== '') {
            $lines[] = $o['synopsis'];
        }
        if ($o['toc']) {
            $lines[] = ($md ? '## ' : '').'目录';
            foreach ($m['items'] as $item) {
                $lines[] = ($md ? '- ' : '').'第'.$item['sequence'].'章 '.$item['title'];
            }
        }
        $volume = null;
        foreach ($m['items'] as $i => $item) {
            if ($o['volume_titles'] && $volume !== $item['volume_id']) {
                $lines[] = ($md ? '## ' : '').$item['volume_title'];
                $volume = $item['volume_id'];
            }
            $lines[] = ($md ? '### ' : '').'第'.$item['sequence'].'章 '.$item['title'];
            $lines[] = $bodies[$i];
        }

        return implode($o['separator'] === 'rule' ? "\n\n---\n\n" : "\n\n", $lines)."\n";
    }

    /** 下载每次重新鉴权、校验过期及文件哈希，不能通过已知路径绕过。 */
    public function download(User $actor, string $id, bool $manifest = false)
    {
        $job = $this->read($actor, $id);
        if ($manifest) {
            return response()->streamDownload(fn () => print (NovelIntentService::canonical(['export_id' => $id, 'manifest_hash' => $job['manifest_hash'], 'manifest' => $job['manifest'], 'file' => $job['file']])), 'export-'.$id.'-manifest.json', ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        }
        if ($job['status'] === 'expired') {
            throw new NovelProblem('EXPORT_EXPIRED', '派生文件已过期或删除，请按原清单重新生成。', 410);
        }
        if ($job['status'] !== 'ready') {
            throw new NovelProblem('EXPORT_NOT_READY', '导出尚未就绪。');
        }
        $file = DB::table('export_files')->where('export_job_id', $id)->first();
        try {
            $bytes = Storage::disk('local')->get($file->path);
        } catch (\Throwable) {
            throw new NovelProblem('FILE_HASH_MISMATCH', '私有文件不可读，请按原清单重建。');
        }
        if (! is_string($bytes) || strlen($bytes) !== $file->byte_size || hash('sha256', $bytes) !== $file->checksum) {
            throw new NovelProblem('FILE_HASH_MISMATCH', '私有派生文件缺失或校验不符，请重建。');
        }

        return response()->streamDownload(fn () => print ($bytes), $file->filename, ['Content-Type' => ($job['format'] === 'md' ? 'text/markdown' : 'text/plain').'; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** 仅删除派生字节并保留清单和文件证据，幂等重试不影响正式历史。 */
    public function deleteFile(User $actor, string $id, string $key): void
    {
        $this->commandKey($key);
        $job = $this->read($actor, $id);
        DB::transaction(function () use ($actor, $id, $key, $job) {
            app(NovelIntentService::class)->novel($actor, $job['novel_id'], true);
            $where = ['actor_id' => $actor->id, 'novel_scope' => $job['novel_id'], 'command' => 'export.delete', 'key' => $key];
            $hash = $this->hash(['export_id' => $id]);
            if ($old = DB::table('novel_commands')->where($where)->first()) {
                if ($old->request_hash !== $hash) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '删除操作键已绑定其他派生文件。');
                }

                return;
            }
            $file = DB::table('export_files')->where('export_job_id', $id)->lockForUpdate()->first();
            if (! $file) {
                throw new NovelProblem('EXPORT_NOT_READY', '任务尚未生成派生文件。');
            }
            if (! $file->deleted_at) {
                if (! Storage::disk('local')->delete($file->path)) {
                    throw new NovelProblem('FILE_DELETE_FAILED', '派生文件删除失败，请重试。');
                }
                DB::table('export_files')->where('id', $file->id)->update(['deleted_at' => now()]);
            }
            // 删除也登记独立幂等身份，保留清单、正式正文及操作证据。
            DB::table('novel_commands')->insert($where + ['request_hash' => $hash, 'response' => NovelIntentService::canonical(['export_id' => $id, 'deleted' => true]), 'created_at' => now()]);
        });
    }
}
