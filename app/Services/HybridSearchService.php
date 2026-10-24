<?php

namespace App\Services;

use App\Exceptions\NovelProblem;
use App\Models\User;
use App\Search\OfflineEmbedding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** 精确、别名、三元组和pgvector融合；权限过滤先于任何排名。 */
final class HybridSearchService
{
    public function execute(User $actor, string $novel, string $command, array $data, string $key): array
    {
        app(NovelIntentService::class)->authorize($actor);
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D']])->validate();

        return DB::transaction(function () use ($actor, $novel, $command, $data, $key) {
            $book = app(NovelIntentService::class)->novel($actor, $novel, true);
            $identity = ['actor_id' => $actor->id, 'novel_scope' => $novel, 'command' => 'search.'.$command, 'key' => $key];
            $hash = hash('sha256', NovelIntentService::canonical($data));
            if ($prior = DB::table('novel_commands')->where($identity)->first()) {
                if ($prior->request_hash !== $hash) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '操作键已用于不同检索配置。');
                }

                return json_decode($prior->response, true, flags: JSON_THROW_ON_ERROR);
            }
            $fields = match ($command) {
                'profile' => ['name', 'encoder', 'model_profile_version_id', 'dimensions', 'distance_metric'],'build' => ['branch_id', 'profile_id'],'document' => ['build_id', 'source_version_id', 'evidence_id', 'event_id', 'memory_id', 'chunk_id', 'title', 'aliases', 'introduced_chapter'],'validate','activate' => ['build_id'],default => throw new NovelProblem('INVALID_ACTION', '索引操作不存在。', 422)
            };
            Validator::make(['d' => $data], ['d' => 'array:'.implode(',', array_merge($fields, ['expected_version', 'reason']))])->validate();
            Validator::make($data, ['expected_version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000'])->validate();
            if ((int) $data['expected_version'] !== $book->row_version) {
                throw new NovelProblem('STALE_STATE', '小说或索引状态已变化，请刷新。');
            }
            // 把本次理由绑定到索引切换审计，不沿用最初构建的理由。
            DB::select("SELECT set_config('novelforge.reason', ?, true)", [$data['reason']]);
            $r = match ($command) {
                'profile' => $this->profile($actor, $data),'build' => $this->build($actor, $novel, $data),'document' => $this->document($actor, $novel, $data),'validate' => $this->validateBuild($actor, $novel, $data),'activate' => $this->activate($actor, $novel, $data)
            };
            $book->row_version++;
            $book->save();
            $r['row_version'] = $book->row_version;
            DB::table('novel_commands')->insert($identity + ['request_hash' => $hash, 'response' => NovelIntentService::canonical($r), 'created_at' => now()]);

            return $r;
        });
    }

    private function profile(User $actor, array $d): array
    {
        Validator::make($d, ['name' => 'required|string|max:150', 'encoder' => ['required', Rule::in(['offline_hash_v1', 'external_disabled'])], 'model_profile_version_id' => 'nullable|integer|min:1|exists:model_profile_versions,id', 'dimensions' => 'required|integer|min:2|max:4096', 'distance_metric' => ['required', Rule::in(['cosine', 'l2'])]])->validate();
        if (($d['encoder'] === 'offline_hash_v1') !== empty($d['model_profile_version_id'])) {
            throw new NovelProblem('INVALID_PROFILE', '离线哈希不绑定真实模型；外部档案必须绑定具体模型版本。', 422);
        }
        DB::select("SELECT pg_advisory_xact_lock(hashtext('d05-profiles'))");
        $p = ['name' => $d['name'], 'encoder' => $d['encoder'], 'model_profile_version_id' => isset($d['model_profile_version_id']) ? (string) $d['model_profile_version_id'] : null, 'dimensions' => (int) $d['dimensions'], 'distance_metric' => $d['distance_metric']];
        $id = DB::table('embedding_profiles')->insertGetId($p + ['version' => 1 + (int) DB::table('embedding_profiles')->where('name', $d['name'])->max('version'), 'content_hash' => hash('sha256', NovelIntentService::canonical($p)), 'actor_id' => $actor->id, 'reason' => $d['reason']]);

        return ['profile_id' => (string) $id];
    }

    private function build(User $actor, string $novel, array $d): array
    {
        Validator::make($d, ['branch_id' => 'required|integer|min:1', 'profile_id' => 'required|integer|min:1|exists:embedding_profiles,id'])->validate();
        if (! DB::table('generation_runs')->where('novel_id', $novel)->where('id', $d['branch_id'])->exists()) {
            throw new NovelProblem('NOT_FOUND', '候选分支不存在。', 404);
        }
        $id = DB::table('index_builds')->insertGetId(['novel_id' => $novel, 'branch_id' => $d['branch_id'], 'profile_id' => $d['profile_id'], 'scope' => 'candidate', 'generation' => 1 + (int) DB::table('index_builds')->where('novel_id', $novel)->where('branch_id', $d['branch_id'])->max('generation'), 'status' => 'building', 'actor_id' => $actor->id, 'reason' => $d['reason']]);

        return ['build_id' => (string) $id];
    }

    private function index(string $novel, mixed $id): object
    {
        Validator::make(['id' => $id], ['id' => 'required|integer|min:1'])->validate();

        return DB::table('index_builds')->where('novel_id', $novel)->where('id', $id)->first() ?? throw new NovelProblem('NOT_FOUND', '索引不属于当前小说。', 404);
    }

    private function document(User $actor, string $novel, array $d): array
    {
        $b = $this->index($novel, $d['build_id'] ?? null);
        if ($b->status !== 'building') {
            throw new NovelProblem('INDEX_SEALED', '只能向构建中的代次追加文档。');
        }
        $p = DB::table('embedding_profiles')->find($b->profile_id);
        if ($p->encoder !== 'offline_hash_v1') {
            throw new NovelProblem('EMBEDDING_DISABLED', '真实嵌入调用未授权，未发起请求或产生费用。');
        }
        Validator::make($d, ['source_version_id' => 'required|integer|min:1', 'evidence_id' => 'nullable|integer|min:1', 'event_id' => 'nullable|integer|min:1', 'memory_id' => 'nullable|integer|min:1', 'chunk_id' => 'nullable|integer|min:1', 'title' => 'required|string|max:200', 'aliases' => 'present|array|max:100', 'aliases.*' => 'required|string|max:200|distinct', 'introduced_chapter' => 'required|integer|min:1|max:100000'])->validate();
        $source = app(LayeredMemoryService::class)->verifiedSource($actor, $novel, (string) $b->branch_id, (string) $d['source_version_id']);
        $memory = null;
        $event = null;
        $evidence = null;
        $chunk = null;
        if (isset($d['memory_id'])) {
            if (isset($d['event_id']) || isset($d['evidence_id'])) {
                throw new NovelProblem('INVALID_DOCUMENT', '记忆片段不可以声明事件知识资格。', 422);
            }
            $memory = app(LayeredMemoryService::class)->read($actor, $novel, (string) $d['memory_id'], ['scope' => 'candidate', 'branch_id' => (string) $b->branch_id]);
            $chunk = collect($memory['chunks'])->firstWhere('id', (string) ($d['chunk_id'] ?? ''));
            if (! $chunk || $memory['content_status'] !== 'active' || ! in_array((string) $source['id'], $memory['payload']['source_ids'], true) || (int) $d['introduced_chapter'] < $memory['introduced_chapter']) {
                throw new NovelProblem('INVALID_MEMORY', '记忆必须活动、分块匹配且来源完整。', 422);
            }
            $body = $chunk['text'];
        } else {
            if (isset($d['chunk_id'])) {
                throw new NovelProblem('INVALID_DOCUMENT', '分块必须指定记忆版本。', 422);
            }
            $evidence = DB::table('evidence_spans')->where('novel_id', $novel)->where('source_version_id', $source['id'])->where('id', $d['evidence_id'] ?? null)->first();
            if (! $evidence) {
                throw new NovelProblem('INVALID_EVIDENCE', '必须引用同源精确证据。', 422);
            }
            $body = $evidence->quote;
            if (isset($d['event_id'])) {
                $event = DB::table('proposed_events')->where('novel_id', $novel)->where('evidence_id', $evidence->id)->where('id', $d['event_id'])->first();
                if (! $event || json_decode($event->value, true) !== $body || $event->chapter_sequence !== (int) $d['introduced_chapter']) {
                    throw new NovelProblem('INVALID_DISCLOSURE', '事件正文必须等于其已知事实值，且章位不可提前。', 422);
                }
                foreach ([$d['title'], ...$d['aliases']] as $label) {
                    if (! str_contains($body, $label)) {
                        throw new NovelProblem('INVALID_DISCLOSURE', '可用于POV的标题和别名必须包含于该事实原文。', 422);
                    }
                }
            }
        }
        if (OfflineEmbedding::normalize($d['title']) === '') {
            throw new NovelProblem('INVALID_TITLE', '标题不能只有标点。', 422);
        }
        $norm = OfflineEmbedding::normalize($d['title'].' '.implode(' ', $d['aliases']).' '.$body);
        $payload = ['source_version_id' => (string) $source['id'], 'evidence_id' => $evidence ? (string) $evidence->id : null, 'event_id' => $event ? (string) $event->id : null, 'memory_id' => $memory['id'] ?? null, 'chunk_id' => $chunk['id'] ?? null, 'source_hash' => $source['source_hash'], 'body' => $body, 'title' => $d['title'], 'aliases' => $d['aliases'], 'normalized_text' => $norm, 'introduced_chapter' => (int) $d['introduced_chapter']];
        $hash = hash('sha256', NovelIntentService::canonical($payload));
        if ($old = DB::table('search_documents')->where('index_build_id', $b->id)->where('content_hash', $hash)->first()) {
            return ['document_id' => (string) $old->id];
        }
        $row = $payload;
        $row['aliases'] = NovelIntentService::canonical($row['aliases']);
        $id = DB::table('search_documents')->insertGetId($row + ['novel_id' => $novel, 'branch_id' => $b->branch_id, 'index_build_id' => $b->id, 'content_hash' => $hash]);
        DB::table('memory_embeddings')->insert(['index_build_id' => $b->id, 'profile_id' => $p->id, 'document_id' => $id, 'input_hash' => hash('sha256', $norm), 'embedding' => app(OfflineEmbedding::class)->encode($norm, $p->dimensions), 'origin' => 'offline_hash_v1']);

        return ['document_id' => (string) $id];
    }

    private function valid(User $actor, string $novel, object $b, object $doc): bool
    {
        try {
            $s = app(LayeredMemoryService::class)->verifiedSource($actor, $novel, (string) $b->branch_id, (string) $doc->source_version_id);
            if ($s['source_hash'] !== $doc->source_hash) {
                return false;
            }
            if ($doc->memory_id) {
                $m = app(LayeredMemoryService::class)->read($actor, $novel, (string) $doc->memory_id, ['scope' => 'candidate', 'branch_id' => (string) $b->branch_id]);
                if ($m['content_status'] !== 'active') {
                    return false;
                }
            }

            return true;
        } catch (NovelProblem) {
            return false;
        }
    }

    private function manifest(object $b): string
    {
        $docs = DB::table('search_documents')->where('index_build_id', $b->id)->orderBy('id')->get(['id', 'content_hash'])->map(fn ($d) => [(string) $d->id, $d->content_hash])->all();

        return hash('sha256', NovelIntentService::canonical(['profile_id' => (string) $b->profile_id, 'documents' => $docs]));
    }

    private function validateBuild(User $actor, string $novel, array $d): array
    {
        $b = $this->index($novel, $d['build_id'] ?? null);
        if ($b->status !== 'building') {
            throw new NovelProblem('INDEX_SEALED', '代次已经验证封存。');
        }
        $docs = DB::table('search_documents')->where('index_build_id', $b->id)->get();
        if ($docs->isEmpty() || $docs->count() !== DB::table('memory_embeddings')->where('index_build_id', $b->id)->count()) {
            throw new NovelProblem('INDEX_INCOMPLETE', '索引为空或嵌入缺失。');
        }
        foreach ($docs as $doc) {
            if (! $this->valid($actor, $novel, $b, $doc)) {
                throw new NovelProblem('STALE_SOURCE', '索引包含失效来源，请新建代次。');
            }
        }
        $hash = $this->manifest($b);
        DB::table('index_builds')->where('id', $b->id)->update(['status' => 'validated', 'manifest_hash' => $hash, 'metrics' => json_encode(['documents' => $docs->count(), 'strategy' => 'exact', 'quality_evaluated' => false])]);

        return ['build_id' => (string) $b->id, 'manifest_hash' => $hash, 'status' => 'validated'];
    }

    private function activate(User $actor, string $novel, array $d): array
    {
        $b = $this->index($novel, $d['build_id'] ?? null);
        if (! in_array($b->status, ['validated', 'retired'], true) || $b->manifest_hash !== $this->manifest($b)) {
            throw new NovelProblem('INDEX_NOT_VALIDATED', '代次未验证或清单不匹配。');
        }
        foreach (DB::table('search_documents')->where('index_build_id', $b->id)->get() as $doc) {
            if (! $this->valid($actor, $novel, $b, $doc)) {
                throw new NovelProblem('STALE_SOURCE', '切换前发现失效来源，不能激活。');
            }
        }
        DB::table('index_builds')->where('novel_id', $novel)->where('branch_id', $b->branch_id)->where('status', 'active')->update(['status' => 'retired']);
        DB::table('index_builds')->where('id', $b->id)->update(['status' => 'active']);

        return ['build_id' => (string) $b->id, 'profile_id' => (string) $b->profile_id, 'status' => 'active'];
    }

    public function listing(User $actor, string $novel): array
    {
        $book = app(NovelIntentService::class)->novel($actor, $novel);

        return ['row_version' => $book->row_version, 'profiles' => DB::table('embedding_profiles')->orderByDesc('id')->get()->map(fn ($r) => $this->decode($r))->all(), 'evaluations' => DB::table('search_evaluations')->where('novel_id', $novel)->orderByDesc('id')->limit(20)->get(['id', 'index_build_id', 'manifest_hash', DB::raw("report->>'query_count' AS query_count"), DB::raw("report->>'recall_at_20' AS recall_at_20"), DB::raw("report->>'leak_count' AS leak_count")])->map(fn ($r) => $this->decode($r))->all(), 'builds' => DB::table('index_builds')->where('novel_id', $novel)->orderByDesc('id')->get()->map(fn ($r) => $this->decode($r))->all()];
    }

    /** 完整评测报告按书鉴权读取，包含来源与索引清单，可用于重现。 */
    public function evaluation(User $actor, string $novel, string $id): array
    {
        app(NovelIntentService::class)->novel($actor, $novel);
        Validator::make(['id' => $id], ['id' => 'required|integer|min:1'])->validate();
        $row = DB::table('search_evaluations')->where('novel_id', $novel)->where('id', $id)->first() ?? throw new NovelProblem('NOT_FOUND', '评测报告不存在。', 404);
        $result = $this->decode($row);
        $result['report'] = json_decode($row->report, true, flags: JSON_THROW_ON_ERROR);

        return $result;
    }

    private function decode(object $r): array
    {
        $a = (array) $r;
        foreach ($a as $key => &$value) {
            if ($value !== null && ($key === 'id' || str_ends_with($key, '_id'))) {
                $value = (string) $value;
            }if (in_array($key, ['metrics', 'aliases'], true)) {
                $value = json_decode($value, true);
            }
        }

        return $a;
    }

    public function search(User $actor, string $novel, array $d): array
    {
        return DB::transaction(function () use ($actor, $novel, $d) {
            app(NovelIntentService::class)->novel($actor, $novel, true);
            Validator::make($d, ['scope' => ['required', Rule::in(['candidate', 'formal'])]])->validate();
            if ($d['scope'] === 'formal') {
                throw new NovelProblem('FORMAL_SEARCH_UNAVAILABLE', '正式来源与基线未接入，不能回退到候选检索。');
            }
            Validator::make($d, ['branch_id' => 'required|integer|min:1', 'build_id' => 'required|integer|min:1', 'profile_id' => 'required|integer|min:1', 'query' => 'required|string|max:500', 'chapter' => 'required|integer|min:1|max:100000', 'audience' => ['required', Rule::in(['author', 'pov'])], 'pov_id' => 'required_if:audience,pov|integer|min:1', 'context_id' => 'required_if:audience,pov|integer|min:1', 'limit' => 'sometimes|integer|min:1|max:100', 'revision_id' => 'prohibited', 'vector' => 'sometimes|boolean'])->validate();
            $b = $this->index($novel, $d['build_id']);
            if ((string) $b->branch_id !== (string) $d['branch_id'] || $b->status !== 'active' || (string) $b->profile_id !== (string) $d['profile_id']) {
                throw new NovelProblem('INDEX_PROFILE_MISMATCH', '查询分支、活动代次与嵌入档案必须一致。');
            }
            $p = DB::table('embedding_profiles')->find($b->profile_id);
            $q = OfflineEmbedding::normalize($d['query']);
            if ($q === '') {
                throw new NovelProblem('EMPTY_QUERY', '查询不能只有标点。', 422);
            }
            $known = null;
            if ($d['audience'] === 'pov') {
                if (! DB::table('entities')->where('novel_id', $novel)->where('id', $d['pov_id'])->where('type', 'character')->exists()) {
                    throw new NovelProblem('INVALID_KNOWER', 'POV必须是当前小说角色。', 422);
                }
                $context = app(StoryConsistencyService::class)->read($actor, $novel, (string) $d['context_id']);
                $events = array_column($context['events'], null, 'id');
                $sourceIds = array_map(fn ($e) => $e['evidence']['source_version_id'], $events);
                $validKnowledgeSources = app(LayeredMemoryService::class)->verifiedSources($actor, $novel, (string) $b->branch_id, $sourceIds);
                // 在D02重演知识传递之前移除无效获知证据，避免上游已失效却仍可转述。
                $payload = $context['payload'];
                $payload['knowledge'] = array_values(array_filter($payload['knowledge'], function ($k) use ($events, $validKnowledgeSources) {
                    return isset($validKnowledgeSources[$events[$k['fact_event_id']]['evidence']['source_version_id']], $validKnowledgeSources[$events[$k['acquired_event_id']]['evidence']['source_version_id']]);
                }));
                $pov = app(StoryConsistencyValidator::class)->pov($payload, $events, (string) $d['pov_id'], (int) $d['chapter']);
                $known = $pov['usable'] ? array_column(array_filter($pov['knowledge'], fn ($k) => in_array($k['knowledge_kind'], ['fact', 'hidden_truth'], true)), 'fact_event_id') : [];
            }
            $base = DB::table('search_documents')->where('novel_id', $novel)->where('branch_id', $b->branch_id)->where('index_build_id', $b->id)->where('introduced_chapter', '<=', $d['chapter']);
            if ($known !== null) {
                $base->whereIn('event_id', $known)->whereNull('memory_id');
            }
            if ((clone $base)->count() > 10000) {
                throw new NovelProblem('SEARCH_SCOPE_TOO_LARGE', '当前精确搜索范围超过一万条，请缩小章位范围。');
            }
            $candidates = $base->orderBy('id')->get();
            $validSources = app(LayeredMemoryService::class)->verifiedSources($actor, $novel, (string) $b->branch_id, $candidates->pluck('source_version_id')->all());
            $docs = $candidates->filter(function ($doc) use ($validSources, $actor, $novel, $b) {
                if (! isset($validSources[$doc->source_version_id]) || $validSources[$doc->source_version_id]['source_hash'] !== $doc->source_hash) {
                    return false;
                }

                return ! $doc->memory_id || $this->valid($actor, $novel, $b, $doc);
            })->keyBy('id');
            $ids = $docs->keys()->all();
            $channels = ['exact' => [], 'alias' => [], 'trgm' => [], 'vector' => []];
            $limit = (int) ($d['limit'] ?? 20);
            $take = count($ids);
            // 短词使用已过滤集合内的精确子串；不放宽权限补足候选。
            foreach ($docs as $doc) {
                $title = OfflineEmbedding::normalize($doc->title);
                $body = OfflineEmbedding::normalize($doc->body);
                $score = $title === $q ? 100 : (str_contains($body, $q) ? 60 : (str_contains($q, $title) ? 40 : 0));
                if ($score) {
                    $channels['exact'][] = ['id' => (string) $doc->id, 'score' => $score];
                }
                foreach (json_decode($doc->aliases, true) as $alias) {
                    $a = OfflineEmbedding::normalize($alias);
                    if ($a === $q || ($a !== '' && str_contains($q, $a))) {
                        $channels['alias'][] = ['id' => (string) $doc->id, 'score' => $a === $q ? 100 : 50];
                        break;
                    }
                }
            }
            foreach (['exact', 'alias'] as $c) {
                usort($channels[$c], fn ($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['id'], $b['id']));
                $channels[$c] = array_slice($channels[$c], 0, $take);
            }
            if ($ids) {
                // pg_trgm只作字符模糊召回；不声明执行过中文分词或全文检索。
                DB::statement('SET LOCAL pg_trgm.similarity_threshold = 0.05');
                $rows = DB::table('search_documents')->whereIn('id', $ids)->whereRaw('normalized_text % ?', [$q])->selectRaw('id, greatest(similarity(normalized_text, ?),word_similarity(?,normalized_text)) AS score', [$q, $q])->orderByDesc('score')->orderBy('id')->limit($take)->get();
                $channels['trgm'] = $rows->map(fn ($r) => ['id' => (string) $r->id, 'score' => (float) $r->score])->all();
                if ($d['vector'] ?? true) {
                    $operator = $p->distance_metric === 'cosine' ? '<=>' : '<->';
                    $vector = app(OfflineEmbedding::class)->encode($q, $p->dimensions);
                    $rows = DB::table('memory_embeddings')->where('index_build_id', $b->id)->where('profile_id', $p->id)->whereIn('document_id', $ids)->selectRaw("document_id AS id, embedding $operator ?::vector AS distance", [$vector])->orderBy('distance')->orderBy('document_id')->limit($take)->get();
                    $channels['vector'] = $rows->map(fn ($r) => ['id' => (string) $r->id, 'score' => (float) $r->distance])->all();
                }
            }
            $ranked = [];
            foreach ($channels as $channel => $rows) {
                foreach ($rows as $rank => $row) {
                    $id = $row['id'];
                    $ranked[$id] ??= ['id' => $id, 'rrf' => 0.0, 'contributions' => []];
                    $ranked[$id]['rrf'] += 1 / (60 + $rank + 1);
                    $ranked[$id]['contributions'][$channel] = ['rank' => $rank + 1, 'raw_score' => $row['score']];
                }
            }
            usort($ranked, fn ($a, $b) => $b['rrf'] <=> $a['rrf'] ?: strcmp($a['id'], $b['id']));
            $results = [];
            $seen = [];
            foreach ($ranked as $hit) {
                $doc = $docs[$hit['id']];
                $source = (string) $doc->source_version_id;
                if (isset($seen[$source])) {
                    continue;
                }
                $seen[$source] = true;
                $results[] = ['document_id' => $hit['id'], 'source_version_id' => $source, 'source_hash' => $doc->source_hash, 'memory_id' => $doc->memory_id === null ? null : (string) $doc->memory_id, 'event_id' => $doc->event_id === null ? null : (string) $doc->event_id, 'evidence_id' => $doc->evidence_id === null ? null : (string) $doc->evidence_id, 'chunk_id' => $doc->chunk_id === null ? null : (string) $doc->chunk_id, 'title' => $doc->title, 'body' => $doc->body, 'introduced_chapter' => $doc->introduced_chapter, 'score' => $hit['rrf'], 'contributions' => $hit['contributions']];
                if (count($results) >= $limit) {
                    break;
                }
            }

            return ['scope' => 'candidate', 'canonical' => false, 'build_id' => (string) $b->id, 'profile_id' => (string) $p->id, 'generation' => $b->generation, 'encoder' => $p->encoder, 'strategy' => 'exact_filtered', 'degraded' => ! ($d['vector'] ?? true), 'degradation_reason' => ! ($d['vector'] ?? true) ? 'VECTOR_DISABLED_EXPLICITLY' : null, 'eligible_documents' => count($ids), 'channel_counts' => array_map('count', $channels), 'results' => $results];
        });
    }
}
