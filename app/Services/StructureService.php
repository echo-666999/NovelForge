<?php

namespace App\Services;

use App\Exceptions\NovelProblem;
use App\Models\Chapter;
use App\Models\Novel;
use App\Models\OutlineVersion;
use App\Models\PlanNode;
use App\Models\PremiseVersion;
use App\Models\Scene;
use App\Models\StoryArc;
use App\Models\Thread;
use App\Models\User;
use App\Models\Volume;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class StructureService
{
    public const MODELS = ['volume' => Volume::class, 'story_arc' => StoryArc::class, 'chapter' => Chapter::class, 'scene' => Scene::class];

    public const PARENTS = ['story_arc' => 'volume', 'chapter' => 'story_arc', 'scene' => 'chapter'];

    public const DETAIL_FIELDS = ['turning_point', 'climax', 'ending_state', 'pov', 'story_time', 'location', 'conflict', 'character_change', 'foreshadow_tasks', 'participants', 'transition_in', 'transition_out', 'forbidden_changes', 'required_events', 'dependencies', 'target_words'];

    public function execute(User $actor, string $novelId, string $command, array $data, string $key): array
    {
        $intents = app(NovelIntentService::class);
        $intents->authorize($actor);
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D']])->validate();
        $hash = hash('sha256', NovelIntentService::canonical($data));
        try {
            return DB::transaction(function () use ($intents, $actor, $novelId, $command, $data, $key, $hash) {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [NovelIntentService::canonical([$actor->id, $novelId, 'structure.'.$command, $key])]);
                $novel = $intents->novel($actor, $novelId, true);
                $where = ['actor_id' => $actor->id, 'novel_scope' => $novelId, 'command' => 'structure.'.$command, 'key' => $key];
                if ($old = DB::table('novel_commands')->where($where)->first()) {
                    if ($old->request_hash !== $hash) {
                        throw new NovelProblem('IDEMPOTENCY_CONFLICT', '操作键已用于不同内容。');
                    }

                    return json_decode($old->response, true, flags: JSON_THROW_ON_ERROR);
                }
                Validator::make($data, ['expected_version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000'])->validate();
                if ((int) $novel->row_version !== (int) $data['expected_version']) {
                    throw new NovelProblem('STALE_STATE', '结构已变化，请刷新后重新操作。');
                }
                if (! in_array($novel->status, ['draft', 'planning'])) {
                    throw new NovelProblem('INVALID_TRANSITION', '当前小说状态不可修改结构。');
                }
                $result = match ($command) {
                    'node' => $this->saveNode($novel, $data),
                    'thread' => $this->saveThread($novel, $data),
                    'snapshot' => $this->snapshot($actor, $novel, $data),
                    default => throw new NovelProblem('INVALID_TRANSITION', '不支持该结构操作。'),
                };
                $novel->row_version++;
                $novel->status = 'planning';
                $novel->save();
                $result['novel'] = $novel->fresh()->toArray();
                DB::table('novel_commands')->insert($where + ['request_hash' => $hash, 'response' => NovelIntentService::canonical($result), 'created_at' => now()]);

                return $result;
            });
        } catch (QueryException $e) {
            if (in_array($e->errorInfo[0] ?? null, ['23503', '23505', '23514', '23P01'])) {
                throw new NovelProblem('STRUCTURE_CONFLICT', '父级、章范围或顺序存在冲突；请检查同书归属、子节点与重复序号。');
            }
            throw $e;
        }
    }

    private function saveNode(Novel $novel, array $data): array
    {
        $v = Validator::make($data, [
            'type' => ['required', Rule::in(array_keys(self::MODELS))], 'node_id' => 'nullable|integer|min:1',
            'parent_id' => 'nullable|integer|min:1', 'source_version_id' => 'required|integer|min:1',
            'sequence' => 'required|integer|min:1|max:100000', 'title' => 'required|string|max:255', 'objective' => 'required|string|max:20000',
            'summary' => 'nullable|string|max:20000', 'status' => ['required', Rule::in(['skeleton', 'detailed'])],
            'start_chapter' => 'nullable|integer|min:1|max:100000', 'end_chapter' => 'nullable|integer|gte:start_chapter|max:100000',
            'details' => 'sometimes|array:'.implode(',', self::DETAIL_FIELDS),
            ...array_fill_keys(array_map(fn ($k) => 'details.'.$k, array_diff(self::DETAIL_FIELDS, ['target_words'])), 'nullable|string|max:20000'),
            'details.target_words' => 'nullable|integer|min:1|max:1000000', 'thread_ids' => 'sometimes|array', 'thread_ids.*' => 'required|integer|min:1|distinct',
            'reason' => 'required|string|max:2000',
        ])->validate();
        $type = $v['type'];
        $model = self::MODELS[$type];
        $node = empty($v['node_id']) ? new $model : $model::where('novel_id', $novel->id)->find($v['node_id']);
        if (! $node) {
            throw new NovelProblem('NOT_FOUND', '结构节点不存在。', 404);
        }
        if (! PremiseVersion::where('novel_id', $novel->id)->whereKey($v['source_version_id'])->exists()) {
            throw new NovelProblem('NOT_FOUND', '原始输入版本不存在。', 404);
        }
        $values = array_intersect_key($v, array_flip(['source_version_id', 'sequence', 'title', 'objective', 'summary', 'status', 'reason']));
        $values['summary'] = $values['summary'] ?? '';
        $values['details'] = $v['details'] ?? [];
        if (isset($values['details']['target_words'])) {
            $values['details']['target_words'] = (int) $values['details']['target_words'];
        }
        if (isset(self::PARENTS[$type])) {
            $parent = self::MODELS[self::PARENTS[$type]];
            if (empty($v['parent_id']) || ! $parent::where('novel_id', $novel->id)->whereKey($v['parent_id'])->exists()) {
                throw new NovelProblem('NOT_FOUND', '同书父节点不存在。', 404);
            }
            $values['parent_id'] = $v['parent_id'];
        } elseif (! empty($v['parent_id'])) {
            throw new NovelProblem('VALIDATION_FAILED', '卷直接属于小说，不能指定其他父级。', 422);
        }
        if (in_array($type, ['volume', 'story_arc'])) {
            Validator::make($v, ['start_chapter' => 'required|integer|min:1', 'end_chapter' => 'required|integer|gte:start_chapter'])->validate();
            if ($v['end_chapter'] > $novel->target_chapters) {
                throw new NovelProblem('VALIDATION_FAILED', '范围超过目标章数。', 422);
            }
            $values['start_chapter'] = $v['start_chapter'];
            $values['end_chapter'] = $v['end_chapter'];
        }
        if ($node->exists) {
            $node->row_version++;
        }
        $node->fill($values + ['novel_id' => $novel->id]);
        $node->save();
        if ($type === 'story_arc') {
            $ids = array_map('strval', $v['thread_ids'] ?? []);
            if (Thread::where('novel_id', $novel->id)->whereIn('id', $ids)->count() !== count($ids)) {
                throw new NovelProblem('NOT_FOUND', '关联线索不属于当前小说。', 404);
            }
            DB::table('arc_thread')->where('arc_id', $node->id)->delete();
            foreach ($ids as $id) {
                DB::table('arc_thread')->insert(['novel_id' => $novel->id, 'arc_id' => $node->id, 'thread_id' => $id]);
            }
        } elseif (! empty($v['thread_ids'])) {
            throw new NovelProblem('VALIDATION_FAILED', '全书线索只通过故事弧关联。', 422);
        }

        return ['node' => $node->fresh()->toArray(), 'type' => $type];
    }

    private function saveThread(Novel $novel, array $data): array
    {
        $v = Validator::make($data, ['thread_id' => 'nullable|integer|min:1', 'title' => 'required|string|max:255', 'objective' => 'required|string|max:20000', 'reason' => 'required|string|max:2000'])->validate();
        $thread = empty($v['thread_id']) ? new Thread : Thread::where('novel_id', $novel->id)->find($v['thread_id']);
        if (! $thread) {
            throw new NovelProblem('NOT_FOUND', '全书线索不存在。', 404);
        }
        unset($v['thread_id']);
        if ($thread->exists) {
            $thread->row_version++;
        }
        $thread->fill($v + ['novel_id' => $novel->id]);
        $thread->save();

        return ['thread' => $thread->fresh()->toArray()];
    }

    public function working(User $actor, string $id): array
    {
        $novel = app(NovelIntentService::class)->novel($actor, $id);
        $nodes = [];
        foreach (self::MODELS as $type => $model) {
            foreach ($model::where('novel_id', $id)->orderBy('sequence')->orderBy('id')->get() as $node) {
                $row = $node->toArray();
                $row['type'] = $type;
                if ($type === 'story_arc') {
                    $row['threads'] = Thread::where('novel_id', $id)->whereIn('id', DB::table('arc_thread')->where('arc_id', $node->id)->select('thread_id'))->orderBy('id')->get()->toArray();
                }
                $nodes[] = $row;
            }
        }

        return ['novel' => $novel->toArray(), 'nodes' => $nodes, 'threads' => Thread::where('novel_id', $id)->orderBy('id')->get()->toArray(), 'generation_eligible' => false];
    }

    private function snapshot(User $actor, Novel $novel, array $data): array
    {
        $content = $this->working($actor, $novel->id);
        if (! $content['nodes']) {
            throw new NovelProblem('VALIDATION_FAILED', '请先建立至少一个卷。', 422);
        }
        // The immutable structure root records author inputs, not a mutable current-pointer reference.
        $content['novel'] = array_intersect_key($content['novel'], array_flip(['id', 'title', 'genre', 'premise_raw', 'target_chapters', 'chapter_length_policy', 'settings']));
        $latest = OutlineVersion::where('novel_id', $novel->id)->latest('version')->first();
        $version = OutlineVersion::create([
            'novel_id' => $novel->id, 'version' => ($latest?->version ?? 0) + 1, 'base_version_id' => $latest?->id,
            'source_version_id' => PremiseVersion::where('novel_id', $novel->id)->latest('version')->value('id'),
            'input_row_version' => $novel->row_version, 'status' => 'building', 'content' => $content,
            'content_hash' => hash('sha256', NovelIntentService::canonical($content)), 'schema_version' => 'structure-v1',
            'reason' => $data['reason'], 'actor_id' => $actor->id, 'created_at' => now(),
        ]);
        $parents = [];
        foreach ($content['nodes'] as $row) {
            $type = $row['type'];
            $parentId = isset(self::PARENTS[$type]) ? ($parents[self::PARENTS[$type].':'.$row['parent_id']] ?? null) : null;
            $plan = PlanNode::create(['novel_id' => $novel->id, 'outline_version_id' => $version->id, 'node_type' => $type, $type.'_id' => $row['id'], 'parent_plan_node_id' => $parentId, 'sequence' => $row['sequence'], 'details' => $row, 'content_hash' => hash('sha256', NovelIntentService::canonical($row))]);
            $parents[$type.':'.$row['id']] = $plan->id;
        }
        $version->status = 'sealed';
        $version->save();

        return ['version' => $this->version($actor, $novel->id, $version->id)];
    }

    public function version(User $actor, string $novelId, string $id): array
    {
        app(NovelIntentService::class)->novel($actor, $novelId);
        if (! preg_match('/^[1-9][0-9]{0,18}$/D', $id) || (strlen($id) === 19 && strcmp($id, '9223372036854775807') > 0)) {
            throw new NovelProblem('NOT_FOUND', '结构版本不存在。', 404);
        }
        $v = OutlineVersion::where('novel_id', $novelId)->where('status', 'sealed')->find($id);
        if (! $v) {
            throw new NovelProblem('NOT_FOUND', '结构版本不存在。', 404);
        }
        $result = $v->toArray();
        $result['nodes'] = PlanNode::where('outline_version_id', $v->id)->orderBy('id')->get()->toArray();
        if (! hash_equals($v->content_hash, hash('sha256', NovelIntentService::canonical($v->content)))) {
            throw new NovelProblem('SNAPSHOT_INVALID', '结构快照哈希不匹配。');
        }
        $result['generation_eligible'] = false;

        return $result;
    }

    public function versions(User $actor, string $id, array $filters = []): array
    {
        app(NovelIntentService::class)->novel($actor, $id);
        $v = Validator::make($filters, ['limit' => 'sometimes|integer|min:1|max:100', 'cursor' => 'nullable|string|max:2000'])->validate();
        try {
            $cursor = empty($v['cursor']) ? null : Cursor::fromEncoded($v['cursor']);
            if (! empty($v['cursor']) && (! $cursor || ! preg_match('/^[1-9][0-9]{0,18}$/D', (string) ($cursor->toArray()['id'] ?? '')) || strlen((string) ($cursor->toArray()['id'] ?? '')) === 19 && strcmp((string) $cursor->toArray()['id'], '9223372036854775807') > 0)) {
                throw new \InvalidArgumentException;
            }
        } catch (\Throwable) {
            throw new NovelProblem('VALIDATION_FAILED','无效游标。',422);
        }
        $page = OutlineVersion::where('novel_id',$id)->where('status','sealed')->select(['id', 'novel_id', 'version', 'status', 'content_hash', 'reason', 'created_at'])->orderBy('id')->cursorPaginate($v['limit'] ?? 20,['*'],'cursor',$cursor);

        return ['items' => $page->items(), 'next_cursor' => $page->nextCursor()?->encode()];
    }
}
