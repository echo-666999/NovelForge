<?php

namespace App\Services;

use App\Exceptions\NovelProblem;
use App\Models\Novel;
use App\Models\PremiseVersion;
use App\Models\RequirementItem;
use App\Models\RequirementVersion;
use App\Models\RollingPlan;
use App\Models\User;
use App\Models\Volume;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class NovelIntentService
{
    public const INPUTS = ['title', 'genre', 'target_reader', 'background', 'premise_raw', 'selling_point', 'forbidden_content', 'target_chapters', 'length_policy', 'style', 'ending_preference', 'budget_note'];

    public function authorize(User $actor): void
    {
        if (! $actor->is_admin) {
            throw new NovelProblem('FORBIDDEN', '仅管理员可以管理小说。', 403);
        }
    }

    public function novel(User $actor, string $id, bool $lock = false): Novel
    {
        $this->authorize($actor);
        if (! preg_match('/^[1-9][0-9]{0,18}$/D', $id) || (strlen($id) === 19 && strcmp($id, '9223372036854775807') > 0)) {
            throw new NovelProblem('NOT_FOUND', '小说不存在。', 404);
        }
        $query = Novel::where('owner_id', $actor->id)->whereKey($id);
        $novel = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $novel) {
            throw new NovelProblem('NOT_FOUND', '小说不存在。', 404);
        }

        return $novel;
    }

    public static function canonical(mixed $value): string
    {
        $sort = function ($v) use (&$sort) {
            if (is_float($v)) {
                throw new NovelProblem('VALIDATION_FAILED', '小数请使用十进制字符串。', 422);
            }
            if (is_array($v)) {
                if (! array_is_list($v)) {
                    ksort($v, SORT_STRING);
                }

                return array_map($sort, $v);
            }

            return $v;
        };

        return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function execute(User $actor, string $command, ?string $id, array $payload, string $key): array
    {
        $this->authorize($actor);
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D']])->validate();
        $scope = $id ?? 'new';
        $hash = hash('sha256', self::canonical($payload));

        return DB::transaction(function () use ($actor, $command, $id, $scope, $payload, $key, $hash) {
            // Serialize the same command key, including creation where no novel row exists yet.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [self::canonical([$actor->id, $scope, $command, $key])]);
            $novel = $id === null ? null : $this->novel($actor, $id, true);
            $where = ['actor_id' => $actor->id, 'novel_scope' => $scope, 'command' => $command, 'key' => $key];
            $previous = DB::table('novel_commands')->where($where)->first();
            if ($previous) {
                if ($previous->request_hash !== $hash) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '同一操作键已用于不同内容。');
                }

                return json_decode($previous->response, true, flags: JSON_THROW_ON_ERROR);
            }
            if ($novel) {
                Validator::make($payload, ['expected_version' => 'required|integer|min:1'])->validate();
                if ((int) $payload['expected_version'] !== (int) $novel->row_version) {
                    throw new NovelProblem('STALE_STATE', '内容已更新，请刷新后重新操作。');
                }
            }
            if ($novel && $novel->status === 'archived' && ! in_array($command, ['restore', 'copy'])) {
                throw new NovelProblem('INVALID_TRANSITION', '归档小说只读，请先恢复。');
            }
            $result = match ($command) {
                'create' => $this->create($actor, $payload),
                'update' => $this->update($novel, $payload),
                'source' => $this->source($novel, $payload),
                'archive', 'restore' => $this->transition($novel, $command),
                'copy' => $this->copy($actor, $novel, $payload),
                'requirement' => $this->requirement($actor, $novel, $payload),
                default => throw new NovelProblem('INVALID_TRANSITION', '不支持该操作。'),
            };
            DB::table('novel_commands')->insert($where + ['request_hash' => $hash, 'response' => self::canonical($result), 'created_at' => now()]);

            return $result;
        });
    }

    public function input(array $data): array
    {
        $rules = array_fill_keys(self::INPUTS, 'nullable|string|max:20000');
        $rules = array_replace($rules, [
            'title' => 'required|string|max:255', 'genre' => 'required|string|max:255',
            'background' => 'required|string|max:20000', 'premise_raw' => 'required|string|max:100000',
            'target_chapters' => 'required|integer|min:1|max:100000',
            'length_policy' => 'required|array:min,max', 'length_policy.min' => 'required|integer|min:1|max:100000',
            'length_policy.max' => 'required|integer|gte:length_policy.min|max:100000',
        ]);
        $result = Validator::make($data, $rules)->validate();
        $result['target_chapters'] = (int) $result['target_chapters'];
        $result['length_policy'] = array_map('intval', $result['length_policy']);

        return $result;
    }

    private function create(User $actor, array $data): array
    {
        $input = $this->input($data);
        $novel = Novel::create([
            'owner_id' => $actor->id, 'title' => $input['title'], 'genre' => $input['genre'],
            'premise_raw' => $input['premise_raw'], 'target_chapters' => $input['target_chapters'],
            'chapter_length_policy' => $input['length_policy'], 'settings' => $input, 'status' => 'draft',
        ]);
        $source = $this->appendSource($novel, $input, '创建原始创意');
        // Deterministic suggestions preserve the author's actual words; no model extraction.
        foreach (['background' => '背景', 'premise_raw' => '剧情', 'forbidden_content' => '禁用内容', 'ending_preference' => '结局'] as $field => $category) {
            if (! empty($input[$field])) {
                $item = RequirementItem::create(['novel_id' => $novel->id]);
                RequirementVersion::create([
                    'novel_id' => $novel->id, 'requirement_id' => $item->id, 'source_version_id' => $source->id,
                    'version' => 1, 'source_quote' => $input[$field], 'content' => $input[$field],
                    'category' => $category, 'strength' => $field === 'forbidden_content' ? 'hard' : 'undecided',
                    'critical' => in_array($field, ['background', 'ending_preference']), 'decision_status' => 'unresolved',
                    'reason' => '按输入字段建立待作者确认的要求', 'actor_id' => $actor->id, 'created_at' => now(),
                ]);
            }
        }

        return $this->snapshot($actor, $novel->fresh());
    }

    private function appendSource(Novel $novel, array $input, string $reason): PremiseVersion
    {
        return PremiseVersion::create([
            'novel_id' => $novel->id, 'version' => (int) PremiseVersion::where('novel_id', $novel->id)->max('version') + 1,
            'premise_raw' => $input['premise_raw'], 'input' => $input,
            'content_hash' => hash('sha256', self::canonical($input)), 'reason' => $reason, 'created_at' => now(),
        ]);
    }

    private function update(Novel $novel, array $data): array
    {
        $allowed = ['title', 'genre', 'expected_version'];
        if (array_diff(array_keys($data), $allowed)) {
            throw new NovelProblem('VALIDATION_FAILED', '这里只允许修改书名和题材；原始创意请追加来源版本。', 422);
        }
        $values = Validator::make($data, ['title' => 'sometimes|required|string|max:255', 'genre' => 'sometimes|required|string|max:255'])->validate();
        if (! $values) {
            throw new NovelProblem('VALIDATION_FAILED', '没有可保存的修改。', 422);
        }
        $novel->fill($values);
        $novel->row_version++;
        $novel->save();

        return ['novel' => $novel->fresh()->toArray()];
    }

    private function source(Novel $novel, array $data): array
    {
        $reason = Validator::make($data, ['reason' => 'required|string|max:2000'])->validate()['reason'];
        $input = $this->input($data);
        if (Volume::where('novel_id', $novel->id)->where('end_chapter', '>', $input['target_chapters'])->exists()) {
            throw new NovelProblem('STRUCTURE_CONFLICT', '目标章数不能排除已有卷范围；请先调整结构。');
        }
        $this->appendSource($novel, $input, $reason);
        $novel->fill(['premise_raw' => $input['premise_raw'], 'settings' => $input, 'target_chapters' => $input['target_chapters'], 'chapter_length_policy' => $input['length_policy'], 'title' => $input['title'], 'genre' => $input['genre']]);
        $novel->row_version++;
        $novel->save();

        return ['novel' => $novel->fresh()->toArray(), 'source' => PremiseVersion::where('novel_id', $novel->id)->latest('version')->first()->toArray()];
    }

    private function transition(Novel $novel, string $command): array
    {
        if ($command === 'archive') {
            if (! in_array($novel->status, ['draft', 'planning', 'ready', 'paused', 'completed'])) {
                throw new NovelProblem('INVALID_TRANSITION', '当前状态不能归档。');
            }
            $novel->previous_status = $novel->status;
            $novel->status = 'archived';
            $novel->archived_at = now();
        } else {
            if ($novel->status !== 'archived' || ! in_array($novel->previous_status, ['draft', 'planning', 'ready', 'paused', 'completed'])) {
                throw new NovelProblem('INVALID_TRANSITION', '当前状态不能恢复。');
            }
            $novel->status = $novel->previous_status;
            $novel->previous_status = null;
            $novel->archived_at = null;
        }
        $novel->row_version++;
        $novel->save();

        return ['novel' => $novel->fresh()->toArray()];
    }

    private function copy(User $actor, Novel $novel, array $data): array
    {
        $title = Validator::make($data, ['title' => 'required|string|max:255'])->validate()['title'];

        return $this->create($actor, array_replace($novel->settings, ['title' => $title, 'genre' => $novel->genre]));
    }

    private function requirement(User $actor, Novel $novel, array $data): array
    {
        $v = Validator::make($data, [
            'requirement_id' => ['nullable', 'regex:/^[1-9][0-9]*$/D'],
            'source_version_id' => ['required', 'regex:/^[1-9][0-9]*$/D'],
            'source_quote' => 'required|string|max:100000', 'content' => 'required|string|max:20000',
            'category' => 'required|string|max:100', 'strength' => ['required', Rule::in(['hard', 'preference', 'undecided'])],
            'critical' => 'required|boolean', 'decision_status' => ['required', Rule::in(['unresolved', 'accepted', 'conflict', 'waived'])],
            'conflict' => 'nullable|string|max:20000', 'author_decision' => 'nullable|string|max:20000', 'reason' => 'required|string|max:2000',
        ])->validate();
        $source = PremiseVersion::where('novel_id', $novel->id)->find($v['source_version_id']);
        if (! $source) {
            throw new NovelProblem('NOT_FOUND', '来源不存在。', 404);
        }
        $found = false;
        foreach ($source->input as $text) {
            if (is_string($text) && str_contains($text, $v['source_quote'])) {
                $found = true;
            }
        }
        if (! $found) {
            throw new NovelProblem('EVIDENCE_INVALID', '原句必须是所选原始输入中的准确片段。', 422);
        }
        if (($v['decision_status'] === 'waived' && ($v['strength'] !== 'preference' || empty($v['author_decision'])))
            || ($v['decision_status'] === 'accepted' && (empty($v['author_decision']) || $v['strength'] === 'undecided'))
            || ($v['decision_status'] === 'conflict' && empty($v['conflict']))) {
            throw new NovelProblem('VALIDATION_FAILED', '请明确强度、冲突或作者决定；硬约束不能标记不适用。', 422);
        }
        if (! empty($v['conflict']) && $v['decision_status'] === 'accepted' && empty($v['author_decision'])) {
            throw new NovelProblem('VALIDATION_FAILED', '冲突尚未决定。', 422);
        }
        $item = empty($v['requirement_id']) ? RequirementItem::create(['novel_id' => $novel->id]) : RequirementItem::where('novel_id', $novel->id)->find($v['requirement_id']);
        if (! $item) {
            throw new NovelProblem('NOT_FOUND', '要求不存在。', 404);
        }
        if (! $item->wasRecentlyCreated) {
            $item->current_version++;
            $item->save();
        }
        unset($v['requirement_id']);
        $version = RequirementVersion::create($v + ['novel_id' => $novel->id, 'requirement_id' => $item->id, 'version' => $item->current_version, 'actor_id' => $actor->id, 'created_at' => now()]);
        $novel->row_version++;
        $novel->save();

        return ['novel' => $novel->fresh()->toArray(), 'requirement' => $version->toArray()];
    }

    public function requirements(Novel $novel): array
    {
        return RequirementVersion::query()->join('requirement_items as i', function ($join) {
            $join->on('i.id', '=', 'requirement_versions.requirement_id')->on('i.current_version', '=', 'requirement_versions.version');
        })->where('requirement_versions.novel_id', $novel->id)->select('requirement_versions.*')->orderBy('requirement_versions.requirement_id')->get()->map(function ($row) {
            return $row->toArray() + ['coverage_status' => in_array($row->decision_status, ['conflict', 'waived']) ? $row->decision_status : 'unresolved', 'plan_url' => null, 'coverage_evidence' => []];
        })->all();
    }

    public function paginatedRequirements(User $actor, string $id, array $filters): array
    {
        $novel = $this->novel($actor, $id);
        $filters = Validator::make($filters, [
            'limit' => 'sometimes|integer|min:1|max:100', 'cursor' => 'nullable|string|max:2000',
            'strength' => ['nullable', Rule::in(['hard', 'preference', 'undecided'])],
            'decision_status' => ['nullable', Rule::in(['unresolved', 'accepted', 'conflict', 'waived'])],
            'source_version_id' => 'nullable|integer|min:1',
        ])->validate();
        $query = RequirementVersion::query()->join('requirement_items as i', function ($join) {
            $join->on('i.id', '=', 'requirement_versions.requirement_id')->on('i.current_version', '=', 'requirement_versions.version');
        })->where('requirement_versions.novel_id', $novel->id)->select('requirement_versions.*');
        foreach (['strength', 'decision_status', 'source_version_id'] as $field) {
            if (! empty($filters[$field])) {
                $query->where('requirement_versions.'.$field, $filters[$field]);
            }
        }
        try {
            $cursor = empty($filters['cursor']) ? null : Cursor::fromEncoded($filters['cursor']);
            $position = $cursor?->toArray()['requirement_versions.requirement_id'] ?? null;
            if (! empty($filters['cursor']) && (! $cursor || ! is_scalar($position) || ! preg_match('/^[1-9][0-9]{0,18}$/D', (string) $position) || (strlen((string) $position) === 19 && strcmp((string) $position, '9223372036854775807') > 0))) {
                throw new \InvalidArgumentException;
            }
        } catch (\Throwable) {
            throw new NovelProblem('VALIDATION_FAILED', '分页游标无效。', 422);
        }
        $page = $query->orderBy('requirement_versions.requirement_id')->cursorPaginate($filters['limit'] ?? 20, ['*'], 'cursor', $cursor);

        // 分页接口也返回当前计划证据，但不污染蓝图的原始要求快照。
        $enriched = collect(app(RollingPlanService::class)->coverage($actor, $novel, $this->requirements($novel)))->keyBy('id');

        return ['items' => array_map(fn ($r) => $enriched->get($r->id, $r->toArray()), $page->items()), 'next_cursor' => $page->nextCursor()?->encode(), 'previous_cursor' => $page->previousCursor()?->encode()];
    }

    public function snapshot(User $actor, Novel $novel): array
    {
        $this->novel($actor, $novel->id);
        // 原始要求保持纯数据；仅展示层附加计划证据，避免蓝图依赖哈希循环引用。
        $requirements = app(RollingPlanService::class)->coverage($actor, $novel, $this->requirements($novel));
        $latestPlan = RollingPlan::where('novel_id', $novel->id)->where('status', 'sealed')->latest('version')->first();
        $planReport = $latestPlan ? app(RollingPlanService::class)->report($actor, $novel, $latestPlan) : null;
        $blockers = $planReport['blockers'] ?? [['code' => 'PLAN_REQUIRED', 'requirement_id' => null]];
        $latestSource = (string) PremiseVersion::where('novel_id', $novel->id)->latest('version')->value('id');
        foreach ($requirements as $requirement) {
            if ($requirement['strength'] === 'hard' && $requirement['coverage_status'] !== 'covered') {
                $blockers[] = ['code' => 'HARD_REQUIREMENT_UNCOVERED', 'requirement_id' => $requirement['requirement_id']];
            }
            if ($requirement['decision_status'] === 'conflict' || ($requirement['critical'] && ! in_array($requirement['decision_status'], ['accepted', 'waived']))) {
                $blockers[] = ['code' => 'AUTHOR_DECISION_REQUIRED', 'requirement_id' => $requirement['requirement_id']];
            }
            if ($requirement['source_version_id'] !== $latestSource) {
                $blockers[] = ['code' => 'SOURCE_VERSION_CHANGED', 'requirement_id' => $requirement['requirement_id']];
            }
        }

        return ['novel' => $novel->toArray(), 'sources' => PremiseVersion::where('novel_id', $novel->id)->orderBy('version')->get()->toArray(), 'requirements' => $requirements,
            'unresolved_requirements' => array_values(array_filter($requirements, fn ($r) => in_array($r['decision_status'], ['unresolved', 'conflict']))),
            'generation_eligible' => false, 'freeze_gate' => ['allowed' => $planReport['passed'] ?? false, 'blockers' => $blockers, 'code' => ($planReport['passed'] ?? false) ? 'PLAN_READY' : 'PLAN_BLOCKED', 'message' => '此门禁仅针对规划冻结；真实模型生成与正式提交尚未接入。']];
    }
}
