<?php

namespace App\Services;

use App\Exceptions\NovelProblem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** 分层记忆只接收人工或离线摘要，不发起模型请求，也不生成正式事实。 */
final class LayeredMemoryService
{
    public const KINDS = ['working', 'episodic', 'semantic', 'event'];

    public const CATEGORIES = ['irreversible', 'causal', 'knowledge', 'open_issue', 'other'];

    public function execute(User $actor, string $novel, string $command, array $data, string $key): array
    {
        app(NovelIntentService::class)->authorize($actor);
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D']])->validate();

        return DB::transaction(function () use ($actor, $novel, $command, $data, $key) {
            $book = app(NovelIntentService::class)->novel($actor, $novel, true);
            $where = ['actor_id' => $actor->id, 'novel_scope' => $novel, 'command' => 'memory.'.$command, 'key' => $key];
            $hash = $this->hash($data);
            if ($old = DB::table('novel_commands')->where($where)->first()) {
                if ($old->request_hash !== $hash) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '操作键已用于其他记忆输入。');
                }

                return json_decode($old->response, true, flags: JSON_THROW_ON_ERROR);
            }
            $extra = match ($command) {
                'fact' => ['source_version_id', 'evidence_id', 'category', 'statement', 'introduced_chapter'],
                'save' => ['collection_id', 'kind', 'name', 'summary', 'introduced_chapter', 'visibility', 'importance', 'source_ids', 'fact_ids', 'parent_ids', 'retry_of_id', 'chunk_size'],
                'activate' => ['memory_id'], 'invalidate' => ['source_version_id', 'memory_id'],
                default => throw new NovelProblem('INVALID_ACTION', '记忆操作不存在。', 422),
            };
            Validator::make(['data' => $data], ['data' => 'array:'.implode(',', array_merge($extra, ['branch_id', 'expected_version', 'reason']))])->validate();
            Validator::make($data, ['branch_id' => 'required|integer|min:1|max:9223372036854775807', 'expected_version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000'])->validate();
            $branch = (string) $data['branch_id'];
            $this->branch($novel, $branch);
            if ($book->row_version !== (int) $data['expected_version']) {
                throw new NovelProblem('STALE_STATE', '小说输入已变化，请刷新。');
            }
            $r = match ($command) {
                'fact' => $this->fact($actor, $novel, $branch, $data),
                'save' => $this->save($actor, $novel, $branch, $data),
                'activate' => $this->activate($actor, $novel, $branch, $data),
                'invalidate' => $this->invalidate($actor, $novel, $branch, $data),
            };
            $book->row_version++;
            $book->save();
            $r['row_version'] = $book->row_version;
            DB::table('novel_commands')->insert($where + ['request_hash' => $hash, 'response' => NovelIntentService::canonical($r), 'created_at' => now()]);

            return $r;
        });
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', NovelIntentService::canonical($value));
    }

    private function branch(string $novel, mixed $id): object
    {
        Validator::make(['id' => $id], ['id' => 'required|integer|min:1|max:9223372036854775807'])->validate();
        $r = DB::table('generation_runs')->where('novel_id', $novel)->where('id', $id)->first();
        if (! $r) {
            throw new NovelProblem('NOT_FOUND', '候选分支不存在。', 404);
        }

        return $r;
    }

    private function row(string $table, string $novel, string $branch, mixed $id): array
    {
        Validator::make(['id' => $id], ['id' => 'required|integer|min:1|max:9223372036854775807'])->validate();
        $r = DB::table($table)->where('novel_id', $novel)->where('branch_id', $branch)->where('id', $id)->first();
        if (! $r) {
            throw new NovelProblem('NOT_FOUND', '记忆记录不在当前小说和分支。', 404);
        }

        return $this->decode($r);
    }

    private function decode(object $row): array
    {
        $a = (array) $row;
        foreach ($a as $k => &$v) {
            if ($v !== null && ($k === 'id' || str_ends_with($k, '_id'))) {
                $v = (string) $v;
            }
            if (in_array($k, ['payload', 'report', 'visibility'], true)) {
                $v = json_decode($v, true, flags: JSON_THROW_ON_ERROR);
            }
        }

        return $a;
    }

    // 每次读取/激活重验源文本和产物哈希，不能只相信旧摘要的状态字段。
    private function source(string $novel, string $branch, mixed $id): object
    {
        Validator::make(['id' => $id], ['id' => 'required|integer|min:1|max:9223372036854775807'])->validate();
        $s = DB::table('source_documents')->where('novel_id', $novel)->where('run_id', $branch)->where('id', $id)->first();
        if (! $s) {
            throw new NovelProblem('INVALID_SOURCE', '来源不属于同书候选分支。', 422);
        }
        $artifact = DB::table('generation_artifacts')->where('novel_id', $novel)->where('run_id', $branch)->where('id', $s->artifact_id)->first();
        $body = $artifact ? json_decode($artifact->content, true, flags: JSON_THROW_ON_ERROR) : null;
        if (! $artifact || ! is_string($body['text'] ?? null) || $this->hash($body) !== $artifact->content_hash || StoryStateService::normalize($body['text']) !== $s->body || hash('sha256', $s->body) !== $s->source_hash) {
            throw new NovelProblem('SOURCE_HASH_MISMATCH', '来源正文与冻结产物哈希不一致。');
        }
        if (DB::table('memory_invalidations')->where('source_version_id', $s->id)->exists() || $this->branch($novel, $branch)->status === 'canceled') {
            throw new NovelProblem('STALE_SOURCE', '来源或候选分支已停用。');
        }

        return $s;
    }

    private function fact(User $actor, string $novel, string $branch, array $d): array
    {
        Validator::make($d, ['source_version_id' => 'required|integer|min:1', 'evidence_id' => 'required|integer|min:1', 'category' => ['required', Rule::in(self::CATEGORIES)], 'statement' => 'required|string|max:10000', 'introduced_chapter' => 'required|integer|min:1|max:100000'])->validate();
        $source = $this->source($novel, $branch, $d['source_version_id']);
        $e = DB::table('evidence_spans')->where('novel_id', $novel)->where('source_version_id', $source->id)->where('id', $d['evidence_id'])->first();
        if (! $e || $e->source_hash !== $source->source_hash || mb_substr($source->body, $e->start_offset, $e->end_offset - $e->start_offset, 'UTF-8') !== $e->quote || ! str_contains($e->quote, $d['statement'])) {
            throw new NovelProblem('INVALID_EVIDENCE', '事实清单必须有同一来源的精确引文。', 422);
        }
        $identity = ['branch_id' => $branch, 'evidence_id' => $e->id, 'category' => $d['category'], 'statement' => $d['statement'], 'statement_hash' => hash('sha256', $d['statement'])];
        if ($old = DB::table('memory_facts')->where($identity)->first()) {
            if ($old->introduced_chapter !== (int) $d['introduced_chapter']) {
                throw new NovelProblem('FACT_CONFLICT', '既有事实章位不可重写。');
            }

            return ['fact' => $this->decode($old)];
        }
        $id = (string) DB::table('memory_facts')->insertGetId($identity + ['novel_id' => $novel, 'source_version_id' => $source->id, 'introduced_chapter' => $d['introduced_chapter'], 'actor_id' => $actor->id]);

        return ['fact' => $this->row('memory_facts', $novel, $branch, $id)];
    }

    private function save(User $actor, string $novel, string $branch, array $d): array
    {
        $rules = ['collection_id' => 'nullable|integer|min:1', 'kind' => ['required', Rule::in(self::KINDS)], 'name' => 'required|string|max:200', 'summary' => 'present|string|max:200000', 'introduced_chapter' => 'required|integer|min:1|max:100000', 'visibility' => 'required|array:mode,character_ids', 'visibility.mode' => ['required', Rule::in(['author_only', 'characters'])], 'visibility.character_ids' => 'present|array|max:200', 'visibility.character_ids.*' => 'required|integer|min:1|distinct', 'importance' => 'required|integer|min:1|max:5', 'retry_of_id' => 'nullable|integer|min:1', 'chunk_size' => 'sometimes|integer|min:32|max:4000'];
        foreach (['source_ids', 'fact_ids', 'parent_ids'] as $field) {
            $rules[$field] = 'present|array|max:200';
            $rules[$field.'.*'] = 'required|integer|min:1|max:9223372036854775807|distinct';
        }
        Validator::make($d, $rules)->validate();
        $summary = StoryStateService::normalize($d['summary']);
        $collection = isset($d['collection_id']) ? $this->row('memory_collections', $novel, $branch, $d['collection_id']) : null;
        if ($collection && ($collection['kind'] !== $d['kind'] || $collection['name'] !== $d['name'])) {
            throw new NovelProblem('IMMUTABLE_IDENTITY', '主题和类别属于稳定身份，请新建主题。', 422);
        }
        $parents = array_map('strval', $d['parent_ids']);
        // 新版本自动继承当前活动版本，禁止省略parent_ids绕过不可逆事实保留。
        if ($collection && ($active = $this->activeId($collection['id']))) {
            $parents[] = $active;
        }
        if (isset($d['retry_of_id'])) {
            $retry = $this->row('memory_items', $novel, $branch, $d['retry_of_id']);
            if (! $collection || $retry['collection_id'] !== $collection['id'] || $retry['report']['passed']) {
                throw new NovelProblem('INVALID_RETRY', '只能重试同主题的失败版本。', 422);
            }
            $parents[] = $retry['id'];
        }
        $parents = array_values(array_unique($parents));
        $sources = array_map('strval', $d['source_ids']);
        $facts = array_map('strval', $d['fact_ids']);
        $parentRows = [];
        $visibility = ['mode' => $d['visibility']['mode'], 'character_ids' => array_map('strval', $d['visibility']['character_ids'])];
        if (($visibility['mode'] === 'author_only') !== ($visibility['character_ids'] === [])) {
            throw new NovelProblem('INVALID_VISIBILITY', '仅作者范围须为空，指定角色范围须选择角色。', 422);
        }
        foreach ($visibility['character_ids'] as $id) {
            $e = DB::table('entities')->where('novel_id', $novel)->where('id', $id)->where('type', 'character')->first();
            if (! $e || ! $e->approved_version_id) {
                throw new NovelProblem('INVALID_VISIBILITY', '可见角色必须是同书已批准人物。', 422);
            }
            app(WorldService::class)->assertReference($actor, $novel, (string) $e->approved_version_id);
        }
        foreach ($parents as $id) {
            $parent = $this->row('memory_items', $novel, $branch, $id);
            $this->assertValid($novel, $branch, $parent);
            if ($d['introduced_chapter'] < $parent['introduced_chapter'] || ($visibility['mode'] === 'characters' && ($parent['visibility']['mode'] === 'author_only' || array_diff($visibility['character_ids'], $parent['visibility']['character_ids'])))) {
                throw new NovelProblem('VISIBILITY_WIDENED', '压缩不得提前章位或扩大角色可见范围。', 422);
            }
            $parentRows[] = $parent;
            $sources = array_merge($sources, $parent['payload']['source_ids']);
            $facts = array_merge($facts, $parent['payload']['fact_ids']);
        }
        $facts = array_values(array_unique($facts));
        $factRows = [];
        foreach ($facts as $id) {
            $f = $this->row('memory_facts', $novel, $branch, $id);
            $factRows[] = $f;
            $sources[] = $f['source_version_id'];
        }
        $sources = array_values(array_unique($sources));
        $sourceRows = [];
        foreach ($sources as $id) {
            $sourceRows[] = $this->source($novel, $branch, $id);
        }
        if (! $sources || ! $facts) {
            throw new NovelProblem('MISSING_FACT_INVENTORY', '摘要必须有来源与证据事实清单，不能把无依据文字激活。', 422);
        }
        $issues = [];
        foreach ($factRows as $f) {
            if (! str_contains($summary, $f['statement'])) {
                $issues[] = ['code' => 'FACT_OMITTED', 'fact_id' => $f['id'], 'category' => $f['category']];
            }
            if ($d['introduced_chapter'] < $f['introduced_chapter']) {
                $issues[] = ['code' => 'FUTURE_FACT', 'fact_id' => $f['id']];
            }
        }
        if (trim($summary) === '') {
            $issues[] = ['code' => 'EMPTY_SUMMARY'];
        }
        $report = ['schema' => 'memory-preservation-v1', 'passed' => $issues === [], 'issues' => $issues, 'semantic_completeness' => 'author_inventory_only', 'pov_usable' => false];
        $collectionId = $collection['id'] ?? (string) DB::table('memory_collections')->insertGetId(['novel_id' => $novel, 'branch_id' => $branch, 'kind' => $d['kind'], 'name' => $d['name'], 'actor_id' => $actor->id]);
        $p = ['source_ids' => $sources, 'source_hashes' => array_map(fn ($s) => ['id' => (string) $s->id, 'hash' => $s->source_hash], $sourceRows), 'fact_ids' => $facts, 'parent_ids' => $parents, 'parent_hashes' => array_map(fn ($p) => ['id' => $p['id'], 'hash' => $p['content_hash']], $parentRows), 'summary' => $summary, 'introduced_chapter' => (int) $d['introduced_chapter'], 'visibility' => $visibility, 'importance' => (int) $d['importance'], 'chunk_size' => (int) ($d['chunk_size'] ?? 1000), 'origin' => 'author_supplied'];
        $id = (string) DB::table('memory_items')->insertGetId(['novel_id' => $novel, 'branch_id' => $branch, 'collection_id' => $collectionId, 'version' => 1 + (int) DB::table('memory_items')->where('collection_id', $collectionId)->max('version'), 'scope' => 'candidate', 'status' => 'building', 'summary' => $summary, 'introduced_chapter' => $p['introduced_chapter'], 'visibility' => NovelIntentService::canonical($visibility), 'importance' => $p['importance'], 'payload' => NovelIntentService::canonical($p), 'content_hash' => $this->hash($p), 'report' => NovelIntentService::canonical($report), 'retry_of_id' => $d['retry_of_id'] ?? null, 'reason' => $d['reason'], 'actor_id' => $actor->id]);
        foreach ($sourceRows as $s) {
            DB::table('memory_sources')->insert(['novel_id' => $novel, 'branch_id' => $branch, 'memory_id' => $id, 'source_version_id' => $s->id, 'source_hash' => $s->source_hash]);
        }
        foreach ($facts as $f) {
            DB::table('memory_item_facts')->insert(['novel_id' => $novel, 'branch_id' => $branch, 'memory_id' => $id, 'fact_id' => $f]);
        }
        foreach ($parentRows as $parent) {
            DB::table('memory_parents')->insert(['novel_id' => $novel, 'branch_id' => $branch, 'memory_id' => $id, 'parent_memory_id' => $parent['id'], 'parent_hash' => $parent['content_hash']]);
        }
        // 码点分块可无损拼回摘要，不将中文字符数标作Token数。
        for ($start = 0, $n = 1, $length = mb_strlen($summary, 'UTF-8'); $start < $length; $start += $p['chunk_size'], $n++) {
            $end = min($length, $start + $p['chunk_size']);
            $text = mb_substr($summary, $start, $end - $start, 'UTF-8');
            DB::table('memory_chunks')->insert(['novel_id' => $novel, 'memory_id' => $id, 'chunk_no' => $n, 'start_offset' => $start, 'end_offset' => $end, 'text' => $text, 'content_hash' => hash('sha256', $text)]);
        }
        DB::table('memory_items')->where('id', $id)->update(['status' => 'sealed']);

        return ['memory' => $this->decorate($novel, $branch, $this->row('memory_items', $novel, $branch, $id))];
    }

    private function activeId(string $collection): ?string
    {
        $id = DB::table('memory_activations as a')->join('memory_items as m', 'm.id', '=', 'a.memory_id')->where('m.collection_id', $collection)->orderByDesc('a.id')->value('a.memory_id');

        return $id === null ? null : (string) $id;
    }

    // 递归核验完整父链；停用父记忆会使所有派生版本即时过期，无需等待索引。
    private function assertValid(string $novel, string $branch, array $item, array $seen = []): void
    {
        if (isset($seen[$item['id']])) {
            throw new NovelProblem('MEMORY_CYCLE', '记忆父链异常。');
        }
        $seen[$item['id']] = true;
        if ($item['status'] !== 'sealed' || $this->hash($item['payload']) !== $item['content_hash'] || DB::table('memory_invalidations')->where('memory_id', $item['id'])->exists()) {
            throw new NovelProblem('STALE_MEMORY', '记忆已停用或哈希不一致。');
        }
        foreach ($item['payload']['source_hashes'] as $ref) {
            if ($this->source($novel, $branch, $ref['id'])->source_hash !== $ref['hash']) {
                throw new NovelProblem('STALE_SOURCE', '来源版本已失效。');
            }
        }
        foreach ($item['payload']['parent_hashes'] as $ref) {
            $parent = $this->row('memory_items', $novel, $branch, $ref['id']);
            if ($parent['content_hash'] !== $ref['hash']) {
                throw new NovelProblem('STALE_MEMORY', '父摘要版本哈希不一致。');
            }
            $this->assertValid($novel, $branch, $parent, $seen);
        }
    }

    private function activate(User $actor, string $novel, string $branch, array $d): array
    {
        $item = $this->row('memory_items', $novel, $branch, $d['memory_id'] ?? null);
        $this->assertValid($novel, $branch, $item);
        if (! $item['report']['passed']) {
            throw new NovelProblem('MEMORY_VALIDATION_FAILED', '压缩遗漏事实或章位错误，旧活动版本保持可用。');
        }
        if ($item['version'] !== (int) DB::table('memory_items')->where('collection_id', $item['collection_id'])->max('version')) {
            throw new NovelProblem('STALE_MEMORY_VERSION', '只允许激活最新摘要候选。');
        }
        if (DB::table('memory_activations')->where('memory_id', $item['id'])->exists()) {
            throw new NovelProblem('ALREADY_ACTIVE', '此候选已经激活过。');
        }
        $id = DB::table('memory_activations')->insertGetId(['novel_id' => $novel, 'branch_id' => $branch, 'memory_id' => $item['id'], 'reason' => $d['reason'], 'actor_id' => $actor->id]);

        return ['activation_id' => (string) $id, 'memory' => $this->decorate($novel, $branch, $item)];
    }

    private function invalidate(User $actor, string $novel, string $branch, array $d): array
    {
        Validator::make($d, ['source_version_id' => 'nullable|integer|min:1', 'memory_id' => 'nullable|integer|min:1'])->validate();
        if (isset($d['source_version_id']) === isset($d['memory_id'])) {
            throw new NovelProblem('INVALID_TARGET', '每次只停用一个来源或派生记忆。', 422);
        }
        if (isset($d['source_version_id'])) {
            $this->source($novel, $branch, $d['source_version_id']);
            $target = ['source_version_id' => $d['source_version_id']];
        } else {
            $this->row('memory_items', $novel, $branch, $d['memory_id']);
            $target = ['memory_id' => $d['memory_id']];
        }
        if (DB::table('memory_invalidations')->where($target)->exists()) {
            throw new NovelProblem('ALREADY_INVALIDATED', '此来源或记忆已经停用。');
        }
        $id = DB::table('memory_invalidations')->insertGetId($target + ['novel_id' => $novel, 'branch_id' => $branch, 'reason' => $d['reason'], 'actor_id' => $actor->id]);

        return ['invalidation_id' => (string) $id, 'affected' => $this->listing($actor, $novel, ['scope' => 'candidate', 'branch_id' => $branch, 'status' => 'stale'])['items']];
    }

    private function decorate(string $novel, string $branch, array $item): array
    {
        $problem = null;
        try {
            $this->assertValid($novel, $branch, $item);
        } catch (NovelProblem $e) {
            $problem = ['code' => $e->errorCode, 'message' => $e->getMessage()];
        }
        $active = $this->activeId($item['collection_id']) === $item['id'];
        $item['content_status'] = $problem ? 'stale' : (! $item['report']['passed'] ? 'failed' : ($active ? 'active' : 'pending'));
        $item['source_issue'] = $problem;
        $collection = $this->row('memory_collections', $novel, $branch, $item['collection_id']);
        $item['kind'] = $collection['kind'];
        $item['name'] = $collection['name'];
        $item['formal_usable'] = false;
        $item['pov_usable'] = false;

        return $item;
    }

    // 正式读取必须显式指定基线；当前未实现正式来源时拒绝，绝不回退读取草稿。
    private function readScope(User $actor, string $novel, array $filters): string
    {
        app(NovelIntentService::class)->novel($actor, $novel, true);
        Validator::make($filters, ['scope' => ['required', Rule::in(['candidate', 'formal'])]])->validate();
        if ($filters['scope'] === 'formal') {
            throw new NovelProblem('FORMAL_MEMORY_UNAVAILABLE', '正式基线记忆等待 E07 接入，不会回退到候选分支。');
        }
        Validator::make($filters, ['branch_id' => 'required|integer|min:1|max:9223372036854775807', 'revision_id' => 'prohibited'])->validate();
        $this->branch($novel, $filters['branch_id']);

        return (string) $filters['branch_id'];
    }

    public function listing(User $actor, string $novel, array $filters): array
    {
        // 与停用命令共用书行锁，活动读取得到一致的来源有效性快照。
        return DB::transaction(fn () => $this->listingLocked($actor, $novel, $filters));
    }

    private function listingLocked(User $actor, string $novel, array $filters): array
    {
        $branch = $this->readScope($actor, $novel, $filters);
        $v = Validator::make($filters, ['kind' => ['sometimes', Rule::in(self::KINDS)], 'status' => ['sometimes', Rule::in(['pending', 'active', 'stale', 'failed'])], 'index_status' => ['sometimes', Rule::in(['not_requested', 'failed'])], 'chapter' => 'sometimes|integer|min:1|max:100000', 'character_id' => 'sometimes|integer|min:1', 'page' => 'sometimes|integer|min:1|max:100000'])->validate();
        $rows = DB::table('memory_items')->where('novel_id', $novel)->where('branch_id', $branch)->where('status', 'sealed')->orderByDesc('id')->get();
        $items = [];
        foreach ($rows as $row) {
            $item = $this->decorate($novel, $branch, $this->decode($row));
            foreach (['kind' => 'kind', 'status' => 'content_status', 'index_status' => 'index_status'] as $input => $field) {
                if (isset($v[$input]) && $v[$input] !== $item[$field]) {
                    continue 2;
                }
            }
            if (isset($v['chapter']) && $item['introduced_chapter'] > $v['chapter']) {
                continue;
            }
            if (isset($v['character_id']) && ! in_array((string) $v['character_id'], $item['visibility']['character_ids'], true)) {
                continue;
            }
            $items[] = $item;
        }
        $page = (int) ($v['page'] ?? 1);

        return ['scope' => 'candidate', 'branch_id' => $branch, 'formal_usable' => false, 'items' => array_slice($items, ($page - 1) * 50, 50), 'page' => $page, 'total' => count($items), 'next_page' => count($items) > $page * 50 ? $page + 1 : null];
    }

    public function read(User $actor, string $novel, string $id, array $filters): array
    {
        return DB::transaction(fn () => $this->readLocked($actor, $novel, $id, $filters));
    }

    private function readLocked(User $actor, string $novel, string $id, array $filters): array
    {
        $branch = $this->readScope($actor, $novel, $filters);
        $item = $this->decorate($novel, $branch, $this->row('memory_items', $novel, $branch, $id));
        $item['facts'] = array_map(fn ($id) => $this->row('memory_facts', $novel, $branch, $id), $item['payload']['fact_ids']);
        $item['sources'] = DB::table('source_documents')->where('novel_id', $novel)->where('run_id', $branch)->whereIn('id', $item['payload']['source_ids'])->get()->map(fn ($r) => $this->decode($r))->all();
        $item['evidence'] = DB::table('evidence_spans')->where('novel_id', $novel)->whereIn('id', array_column($item['facts'], 'evidence_id'))->get()->map(fn ($r) => $this->decode($r))->all();
        $item['chunks'] = DB::table('memory_chunks')->where('memory_id', $id)->orderBy('chunk_no')->get()->map(fn ($r) => $this->decode($r))->all();
        $item['parents'] = array_map(fn ($id) => $this->decorate($novel, $branch, $this->row('memory_items', $novel, $branch, $id)), $item['payload']['parent_ids']);
        $item['invalidations'] = DB::table('memory_invalidations')->where('novel_id', $novel)->where('branch_id', $branch)->get()->map(fn ($r) => $this->decode($r))->all();

        return $item;
    }
}
