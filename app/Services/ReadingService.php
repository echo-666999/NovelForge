<?php

namespace App\Services;

use App\Drafting\BodyContract;
use App\Exceptions\NovelProblem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** E08只读取精确版本；已读和问题记录都不能改变正式提交或人工成品认可。 */
final class ReadingService
{
    /** 统一读取与导出选区；书锁保证捕获过程中不会混入另一正式状态。 */
    public function snapshot(User $actor, string $novel, array $d): array
    {
        Validator::make($d, ['scope' => 'sometimes|in:formal,draft', 'revision_id' => 'nullable|integer|min:1|max:9223372036854775807', 'range' => 'required|array:type,from,to,volume_id', 'range.type' => 'required|in:chapter,range,volume,book', 'range.from' => 'required_if:range.type,chapter,range|integer|min:1|max:100000', 'range.to' => 'required_if:range.type,range|integer|min:1|max:100000', 'range.volume_id' => 'required_if:range.type,volume|integer|min:1|max:9223372036854775807'])->validate();

        return DB::transaction(function () use ($actor, $novel, $d) {
            $book = app(NovelIntentService::class)->novel($actor, $novel);
            $book = $book->newQuery()->whereKey($novel)->sharedLock()->firstOrFail();
            $scope = $d['scope'] ?? 'formal';
            if ($scope === 'draft' && ! empty($d['revision_id'])) {
                throw ValidationException::withMessages(['revision_id' => '草稿不能伪装成正式基线。']);
            }
            $revision = $scope === 'formal' ? ($d['revision_id'] ?? $book->active_canonical_revision_id) : null;
            if ($revision && ! DB::table('canonical_revisions')->where('novel_id', $novel)->where('id', $revision)->whereIn('status', ['active', 'superseded'])->exists()) {
                throw new NovelProblem('NOT_FOUND', '正式基线不属于当前小说。', 404);
            }
            $range = $d['range'];
            $from = (int) ($range['from'] ?? 1);
            $to = (int) ($range['to'] ?? $from);
            if ($range['type'] === 'chapter') {
                $to = $from;
            }
            if (in_array($range['type'], ['volume', 'book'], true)) {
                $q = DB::table('chapters as c')->where('c.novel_id', $novel);
                if ($range['type'] === 'volume') {
                    $v = DB::table('volumes')->where('novel_id', $novel)->where('id', $range['volume_id'])->first() ?? throw new NovelProblem('NOT_FOUND', '卷不属于当前小说。', 404);
                    $from = (int) $v->start_chapter;
                    $to = (int) $v->end_chapter;
                    if ($scope === 'formal' && $revision) {
                        // 整卷范围同样来自正式提交的规划快照，后改卷界不缩短旧正式导出。
                        $frozen = DB::table('canonical_commits as c')->join('plan_nodes as p', 'p.id', '=', 'c.plan_node_version_id')->join('plan_nodes as a', 'a.id', '=', 'p.parent_plan_node_id')->join('plan_nodes as v', 'v.id', '=', 'a.parent_plan_node_id')->where('c.novel_id', $novel)->where('c.revision_id', $revision)->where('c.status', 'committed')->where('v.volume_id', $range['volume_id'])->orderByDesc('c.chapter_sequence')->value('v.details');
                        if ($frozen) {
                            $bounds = json_decode($frozen, true);
                            $from = (int) $bounds['start_chapter'];
                            $to = (int) $bounds['end_chapter'];
                        }
                    }
                } else {
                    $from = 1;
                    $to = (int) $q->max('sequence');
                }
            }
            if ($to < $from || $to - $from >= 10000) {
                throw ValidationException::withMessages(['range' => '范围为空、倒置或超过单次10000章上限。']);
            }
            $items = [];
            $missing = [];
            for ($seq = $from; $seq <= $to; $seq++) {
                $chapter = DB::table('chapters')->where('novel_id', $novel)->where('sequence', $seq)->first();
                $mapping = $scope === 'formal' && $revision ? DB::table('canonical_chapters')->where('novel_id', $novel)->where('revision_id', $revision)->where('sequence', $seq)->first() : null;
                $body = $chapter ? DB::table('chapter_versions')->where('novel_id', $novel)->where('node_id', $chapter->id)->when($scope === 'formal', fn ($q) => $q->where('id', $mapping?->chapter_version_id ?? 0))->orderByDesc('version')->first() : null;
                if (! $body) {
                    $missing[] = $seq;

                    continue;
                }
                $this->verifyBody($body);
                if ($mapping && ($mapping->body_hash !== $body->body_hash || ! DB::table('canonical_commits')->where('id', $mapping->commit_id)->where('novel_id', $novel)->where('revision_id', $revision)->where('chapter_version_id', $body->id)->where('status', 'committed')->exists())) {
                    throw new NovelProblem('INVALID_FORMAL_SOURCE', '正式映射与提交证据不一致。');
                }
                // 正式卷名来自提交引用的不可变规划节点，不从当前可编辑大纲猜测。
                if ($mapping) {
                    $planId = DB::table('canonical_commits')->where('id', $mapping->commit_id)->value('plan_node_version_id');
                    $p = DB::table('plan_nodes')->where('id', $planId)->first();
                    $arc = DB::table('plan_nodes')->where('id', $p->parent_plan_node_id)->first();
                    $volume = DB::table('plan_nodes')->where('id', $arc->parent_plan_node_id)->first();
                    $volumeId = (string) $volume->volume_id;
                    $volumeTitle = json_decode($volume->details, true)['title'];
                } else {
                    $arc = DB::table('story_arcs')->where('novel_id', $novel)->where('id', $chapter->parent_id)->first();
                    $volume = DB::table('volumes')->where('novel_id', $novel)->where('id', $arc->parent_id)->first();
                    $volumeId = (string) $volume->id;
                    $volumeTitle = $volume->title;
                }
                if ($range['type'] === 'volume' && $volumeId !== (string) $range['volume_id']) {
                    throw new NovelProblem('INVALID_FORMAL_SOURCE', '所选卷与版本规划不一致，不能混合导出。');
                }
                $items[] = ['chapter_id' => (string) $chapter->id, 'chapter_version_id' => (string) $body->id, 'commit_id' => $mapping ? (string) $mapping->commit_id : null, 'sequence' => $seq, 'title' => $body->title, 'volume_id' => $volumeId, 'volume_title' => $volumeTitle, 'body_hash' => $body->body_hash, 'character_count' => $body->character_count, 'body' => $body->body];
            }

            return ['novel_id' => $novel, 'title' => $book->title, 'scope' => $scope, 'revision_id' => $revision ? (string) $revision : null, 'from' => $from, 'to' => $to, 'missing' => $missing, 'items' => $items];
        });
    }

    /** 字节哈希和Unicode计数均核实，不以候选状态或HTTP成功推断正文有效。 */
    public function verifyBody(object $body): void
    {
        if (! mb_check_encoding($body->body, 'UTF-8') || hash('sha256', $body->body) !== $body->body_hash || BodyContract::count($body->body) !== $body->character_count || $body->character_count < 1) {
            throw new NovelProblem('BODY_HASH_MISMATCH', '正文为空或哈希、字数不匹配。');
        }
    }

    /** 比较同章的任意两个可读版本，保守显示首尾公共行之间的完整替换区间。 */
    public function compare(User $actor, string $novel, string $left, string $right): array
    {
        app(NovelIntentService::class)->novel($actor, $novel);
        $versions = [];
        foreach ([$left, $right] as $id) {
            Validator::make(['id' => $id], ['id' => 'required|integer|min:1|max:9223372036854775807'])->validate();
            $row = DB::table('chapter_versions')->where('novel_id', $novel)->where('id', $id)->first() ?? throw new NovelProblem('NOT_FOUND', '比较版本不存在。', 404);
            $this->verifyBody($row);
            $versions[] = $row;
        }
        [$a,$b] = $versions;
        if ($a->node_id !== $b->node_id) {
            throw new NovelProblem('INVALID_REFERENCE', '只能比较同一章的版本。', 422);
        }
        $x = explode("\n", $a->body);
        $y = explode("\n", $b->body);
        $prefix = [];
        $suffix = [];
        while ($x && $y && $x[0] === $y[0]) {
            $prefix[] = array_shift($x);
            array_shift($y);
        }
        while ($x && $y && end($x) === end($y)) {
            $suffix[] = array_pop($x);
            array_pop($y);
        }

        return ['left_id' => $left, 'right_id' => $right, 'left_title' => $a->title, 'right_title' => $b->title, 'prefix' => implode("\n", $prefix), 'removed' => implode("\n", $x), 'added' => implode("\n", $y), 'suffix' => implode("\n", array_reverse($suffix)), 'changed' => $a->body_hash !== $b->body_hash || $a->title !== $b->title];
    }

    /** 人工问题严格绑定码点引文；只追加阅读记录，不创建评审PASS。 */
    public function observe(User $actor, string $novel, array $d, string $key): array
    {
        Validator::make(['d' => $d, 'key' => $key], ['d' => 'required|array:chapter_version_id,revision_id,kind,quote,start_offset,end_offset,note', 'key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D']])->validate();
        Validator::make($d, ['chapter_version_id' => 'required|integer|min:1|max:9223372036854775807', 'revision_id' => 'nullable|integer|min:1|max:9223372036854775807', 'kind' => 'required|in:read,issue', 'note' => 'required|string|max:4000', 'quote' => 'required_if:kind,issue|string|max:10000', 'start_offset' => 'required_if:kind,issue|integer|min:0', 'end_offset' => 'required_if:kind,issue|integer|gt:start_offset'])->validate();

        return DB::transaction(function () use ($actor, $novel, $d, $key) {
            app(NovelIntentService::class)->novel($actor, $novel, true);
            $hash = hash('sha256', NovelIntentService::canonical($d));
            $where = ['actor_id' => $actor->id, 'novel_id' => $novel, 'command_key' => $key];
            if ($old = DB::table('reading_observations')->where($where)->first()) {
                if ($old->request_hash !== $hash) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '阅读记录键已绑定其他输入。');
                }

                return ['id' => (string) $old->id];
            }
            $body = DB::table('chapter_versions')->where('novel_id', $novel)->where('id', $d['chapter_version_id'])->first() ?? throw new NovelProblem('NOT_FOUND', '阅读版本不存在。', 404);
            $this->verifyBody($body);
            $rev = $d['revision_id'] ?? null;
            if ($rev && ! DB::table('canonical_chapters')->where('novel_id', $novel)->where('revision_id', $rev)->where('chapter_version_id', $body->id)->exists()) {
                throw new NovelProblem('INVALID_REFERENCE', '阅读版本不属于所选正式基线。', 422);
            }
            $issue = $d['kind'] === 'issue';
            if ($issue && ((int) $d['end_offset'] > mb_strlen($body->body, 'UTF-8') || mb_substr($body->body, (int) $d['start_offset'], (int) $d['end_offset'] - (int) $d['start_offset'], 'UTF-8') !== $d['quote'])) {
                throw ValidationException::withMessages(['quote' => '引文必须与正文Unicode码点区间完全相同。']);
            }
            $id = DB::table('reading_observations')->insertGetId($where + ['revision_id' => $rev, 'chapter_id' => $body->node_id, 'chapter_version_id' => $body->id, 'kind' => $d['kind'], 'body_hash' => $body->body_hash, 'quote' => $issue ? $d['quote'] : null, 'start_offset' => $issue ? $d['start_offset'] : null, 'end_offset' => $issue ? $d['end_offset'] : null, 'note' => $d['note'], 'request_hash' => $hash]);

            return ['id' => (string) $id];
        });
    }

    /** 当前书的实际已读和问题，不以其他版本的已读状态覆盖本版本。 */
    public function observations(User $actor, string $novel, int $page = 1): array
    {
        app(NovelIntentService::class)->novel($actor, $novel);
        Validator::make(['page' => $page], ['page' => 'integer|min:1|max:100000'])->validate();

        return DB::table('reading_observations')->where('novel_id', $novel)->where('actor_id', $actor->id)->orderByDesc('id')->forPage($page, 25)->get()->map(function ($r) {
            $row = (array) $r;
            foreach ($row as $k => $v) {
                if ($v !== null && ($k === 'id' || str_ends_with($k, '_id'))) {
                    $row[$k] = (string) $v;
                }
            }

            return $row;
        })->all();
    }
}
