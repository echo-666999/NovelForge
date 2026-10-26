<?php

namespace App\Services;

use App\Exceptions\NovelProblem;
use App\Jobs\ConsumeCanonicalMemory;
use App\Models\User;
use App\Search\OfflineEmbedding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** E07派生数据只读取真实正式前缀；本地保守摘要与字符编码不调用模型。 */
final class CanonicalMemoryService
{
    private function hash(mixed $value): string
    {
        return hash('sha256', NovelIntentService::canonical($value));
    }

    /** 意图不删不改，定时扫描补齐任务；重复通知由租约和唯一提交约束去重。 */
    public function discover(int $limit = 100): array
    {
        $limit = max(1, min(1000, $limit));
        $found = 0;
        foreach (DB::table('canonical_outbox as o')->leftJoin('canonical_memory_tasks as t', 't.outbox_id', '=', 'o.id')->whereNull('t.id')->orderBy('o.id')->limit($limit)->select('o.*')->get() as $intent) {
            DB::transaction(function () use ($intent, &$found) {
                DB::table('novels')->where('id', $intent->novel_id)->lockForUpdate()->first();
                $commit = DB::table('canonical_commits')->where('novel_id', $intent->novel_id)->where('revision_id', $intent->revision_id)->where('id', $intent->commit_id)->where('status', 'committed')->first();
                if (! $commit || $commit->response_hash !== $intent->payload_hash || $this->hash(json_decode($intent->payload, true)) !== $intent->payload_hash) {
                    throw new NovelProblem('INVALID_OUTBOX_HASH', '提交意图与不可变回执不一致。');
                }
                $profile = ['encoder' => 'offline_hash_v1', 'dimensions' => 64, 'distance_metric' => 'cosine', 'pipeline' => 'canonical-memory-v1', 'semantic_model' => false];
                $found += DB::table('canonical_memory_tasks')->insertOrIgnore(['novel_id' => $intent->novel_id, 'revision_id' => $intent->revision_id, 'commit_id' => $intent->commit_id, 'outbox_id' => $intent->id, 'profile' => NovelIntentService::canonical($profile), 'profile_hash' => $this->hash($profile)]);
            });
        }

        return ['created' => $found];
    }

    /** 通知投递与执行次数分开；队列故障保留意图，限速补投不会阻碍立即认领。 */
    public function publish(int $limit = 100): array
    {
        $r = $this->discover($limit);
        $sent = 0;
        $failed = 0;
        foreach (DB::table('canonical_memory_tasks')->where('status', 'running')->where('attempts', '>=', 3)->whereRaw('lease_until<=clock_timestamp()')->pluck('id') as $id) {
            $this->claim((string) $id);
        }
        $rows = DB::table('canonical_memory_tasks')->where(function ($q) {
            $q->where('status', 'queued')->orWhere(function ($q) {
                $q->where('status', 'running')->whereRaw('lease_until<=clock_timestamp()');
            });
        })->where('attempts', '<', 3)->whereRaw('available_at<=clock_timestamp()')->whereRaw('next_delivery_at<=clock_timestamp()')->orderBy('id')->limit(max(1, min(1000, $limit)))->get();
        foreach ($rows as $row) {
            $reserved = DB::transaction(function () use ($row) {
                DB::table('novels')->where('id', $row->novel_id)->lockForUpdate()->first();
                $task = DB::table('canonical_memory_tasks')->where('id', $row->id)->lockForUpdate()->first();
                $due = DB::table('canonical_memory_tasks')->where('id', $row->id)->whereRaw('next_delivery_at<=clock_timestamp()')->whereRaw('available_at<=clock_timestamp()')->exists();
                if (! $due || ! in_array($task->status, ['queued', 'running'], true) || ($task->status === 'running' && DB::table('canonical_memory_tasks')->where('id', $task->id)->whereRaw('lease_until>clock_timestamp()')->exists())) {
                    return false;
                }
                DB::table('canonical_memory_tasks')->where('id', $task->id)->update(['delivery_count' => $task->delivery_count + 1, 'next_delivery_at' => DB::raw("clock_timestamp()+interval '60 seconds'")]);

                return true;
            });
            if (! $reserved) {
                continue;
            }
            try {
                Queue::connection('redis')->push(new ConsumeCanonicalMemory((string) $row->id), '', 'memory');
                DB::table('canonical_memory_tasks')->where('id', $row->id)->update(['last_publish_error' => null]);
                $sent++;
            } catch (\Throwable) {
                DB::table('canonical_memory_tasks')->where('id', $row->id)->update(['last_publish_error' => 'QUEUE_UNAVAILABLE', 'next_delivery_at' => DB::raw("clock_timestamp()+interval '15 seconds'")]);
                $failed++;
            }
        }

        return $r + ['published' => $sent, 'failed' => $failed];
    }

    /** 固定书→任务锁序；已完成或在租约内的重复投递没有副作用。 */
    public function claim(string $id): ?array
    {
        $raw = DB::table('canonical_memory_tasks')->find($id);
        if (! $raw) {
            return null;
        }

        return DB::transaction(function () use ($raw) {
            $book = DB::table('novels')->where('id', $raw->novel_id)->lockForUpdate()->first();
            $t = DB::table('canonical_memory_tasks')->where('id', $raw->id)->lockForUpdate()->first();
            if ((string) $book->active_canonical_revision_id !== (string) $t->revision_id) {
                DB::table('canonical_memory_tasks')->where('id', $t->id)->update(['status' => 'stale', 'error_code' => 'STALE_REVISION']);

                return null;
            }
            $available = DB::table('canonical_memory_tasks')->where('id', $t->id)->whereRaw('available_at <= clock_timestamp()')->where(function ($q) {
                $q->where('status', 'queued')->orWhere(function ($q) {
                    $q->where('status', 'running')->whereRaw('lease_until<=clock_timestamp()');
                });
            })->exists();
            if ($available && $t->attempts >= 3) {
                DB::table('canonical_memory_tasks')->where('id', $t->id)->update(['status' => 'failed', 'error_code' => 'LEASE_EXHAUSTED', 'lease_owner' => null, 'lease_until' => null]);
                DB::table('canonical_memory_attempts')->insertOrIgnore(['novel_id' => $t->novel_id, 'task_id' => $t->id, 'token' => $t->token, 'kind' => 'failed', 'details' => NovelIntentService::canonical(['code' => 'LEASE_EXHAUSTED'])]);

                return null;
            }
            if (! $available || $t->attempts >= 3) {
                return null;
            }
            $owner = (string) Str::uuid();
            $token = $t->token + 1;
            DB::table('canonical_memory_tasks')->where('id', $t->id)->update(['status' => 'running', 'token' => $token, 'attempts' => $t->attempts + 1, 'lease_owner' => $owner, 'lease_until' => DB::raw("clock_timestamp()+interval '60 seconds'"), 'updated_at' => now()]);
            DB::table('canonical_memory_attempts')->insert(['novel_id' => $t->novel_id, 'task_id' => $t->id, 'token' => $token, 'kind' => 'claimed', 'details' => NovelIntentService::canonical(['owner' => $owner])]);

            return ['id' => (string) $t->id, 'novel_id' => (string) $t->novel_id, 'commit_id' => (string) $t->commit_id, 'revision_id' => (string) $t->revision_id, 'token' => $token, 'owner' => $owner, 'profile' => json_decode($t->profile, true), 'profile_hash' => $t->profile_hash];
        });
    }

    /** 外部调用为零；编码在写事务之外执行，崩溃可由过期租约重领。 */
    public function consume(string $id): bool
    {
        $lease = $this->claim($id);
        if (! $lease) {
            return false;
        }
        try {
            return $this->finish($lease, $this->prepare($lease));
        } catch (\Throwable $e) {
            $this->failure($lease, $e instanceof NovelProblem ? $e->errorCode : 'MEMORY_PROCESSING_FAILED');
            throw $e;
        }
    }

    public function prepare(array $lease): array
    {
        $t = DB::table('canonical_memory_tasks')->find($lease['id']);
        if (! $t || $this->hash(json_decode($t->profile, true)) !== $lease['profile_hash'] || json_decode($t->profile, true) !== $lease['profile']) {
            throw new NovelProblem('PROFILE_MISMATCH', '冻结的嵌入档案已变化。');
        }
        $actor = User::findOrFail(DB::table('canonical_commits')->where('id', $lease['commit_id'])->value('actor_id'));
        $commit = app(CanonicalCommitService::class)->read($actor, $lease['novel_id'], $lease['commit_id']);
        $replay = app(CanonicalCommitService::class)->replay($actor, $lease['novel_id'], $lease['revision_id'], $commit['chapter_sequence']);
        $events = DB::table('story_events')->where('commit_id', $lease['commit_id'])->orderBy('narrative_order')->get();
        $facts = [];
        $documents = [];
        $vectors = [];
        $vectorStatus = 'indexed';
        foreach ($events as $event) {
            $p = json_decode($event->payload, true);
            $value = is_string($p['value']) ? $p['value'] : NovelIntentService::canonical($p['value']);
            $facts[] = ['event_id' => (string) $event->id, 'proposed_event_id' => (string) $event->proposed_event_id, 'evidence_id' => (string) $event->evidence_id, 'kind' => $p['fact_kind'], 'attribute' => $p['attribute'], 'value' => $p['value'], 'action' => $p['action'], 'entity_id' => (string) $p['entity_id'], 'holder_id' => $p['holder_id'] === null ? null : (string) $p['holder_id'], 'story_time' => $p['story_time'], 'narrative_order' => $event->narrative_order, 'supersedes_id' => $event->supersedes_id === null ? null : (string) $event->supersedes_id];
            $doc = ['event_id' => (string) $event->id, 'attribute' => $p['attribute'], 'body' => $value, 'fact_kind' => $p['fact_kind']];
            $documents[] = $doc;
            $input = $p['attribute'].' '.$value;
            try {
                if (! config('novelforge.canonical_vectors', true)) {
                    throw new \RuntimeException('offline vectors disabled');
                }
                $vectors[$event->id] = ['input_hash' => hash('sha256', $input), 'embedding' => app(OfflineEmbedding::class)->encode($input, 64)];
            } catch (\Throwable) {
                $vectorStatus = 'degraded';
            }
        }
        $payload = ['schema' => 'canonical-memory-v1', 'commit_id' => $commit['id'], 'body_hash' => $commit['body_hash'], 'facts' => $facts, 'state' => $replay['state']];
        $summaries = [];
        foreach (['working', 'episodic', 'semantic', 'event'] as $kind) {
            // 明确保留完整事实和值，不冒充自然语言语义压缩或推断。
            $text = match ($kind) {
                'working' => $payload['state'], 'episodic' => ['chapter_sequence' => $commit['chapter_sequence'], 'events' => $facts], 'semantic' => $payload['state']['state'], 'event' => ['events' => $facts]
            };
            $m = ['kind' => $kind, 'summary' => NovelIntentService::canonical($text), 'payload' => $payload];
            $m['content_hash'] = $this->hash($m);
            $summaries[] = $m;
        }

        return ['commit' => $commit, 'summaries' => $summaries, 'documents' => $documents, 'vectors' => $vectors, 'vector_status' => $vectorStatus];
    }

    /** 封口重验租约、活动基线与来源；失败时四层摘要、文档、向量和回执整体回滚。 */
    public function finish(array $lease, array $prepared): bool
    {
        return DB::transaction(function () use ($lease, $prepared) {
            $book = DB::table('novels')->where('id', $lease['novel_id'])->lockForUpdate()->first();
            $t = DB::table('canonical_memory_tasks')->where('id', $lease['id'])->lockForUpdate()->first();
            if (! $t || $t->status !== 'running' || $t->token !== $lease['token'] || $t->lease_owner !== $lease['owner'] || ! DB::table('canonical_memory_tasks')->where('id', $t->id)->whereRaw('lease_until>clock_timestamp()')->exists()) {
                return false;
            }
            if ((string) $book->active_canonical_revision_id !== $lease['revision_id']) {
                throw new NovelProblem('STALE_REVISION', '正式基线已切换，旧派生结果不得激活。');
            }
            $actor = User::findOrFail(DB::table('canonical_commits')->where('id', $t->commit_id)->value('actor_id'));
            $commit = app(CanonicalCommitService::class)->read($actor, $lease['novel_id'], $lease['commit_id']);
            if ($this->hash($commit) !== $this->hash($prepared['commit'])) {
                throw new NovelProblem('DEPENDENCY_MISMATCH', '正式来源身份变化。');
            }
            DB::select("SELECT set_config('novelforge.memory_token',?,true)", [(string) $lease['token']]);
            foreach ($prepared['summaries'] as $m) {
                DB::table('canonical_memories')->insertOrIgnore(['novel_id' => $t->novel_id, 'revision_id' => $t->revision_id, 'commit_id' => $t->commit_id, 'chapter_sequence' => $commit['chapter_sequence'], 'kind' => $m['kind'], 'summary' => $m['summary'], 'payload' => NovelIntentService::canonical($m['payload']), 'content_hash' => $m['content_hash'], 'source_hash' => $commit['body_hash']]);
            }
            foreach ($prepared['documents'] as $d) {
                $id = DB::table('canonical_memory_documents')->where('revision_id', $t->revision_id)->where('event_id', $d['event_id'])->value('id');
                if (! $id) {
                    $id = DB::table('canonical_memory_documents')->insertGetId($d + ['novel_id' => $t->novel_id, 'revision_id' => $t->revision_id, 'commit_id' => $t->commit_id, 'chapter_sequence' => $commit['chapter_sequence'], 'content_hash' => $this->hash($d)]);
                }
                if (isset($prepared['vectors'][$d['event_id']])) {
                    DB::table('canonical_memory_embeddings')->insertOrIgnore($prepared['vectors'][$d['event_id']] + ['novel_id' => $t->novel_id, 'document_id' => $id, 'profile_hash' => $t->profile_hash, 'origin' => 'offline_hash_v1']);
                }
            }
            $count = DB::table('story_events')->where('commit_id', $t->commit_id)->count();
            $docs = DB::table('canonical_memory_documents')->where('commit_id', $t->commit_id);
            if (DB::table('canonical_memories')->where('commit_id', $t->commit_id)->count() !== 4 || (clone $docs)->count() !== $count || ($prepared['vector_status'] === 'indexed' && DB::table('canonical_memory_embeddings')->whereIn('document_id', $docs->select('id'))->where('profile_hash', $t->profile_hash)->count() !== $count)) {
                throw new NovelProblem('INCOMPLETE_MEMORY_OUTPUT', '摘要、索引或向量不完整，不封口任务。');
            }
            DB::table('canonical_memory_attempts')->insert(['novel_id' => $t->novel_id, 'task_id' => $t->id, 'token' => $t->token, 'kind' => 'completed', 'details' => NovelIntentService::canonical(['vector_status' => $prepared['vector_status'], 'memory_count' => 4])]);
            DB::table('canonical_memory_tasks')->where('id', $t->id)->update(['status' => 'complete', 'vector_status' => $prepared['vector_status'], 'error_code' => null, 'lease_until' => null, 'lease_owner' => null, 'updated_at' => now()]);

            return true;
        });
    }

    public function failure(array $lease, string $code): void
    {
        DB::transaction(function () use ($lease, $code) {
            DB::table('novels')->where('id', $lease['novel_id'])->lockForUpdate()->first();
            $t = DB::table('canonical_memory_tasks')->where('id', $lease['id'])->lockForUpdate()->first();
            if (! $t || $t->token !== $lease['token'] || $t->lease_owner !== $lease['owner'] || $t->status !== 'running') {
                return;
            }
            DB::table('canonical_memory_attempts')->insertOrIgnore(['novel_id' => $t->novel_id, 'task_id' => $t->id, 'token' => $t->token, 'kind' => 'failed', 'details' => NovelIntentService::canonical(['code' => $code])]);
            DB::table('canonical_memory_tasks')->where('id', $t->id)->update(['status' => $t->attempts >= 3 ? 'failed' : 'queued', 'error_code' => $code, 'lease_owner' => null, 'lease_until' => null, 'available_at' => DB::raw("clock_timestamp()+interval '15 seconds'"), 'updated_at' => now()]);
        });
    }

    /** 人工重试留原因和幂等回执，不修改原失败日志或正式事实。 */
    public function retry(User $actor, string $novel, string $id, string $reason, string $key): array
    {
        Validator::make(compact('reason', 'key', 'id'), ['id' => 'required|integer|min:1|max:9223372036854775807', 'reason' => 'required|string|max:2000', 'key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D']])->validate();

        return DB::transaction(function () use ($actor, $novel, $id, $reason, $key) {
            app(NovelIntentService::class)->novel($actor, $novel, true);
            $where = ['actor_id' => $actor->id, 'novel_scope' => $novel, 'command' => 'canonical_memory.retry.'.$id, 'key' => $key];
            $hash = $this->hash([$id, $reason]);
            if ($prior = DB::table('novel_commands')->where($where)->first()) {
                if ($prior->request_hash !== $hash) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '重试键已绑定其他原因。');
                }

                return json_decode($prior->response, true);
            }
            $t = DB::table('canonical_memory_tasks')->where('novel_id', $novel)->where('id', $id)->lockForUpdate()->first() ?? throw new NovelProblem('NOT_FOUND', '派生任务不存在。', 404);
            if ($t->status === 'running') {
                throw new NovelProblem('MEMORY_BUSY', '租约尚在执行，等待完成或过期恢复。');
            }
            DB::select("SELECT set_config('novelforge.reason',?,true)", [$reason]);
            DB::table('canonical_memory_tasks')->where('id', $id)->update(['status' => 'queued', 'attempts' => 0, 'available_at' => now(), 'next_delivery_at' => now(), 'updated_at' => now()]);
            $r = json_decode(NovelIntentService::canonical(['task_id' => $id, 'status' => 'queued']), true);
            DB::table('novel_commands')->insert($where + ['request_hash' => $hash, 'response' => NovelIntentService::canonical($r), 'created_at' => now()]);

            return $r;
        });
    }

    private function revision(User $actor, string $novel, array $d): object
    {
        $book = app(NovelIntentService::class)->novel($actor, $novel);
        Validator::make($d, ['revision_id' => 'required|integer|min:1|max:9223372036854775807'])->validate();
        if ((string) $book->active_canonical_revision_id !== (string) $d['revision_id']) {
            throw new NovelProblem('STALE_REVISION', '必须显式指定当前活动正式基线，不回退候选。');
        }

        return DB::table('canonical_revisions')->where('novel_id', $novel)->where('id', $d['revision_id'])->first() ?? throw new NovelProblem('NOT_FOUND', '正式基线不存在。', 404);
    }

    /** 不依赖派生任务与索引的正式安全桥；完整状态和最近正文均为强制资料。 */
    public function bridge(User $actor, string $novel, string $revision, int $cutoff, string $pov): array
    {
        $r = $this->revision($actor, $novel, ['revision_id' => $revision]);
        Validator::make(['pov' => $pov], ['pov' => 'required|integer|min:1|max:9223372036854775807'])->validate();
        if (! DB::table('entities')->where('novel_id', $novel)->where('id', $pov)->where('type', 'character')->whereNotNull('approved_version_id')->where('retired', false)->exists()) {
            throw new NovelProblem('INVALID_KNOWER', '正式POV必须属于同书已批准人物。');
        }
        if ($cutoff < 0 || $cutoff > $r->cursor) {
            throw new NovelProblem('FORMAL_PREFIX_INCOMPLETE', '缺少连续正式前缀，等待正式提交。');
        }
        if (DB::table('canonical_chapters')->where('revision_id', $revision)->where('sequence', '<=', $cutoff)->count() !== $cutoff) {
            throw new NovelProblem('FORMAL_PREFIX_INCOMPLETE', '正式前缀不连续。');
        }
        $book = app(NovelIntentService::class)->novel($actor, $novel);
        if ($r->local_state_version !== $book->state_version) {
            throw new NovelProblem('STALE_STATE', '活动正式状态版本不一致。');
        }
        if ($cutoff === 0) {
            return ['revision_id' => $revision, 'chapter_cutoff' => 0, 'state' => [], 'pov' => ['knowledge' => [], 'beliefs' => [], 'relationships' => []], 'recent' => [], 'active_event_ids' => [], 'known_event_ids' => [], 'memory_pending' => false];
        }
        $state = app(CanonicalCommitService::class)->replay($actor, $novel, $revision, $cutoff);
        $contextId = DB::table('extraction_attempts')->where('id', $state['request']['event_artifact_id'])->value('context_id');
        $context = app(StoryConsistencyService::class)->read($actor, $novel, (string) $contextId);
        $known = app(StoryConsistencyValidator::class)->pov($context['payload'], array_column($context['events'], null, 'id'), $pov, $cutoff);
        if (! $known['usable']) {
            throw new NovelProblem('POV_CONTEXT_UNUSABLE', '正式人物知识无法证明，不能放宽过滤。');
        }
        $map = DB::table('story_events')->where('revision_id', $revision)->whereIn('proposed_event_id', array_column($context['events'], 'id'))->pluck('id', 'proposed_event_id')->all();
        $knownIds = [];
        foreach (['knowledge', 'beliefs'] as $field) {
            foreach ($known[$field] as &$k) {
                if (isset($k['fact_event_id'])) {
                    // 推断/信念仍保留在带类型的POV快照，不通过无类型检索文本升格事实。
                    if (in_array($k['knowledge_kind'], ['fact', 'hidden_truth'], true)) {
                        $knownIds[] = (string) $map[$k['fact_event_id']];
                    }
                    $k['fact_event_id'] = (string) $map[$k['fact_event_id']];
                    $k['acquired_event_id'] = (string) $map[$k['acquired_event_id']];
                }
                if (isset($k['id'])) {
                    $k['id'] = (string) $map[$k['id']];
                }
                $e = $k['evidence'];
                $k['evidence'] = array_intersect_key($e, array_flip(['id', 'source_version_id', 'source_hash', 'start_offset', 'end_offset', 'offset_unit']));
                $k['evidence']['quote_hash'] = hash('sha256', $e['quote']);
            }unset($k);
        }
        foreach ($known['relationships'] as &$relation) {
            $relation['event_id'] = (string) $map[$relation['event_id']];
        }unset($relation);
        $active = [];
        foreach ($state['state']['state'] as $values) {
            foreach ($values as $value) {
                $active[] = $value['source_event_id'];
            }
        }
        $recent = [];
        foreach (DB::table('canonical_chapters')->where('revision_id', $revision)->where('sequence', '<=', $cutoff)->orderByDesc('sequence')->limit(2)->get() as $m) {
            $c = app(CanonicalCommitService::class)->read($actor, $novel, (string) $m->commit_id);
            $b = DB::table('chapter_versions')->find($m->chapter_version_id);
            if (! $b || hash('sha256', $b->body) !== $c['body_hash']) {
                throw new NovelProblem('INVALID_FORMAL_BODY', '正式正文哈希不符。');
            }
            $recent[] = ['id' => (string) $b->id, 'chapter' => $m->sequence, 'body_hash' => $b->body_hash, 'body' => $b->body];
        }
        $total = DB::table('canonical_commits')->where('revision_id', $revision)->where('chapter_sequence', '<=', $cutoff)->count();
        $done = DB::table('canonical_memory_tasks')->where('revision_id', $revision)->where('status', 'complete')->whereIn('commit_id', DB::table('canonical_commits')->where('revision_id', $revision)->where('chapter_sequence', '<=', $cutoff)->select('id'))->count();

        return ['revision_id' => $revision, 'chapter_cutoff' => $cutoff, 'state' => $state['state'], 'pov' => array_intersect_key($known, array_flip(['knowledge', 'beliefs', 'relationships'])), 'recent' => $recent, 'active_event_ids' => $active, 'known_event_ids' => $knownIds, 'memory_pending' => $done < $total];
    }

    public function memories(User $actor, string $novel, array $d, ?string $id = null): array
    {
        Validator::make(['id' => $id], ['id' => 'nullable|integer|min:1|max:9223372036854775807'])->validate();
        $r = $this->revision($actor, $novel, $d);
        Validator::make($d, ['branch_id' => 'prohibited', 'build_id' => 'prohibited', 'context_id' => 'prohibited', 'profile_id' => 'prohibited', 'chapter' => 'sometimes|integer|min:1', 'kind' => 'sometimes|in:working,episodic,semantic,event', 'page' => 'sometimes|integer|min:1'])->validate();
        $cutoff = (int) ($d['chapter'] ?? $r->cursor);
        if ($cutoff > $r->cursor) {
            throw new NovelProblem('FORMAL_PREFIX_INCOMPLETE', '截止章尚未正式提交。');
        }
        $q = DB::table('canonical_memories')->where('novel_id', $novel)->where('revision_id', $r->id)->where('chapter_sequence', '<=', $cutoff);
        if ($id !== null) {
            $q->where('id', $id);
        } if (isset($d['kind'])) {
            $q->where('kind', $d['kind']);
        }
        $total = (clone $q)->count();
        $page = (int) ($d['page'] ?? 1);
        $items = [];
        foreach ($q->orderByDesc('chapter_sequence')->orderBy('id')->offset(($page - 1) * 50)->limit(50)->get() as $m) {
            $p = json_decode($m->payload, true);
            if ($this->hash(['kind' => $m->kind, 'summary' => $m->summary, 'payload' => $p]) !== $m->content_hash) {
                throw new NovelProblem('INVALID_MEMORY_HASH', '正式记忆哈希不符。');
            } $c = app(CanonicalCommitService::class)->read($actor, $novel, (string) $m->commit_id);
            if ($c['body_hash'] !== $m->source_hash) {
                throw new NovelProblem('INVALID_MEMORY_SOURCE', '正式记忆来源失效。');
            }$items[] = ['id' => (string) $m->id, 'kind' => $m->kind, 'version' => 1, 'summary' => $m->summary, 'payload' => $p, 'content_hash' => $m->content_hash, 'chapter_sequence' => $m->chapter_sequence, 'formal_usable' => true, 'scope' => 'formal', 'commit_id' => (string) $m->commit_id, 'revision_id' => (string) $r->id];
        }
        if ($id !== null) {
            return $items[0] ?? throw new NovelProblem('NOT_FOUND', '正式记忆不存在。', 404);
        }

        return ['scope' => 'formal', 'revision_id' => (string) $r->id, 'formal_usable' => true, 'items' => $items, 'page' => $page, 'total' => $total, 'next_page' => $total > $page * 50 ? $page + 1 : null];
    }

    /** 先正式基线/截止章/活动事件/合法获知链，再做精确、trgm和离线向量排名。 */
    public function search(User $actor, string $novel, array $d): array
    {
        $r = $this->revision($actor, $novel, $d);
        Validator::make($d, ['branch_id' => 'prohibited', 'build_id' => 'prohibited', 'context_id' => 'prohibited', 'profile_id' => 'prohibited', 'query' => 'required|string|max:500', 'chapter' => 'required|integer|min:1', 'audience' => 'required|in:author,pov', 'pov_id' => 'required_if:audience,pov|integer|min:1', 'limit' => 'sometimes|integer|min:1|max:100', 'vector' => 'sometimes|boolean'])->validate();
        if ((int) $d['chapter'] > $r->cursor) {
            throw new NovelProblem('FORMAL_PREFIX_INCOMPLETE', '截止章尚未正式提交。');
        }
        $pov = ! empty($d['pov_id']) ? $d['pov_id'] : DB::table('entities')->where('novel_id', $novel)->where('type', 'character')->whereNotNull('approved_version_id')->where('retired', false)->value('id');
        $bridge = $this->bridge($actor, $novel, (string) $r->id, (int) $d['chapter'], (string) $pov);
        $ids = $d['audience'] === 'pov' ? array_intersect($bridge['active_event_ids'], $bridge['known_event_ids']) : $bridge['active_event_ids'];
        $q = DB::table('canonical_memory_documents')->where('novel_id', $novel)->where('revision_id', $r->id)->where('chapter_sequence', '<=', $d['chapter'])->whereIn('event_id', $ids);
        if ((clone $q)->count() > 10000) {
            throw new NovelProblem('SEARCH_CAPACITY_EXCEEDED', '正式查询超出有界范围，不放宽过滤。');
        }
        $query = $d['query'];
        if (OfflineEmbedding::normalize($query) === '') {
            throw new NovelProblem('EMPTY_QUERY', '查询不能只有标点或空白。', 422);
        }
        $ranks = [];
        $degraded = ! (bool) ($d['vector'] ?? true) || ! config('novelforge.canonical_vectors', true);
        foreach ($q->selectRaw('canonical_memory_documents.*, similarity(body, ?) AS trgm', [$query])->get() as $doc) {
            $data = ['event_id' => (string) $doc->event_id, 'attribute' => $doc->attribute, 'body' => $doc->body, 'fact_kind' => $doc->fact_kind];
            if ($this->hash($data) !== $doc->content_hash) {
                throw new NovelProblem('INVALID_DOCUMENT_HASH', '正式检索文档哈希不符。');
            }
            $score = (float) $doc->trgm;
            $channels = ['trgm' => (string) $score];
            if (mb_strpos($doc->body, $query) !== false) {
                $score += 1;
                $channels['exact'] = '1';
            }
            if (! $degraded) {
                try {
                    $distance = DB::transaction(fn () => DB::table('canonical_memory_embeddings')->where('document_id', $doc->id)->where('input_hash', hash('sha256', $doc->attribute.' '.$doc->body))->whereIn('profile_hash', DB::table('canonical_memory_tasks')->where('commit_id', $doc->commit_id)->select('profile_hash'))->selectRaw('embedding <=> ?::vector AS distance', [app(OfflineEmbedding::class)->encode($doc->attribute.' '.$query, 64)])->first());
                    if ($distance) {
                        $v = max(0, 1 - (float) $distance->distance);
                        $score += $v;
                        $channels['offline_vector'] = (string) $v;
                    } else {
                        $degraded = true;
                    }
                } catch (\Throwable) {
                    $degraded = true;
                }
            }
            if ($score > 0) {
                $ranks[] = ['id' => (string) $doc->id, 'event_id' => (string) $doc->event_id, 'body' => $doc->body, 'attribute' => $doc->attribute, 'chapter' => $doc->chapter_sequence, 'score' => (string) $score, 'contributions' => $channels, 'scope' => 'formal', 'revision_id' => (string) $r->id];
            }
        }
        if ($degraded) {
            // 任一向量不可用时全查询退回文本排名，避免部分文档带向量而其他没有。
            foreach ($ranks as &$hit) {
                if (isset($hit['contributions']['offline_vector'])) {
                    $hit['score'] = (string) ((float) $hit['score'] - (float) $hit['contributions']['offline_vector']);
                    unset($hit['contributions']['offline_vector']);
                }
            } unset($hit);
            $ranks = array_values(array_filter($ranks, fn ($hit) => (float) $hit['score'] > 0));
        }
        usort($ranks, fn ($a, $b) => [(float) $b['score'], -(int) $b['id']] <=> [(float) $a['score'], -(int) $a['id']]);

        return ['scope' => 'formal', 'revision_id' => (string) $r->id, 'results' => array_slice($ranks, 0, $d['limit'] ?? 20), 'degraded' => $degraded, 'degradation_reason' => $degraded ? 'VECTOR_UNAVAILABLE_OR_DISABLED' : null, 'memory_pending' => $bridge['memory_pending'], 'semantic_model' => false];
    }

    public function dashboard(User $actor, string $novel): array
    {
        $book = app(NovelIntentService::class)->novel($actor, $novel);
        $tasks = DB::table('canonical_memory_tasks')->where('novel_id', $novel)->orderByDesc('id')->limit(100)->get()->map(function ($t) {
            $r = (array) $t;
            foreach ($r as $k => &$v) {
                if ($v !== null && ($k === 'id' || str_ends_with($k, '_id'))) {
                    $v = (string) $v;
                }
            }unset($v);
            $r['attempt_log'] = DB::table('canonical_memory_attempts')->where('task_id', $t->id)->orderBy('id')->get()->all();

            return $r;
        })->all();
        $pending = DB::table('canonical_outbox as o')->leftJoin('canonical_memory_tasks as t', 't.outbox_id', '=', 'o.id')->where('o.novel_id', $novel)->where('o.revision_id', $book->active_canonical_revision_id)->where(function ($q) {
            $q->whereNull('t.id')->orWhere('t.status', '<>', 'complete');
        })->count();

        return ['novel_id' => $novel, 'revision_id' => $book->active_canonical_revision_id === null ? null : (string) $book->active_canonical_revision_id, 'state_version' => $book->state_version, 'memory_pending' => $pending > 0, 'pending_count' => $pending, 'tasks' => $tasks, 'mode' => 'offline_conservative_and_hash_vectors'];
    }
}
