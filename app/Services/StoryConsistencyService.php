<?php

namespace App\Services;

use App\Exceptions\NovelProblem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** D02 时间线版本只保存候选知识与约束，不能激活正式状态。 */
final class StoryConsistencyService
{
    public function save(User $actor, string $novel, array $input, string $key): array
    {
        Validator::make(['key' => $key] + $input, ['key' => 'required|string|max:128|regex:/^[\x20-\x7E]+$/D', 'expected_version' => 'required|integer|min:0', 'reason' => 'required|string|max:2000'])->validate();
        $hash = hash('sha256', NovelIntentService::canonical($input));

        return DB::transaction(function () use ($actor, $novel, $input, $key, $hash) {
            app(NovelIntentService::class)->novel($actor, $novel, true);
            $prior = DB::table('story_context_versions')->where('novel_id', $novel)->where('command_key', $key)->first();
            if ($prior) {
                if ($prior->request_hash !== $hash) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '同一命令标识已用于不同内容。');
                }

                return $this->read($actor, $novel, (string) $prior->id);
            }
            $version = (int) DB::table('story_context_versions')->where('novel_id', $novel)->max('version');
            if ((int) $input['expected_version'] !== $version) {
                throw new NovelProblem('VERSION_CONFLICT', '候选时间线已有新版本，请重新读取后修正。');
            }
            $payload = $this->normalize($actor, $novel, $input);
            $events = $this->events($novel, $payload);
            $report = app(StoryConsistencyValidator::class)->evaluate($payload, $events);
            $id = (string) DB::table('story_context_versions')->insertGetId(['novel_id' => $novel, 'version' => $version + 1, 'status' => 'building', 'command_key' => $key, 'request_hash' => $hash, 'payload' => NovelIntentService::canonical($payload), 'content_hash' => hash('sha256', NovelIntentService::canonical($payload)), 'report' => NovelIntentService::canonical($report), 'reason' => $input['reason'], 'actor_id' => $actor->id]);
            foreach ($payload['entries'] as $entry) {
                $time = $entry['time'];
                unset($entry['time']);
                DB::table('story_time_entries')->insert($entry + ['novel_id' => $novel, 'context_id' => $id, 'time_kind' => $time['kind'], 'clock' => $time['clock'], 'start_time' => $time['start'], 'end_time' => $time['end']]);
            }
            foreach (['dependencies' => 'event_dependencies', 'knowledge' => 'knowledge_facts', 'relationships' => 'relationships', 'rules' => 'consistency_rules'] as $field => $table) {
                foreach ($payload[$field] as $row) {
                    DB::table($table)->insert($row + ['novel_id' => $novel, 'context_id' => $id]);
                }
            }
            DB::table('story_context_versions')->where('id', $id)->update(['status' => 'sealed']);

            return $this->read($actor, $novel, $id);
        });
    }

    private function normalize(User $actor, string $novel, array $input): array
    {
        $this->keys($input, ['expected_version', 'reason', 'entries', 'dependencies', 'knowledge', 'relationships', 'rules']);
        $p = [];
        foreach (['entries', 'dependencies', 'knowledge', 'relationships', 'rules'] as $field) {
            $p[$field] = $input[$field] ?? [];
            if (! is_array($p[$field]) || ! array_is_list($p[$field]) || count($p[$field]) > 1000) {
                throw new NovelProblem('INVALID_CONTEXT', '各类条目必须为列表且最多一千条。', 422);
            }
        }
        if (! $p['entries']) {
            throw new NovelProblem('EMPTY_CONTEXT', '请先选择候选事件。', 422);
        }
        $ids = [];
        foreach ($p['entries'] as &$entry) {
            $this->keys($entry, ['event_id', 'time', 'activity', 'location_id', 'ability_id', 'ability_units', 'previous_owner_id', 'revival_rule_id']);
            Validator::make($entry, ['event_id' => 'required|string', 'time' => 'required|array', 'activity' => 'sometimes|boolean', 'ability_units' => 'nullable|integer|min:1|max:2147483647'])->validate();
            $event = $this->row('proposed_events', $novel, $entry['event_id']);
            if (isset($ids[$entry['event_id']])) {
                throw new NovelProblem('DUPLICATE_EVENT', '同一版本不能重复登记事件。', 422);
            }
            $ids[$entry['event_id']] = true;
            app(WorldService::class)->assertReference($actor, $novel, (string) $event->definition_version_id);
            $entry += ['activity' => false, 'location_id' => null, 'ability_id' => null, 'ability_units' => null, 'previous_owner_id' => null, 'revival_rule_id' => null];
            // action 是显式行动事件类型，不能通过取消勾选绕过生命约束。
            $entry['activity'] = (bool) $entry['activity'] || (in_array($event->fact_kind, ['fact', 'hidden_truth'], true) && $event->attribute === 'action');
            $entry['ability_units'] = $entry['ability_units'] === null ? null : (int) $entry['ability_units'];
            if ($entry['activity']) {
                $this->entity($actor, $novel, (string) $event->entity_id, 'character');
            }
            foreach (['location_id' => 'location', 'ability_id' => 'ability', 'previous_owner_id' => 'character'] as $field => $type) {
                if ($entry[$field] !== null) {
                    $this->entity($actor, $novel, $entry[$field], $type);
                }
            }
            if (($entry['ability_id'] === null) !== ($entry['ability_units'] === null) || ($entry['ability_id'] !== null && ! $entry['activity'])) {
                throw new NovelProblem('INVALID_ABILITY', '能力使用必须是明确的角色行动并填写消耗单位。', 422);
            }
            if ($entry['revival_rule_id'] !== null) {
                $this->rule($actor, $novel, $entry['revival_rule_id']);
            }
            // 结构化状态值中的实体引用也必须同书、类型正确且已批准。
            $value = json_decode($event->value, true);
            if (in_array($event->fact_kind, ['fact', 'hidden_truth'], true) && in_array($event->attribute, ['location_id', 'owner_id'], true)) {
                $this->entity($actor, $novel, (string) $event->entity_id, $event->attribute === 'owner_id' ? 'item' : 'character');
                $this->entity($actor, $novel, $value, $event->attribute === 'owner_id' ? 'character' : 'location');
            }
            if (in_array($event->fact_kind, ['fact', 'hidden_truth'], true) && $event->attribute === 'life_status') {
                $this->entity($actor, $novel, (string) $event->entity_id, 'character');
                if (! in_array($value, ['alive', 'dead'], true)) {
                    throw new NovelProblem('INVALID_LIFE_STATE', '生命状态必须明确为 alive 或 dead。', 422);
                }
            }
            $entry['time'] = $this->time($entry['time']);
        }
        unset($entry);
        // D01 负责验证事件链、哈希及证据；未知时间不能绕过这些来源检查。
        app(StoryStateService::class)->replay($actor, $novel, array_map('strval', array_keys($ids)), PHP_INT_MAX);
        $ref = function ($id) use ($ids): void {
            if (! is_string($id) || ! isset($ids[$id])) {
                throw new NovelProblem('MISSING_EVENT', '引用事件必须在同一候选版本中。', 422);
            }
        };
        foreach ($p['dependencies'] as $d) {
            $this->keys($d, ['predecessor_id', 'successor_id', 'relation_type']);
            Validator::make($d, ['predecessor_id' => 'required|string', 'successor_id' => 'required|string', 'relation_type' => ['required', Rule::in(['before', 'depends_on', 'contains', 'simultaneous'])]])->validate();
            $ref($d['predecessor_id']);
            $ref($d['successor_id']);
        }
        $this->acyclic($p['dependencies']);
        foreach ($p['knowledge'] as &$k) {
            $this->keys($k, ['knower_id', 'fact_event_id', 'acquired_event_id', 'method', 'source_knower_id']);
            Validator::make($k, ['knower_id' => 'required|string', 'fact_event_id' => 'required|string', 'acquired_event_id' => 'required|string', 'method' => ['required', Rule::in(['witness', 'told', 'inference'])]])->validate();
            $ref($k['fact_event_id']);
            $ref($k['acquired_event_id']);
            $this->entity($actor, $novel, $k['knower_id'], 'character');
            $k += ['source_knower_id' => null];
            if (($k['method'] === 'told') !== ($k['source_knower_id'] !== null) || $k['source_knower_id'] === $k['knower_id']) {
                throw new NovelProblem('INVALID_KNOWLEDGE_PATH', '转述必须明确其他知情角色，其他途径不能携带转述者。', 422);
            }
            if ($k['source_knower_id'] !== null) {
                $this->entity($actor, $novel, $k['source_knower_id'], 'character');
            }
        }
        unset($k);
        foreach ($p['relationships'] as $r) {
            $this->keys($r, ['event_id', 'from_entity_id', 'to_entity_id', 'relation_type', 'state']);
            Validator::make($r, ['event_id' => 'required|string', 'from_entity_id' => 'required|string', 'to_entity_id' => 'required|string|different:from_entity_id', 'relation_type' => 'required|string|max:80', 'state' => 'required|string|max:2000'])->validate();
            $ref($r['event_id']);
            $this->entity($actor, $novel, $r['from_entity_id'], 'character');
            $this->entity($actor, $novel, $r['to_entity_id'], 'character');
        }
        foreach ($p['rules'] as &$r) {
            $this->keys($r, ['rule_version_id', 'kind', 'subject_id', 'target_id', 'limit_value']);
            Validator::make($r, ['rule_version_id' => 'required|string', 'kind' => ['required', Rule::in(['travel', 'ability', 'revival'])], 'subject_id' => 'required|string', 'limit_value' => 'required|integer|min:0|max:1000000000000'])->validate();
            $r += ['target_id' => null];
            $r['limit_value'] = (int) $r['limit_value'];
            $this->rule($actor, $novel, $r['rule_version_id']);
            $this->entity($actor, $novel, $r['subject_id'], $r['kind'] === 'travel' ? 'location' : 'character');
            if ($r['kind'] !== 'revival') {
                $this->entity($actor, $novel, $r['target_id'], $r['kind'] === 'travel' ? 'location' : 'ability');
            } elseif ($r['target_id'] !== null || $r['limit_value'] !== 1) {
                throw new NovelProblem('INVALID_RULE', '复活解释必须明确启用且只绑定角色。', 422);
            }
        }
        unset($r);

        foreach (['dependencies' => ['predecessor_id', 'successor_id', 'relation_type'], 'knowledge' => ['knower_id', 'fact_event_id', 'acquired_event_id'], 'relationships' => ['event_id', 'from_entity_id', 'to_entity_id', 'relation_type'], 'rules' => ['kind', 'subject_id', 'target_id']] as $field => $keys) {
            $seen = [];
            foreach ($p[$field] as $row) {
                $identity = NovelIntentService::canonical(array_intersect_key($row, array_flip($keys)));
                if (isset($seen[$identity])) {
                    throw new NovelProblem('DUPLICATE_CONTEXT_ROW', '同一版本存在重复或互相覆盖的条目，请修改原条目。', 422);
                }
                $seen[$identity] = true;
            }
        }

        return $p;
    }

    private function time(array $t): array
    {
        $this->keys($t, ['kind', 'clock', 'start', 'end']);
        Validator::make($t, ['kind' => ['required', Rule::in(['unknown', 'exact', 'range'])]])->validate();
        if ($t['kind'] === 'unknown') {
            if (array_filter(array_diff_key($t, ['kind' => true]), fn ($v) => $v !== null)) {
                throw new NovelProblem('INVALID_TIME', '未知时间不能携带猜测时刻。', 422);
            }

            return ['kind' => 'unknown', 'clock' => null, 'start' => null, 'end' => null];
        }
        Validator::make($t, ['clock' => ['required', Rule::in(['utc', 'relative_seconds'])], 'start' => 'required', 'end' => 'required'])->validate();
        foreach (['start', 'end'] as $field) {
            if ($t['clock'] === 'utc' && is_string($t[$field]) && str_contains($t[$field], 'T')) {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $t[$field]);
                $errors = \DateTimeImmutable::getLastErrors();
                if (! $date || ($errors && ($errors['warning_count'] || $errors['error_count'])) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $t[$field])) {
                    throw new NovelProblem('INVALID_TIME', '绝对时间必须为带时区的有效 ISO8601 秒级日期。', 422);
                }
                $t[$field] = $date->getTimestamp();
            }
            Validator::make([$field => $t[$field]], [$field => 'required|integer|between:-1000000000000,1000000000000'])->validate();
            $t[$field] = (int) $t[$field];
        }
        if ($t['start'] > $t['end'] || ($t['kind'] === 'exact' && $t['start'] !== $t['end'])) {
            throw new NovelProblem('INVALID_TIME', '精确时间上下界须相等，区间须按先后排列。', 422);
        }

        return $t;
    }

    private function acyclic(array $edges): void
    {
        $graph = [];
        $seen = [];
        $visiting = [];
        foreach ($edges as $e) {
            $graph[$e['predecessor_id']][] = $e['successor_id'];
        }
        $visit = function ($id) use (&$visit, &$seen, &$visiting, $graph): void {
            if (isset($visiting[$id])) {
                throw new NovelProblem('DEPENDENCY_CYCLE', '事件依赖不能成环。', 422);
            }
            if (isset($seen[$id])) {
                return;
            }
            $visiting[$id] = true;
            foreach ($graph[$id] ?? [] as $next) {
                $visit($next);
            }
            unset($visiting[$id]);
            $seen[$id] = true;
        };
        foreach (array_keys($graph) as $id) {
            $visit($id);
        }
    }

    public function read(User $actor, string $novel, string $id): array
    {
        app(NovelIntentService::class)->novel($actor, $novel);
        $row = $this->row('story_context_versions', $novel, $id);
        if ($row->status !== 'sealed') {
            throw new NovelProblem('NOT_FOUND', '版本尚未封存。', 404);
        }
        $p = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
        if (! hash_equals($row->content_hash, hash('sha256', NovelIntentService::canonical($p)))) {
            throw new NovelProblem('INVALID_CONTEXT_HASH', '候选版本哈希不匹配。');
        }

        return ['id' => $id, 'novel_id' => $novel, 'version' => $row->version, 'scope' => 'candidate', 'canonical' => false, 'content_hash' => $row->content_hash, 'payload' => $p, 'events' => array_values($this->events($novel, $p)), 'report' => json_decode($row->report, true), 'reason' => $row->reason];
    }

    public function listing(User $actor, string $novel): array
    {
        app(NovelIntentService::class)->novel($actor, $novel);

        return DB::table('story_context_versions')->where('novel_id', $novel)->where('status', 'sealed')->orderByDesc('version')->limit(50)->get(['id', 'version', 'reason', 'created_at'])->map(fn ($v) => ['id' => (string) $v->id, 'version' => $v->version, 'reason' => $v->reason, 'created_at' => Carbon::parse($v->created_at)->toIso8601String()])->all();
    }

    public function pov(User $actor, string $novel, string $id, string $knower, int $chapter): array
    {
        $r = $this->read($actor, $novel, $id);
        if ($this->row('entities', $novel, $knower)->type !== 'character') {
            throw new NovelProblem('INVALID_KNOWER', 'POV 必须指定角色。', 422);
        }
        if ($chapter < 1) {
            throw new NovelProblem('INVALID_CHAPTER', '章序必须为正整数。', 422);
        }

        // 历史查询重新按叙述章位计算，未来修正及未来来源不会提前泄露。
        return app(StoryConsistencyValidator::class)->pov($r['payload'], $this->events($novel, $r['payload']), $knower, $chapter);
    }

    private function events(string $novel, array $p): array
    {
        $events = [];
        foreach ($p['entries'] as $entry) {
            $e = (array) $this->row('proposed_events', $novel, $entry['event_id']);
            foreach ($e as $field => $value) {
                if ($field === 'id' || str_ends_with($field, '_id')) {
                    $e[$field] = $value === null ? null : (string) $value;
                }
            }
            $e['created_at'] = Carbon::parse($e['created_at'])->toIso8601String();
            $e['value'] = json_decode($e['value'], true);
            $e['story_time'] = json_decode($e['story_time'], true);
            $e['evidence'] = (array) $this->row('evidence_spans', $novel, $e['evidence_id']);
            $e['evidence']['created_at'] = Carbon::parse($e['evidence']['created_at'])->toIso8601String();
            foreach (['id', 'novel_id', 'source_version_id'] as $field) {
                $e['evidence'][$field] = (string) $e['evidence'][$field];
            }
            $events[$e['id']] = $e + ['entry' => $entry];
        }
        uasort($events, fn ($a, $b) => [$a['chapter_sequence'], $a['narrative_order']] <=> [$b['chapter_sequence'], $b['narrative_order']]);

        return $events;
    }

    private function rule(User $actor, string $novel, mixed $id): void
    {
        $v = $this->row('entity_definition_versions', $novel, $id);
        $e = $this->row('entities', $novel, (string) $v->entity_id);
        if ($e->type !== 'rule') {
            throw new NovelProblem('INVALID_RULE', '限制必须关联世界规则的批准版本。', 422);
        }
        app(WorldService::class)->assertReference($actor, $novel, $id);
    }

    private function entity(User $actor, string $novel, mixed $id, string $type): void
    {
        $e = $this->row('entities', $novel, $id);
        if ($e->type !== $type || ! $e->approved_version_id) {
            throw new NovelProblem('INVALID_ENTITY_TYPE', '关联实体类型或批准状态不符合要求。', 422);
        }
        app(WorldService::class)->assertReference($actor, $novel, (string) $e->approved_version_id);
    }

    private function row(string $table, string $novel, mixed $id): object
    {
        if (! is_string($id) || ! preg_match('/^[1-9][0-9]{0,18}$/D', $id) || (strlen($id) === 19 && strcmp($id, '9223372036854775807') > 0)) {
            throw new NovelProblem('NOT_FOUND', '记录不存在。', 404);
        }

        return DB::table($table)->where('novel_id', $novel)->where('id', $id)->first() ?? throw new NovelProblem('NOT_FOUND', '记录不存在或不属于本书。', 404);
    }

    private function keys(mixed $row, array $allowed): void
    {
        if (! is_array($row) || array_diff(array_keys($row), $allowed)) {
            throw new NovelProblem('INVALID_CONTEXT', '候选配置包含不支持的字段。', 422);
        }
    }
}
