<?php

namespace App\Services;

use App\Exceptions\NovelProblem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** D01 候选事件与回放服务：不推进正式基线，不接收摘要作为事实来源。 */
final class StoryStateService
{
    public const KINDS = ['fact', 'belief', 'rumor', 'lie', 'inference', 'hidden_truth'];

    // 正文只规范化换行；不裁剪空白、不做 Unicode 合成或标点替换。
    public static function normalize(string $body): string
    {
        if (! mb_check_encoding($body, 'UTF-8') || str_contains($body, "\0")) {
            throw new NovelProblem('INVALID_BODY', '正文必须为不含空字节的有效 UTF-8。', 422);
        }

        return str_replace(["\r\n", "\r"], "\n", $body);
    }

    private static function encode(mixed $value): string
    {
        // 仅接收已解析的标量与数组，拒绝对象序列化绕过键排序或浮点检查。
        $check = function ($v) use (&$check): void {
            if (is_array($v)) {
                foreach ($v as $child) {
                    $check($child);
                }
            } elseif (! is_null($v) && ! is_scalar($v)) {
                throw new NovelProblem('INVALID_EVENT', '状态值只能是 JSON 标量或数组。', 422);
            }
        };
        $check($value);

        return NovelIntentService::canonical($value);
    }

    // 旧产物仍严格绑定不可变文本；E03章节正文使用下方独立的registerChapter来源入口。
    public function registerArtifact(User $actor, string $novel, string $artifact): string
    {
        return DB::transaction(function () use ($actor, $novel, $artifact) {
            app(NovelIntentService::class)->novel($actor, $novel, true);
            $a = $this->row('generation_artifacts', $novel, $artifact);
            $content = json_decode($a->content, true, 512, JSON_THROW_ON_ERROR);
            if (! is_string($content['text'] ?? null) || ! hash_equals($a->content_hash, hash('sha256', self::encode($content)))) {
                throw new NovelProblem('INVALID_SOURCE', '来源必须有完整文本且通过产物哈希复核。', 422);
            }
            $existing = DB::table('source_documents')->where('artifact_id', $artifact)->value('id');
            if ($existing) {
                return (string) $existing;
            }
            $body = self::normalize($content['text']);

            return (string) DB::table('source_documents')->insertGetId(['novel_id' => $novel, 'run_id' => $a->run_id, 'artifact_id' => $artifact, 'kind' => 'artifact', 'body' => $body, 'source_hash' => hash('sha256', $body), 'normalization' => 'body-lf-utf8-sha256-v1']);
        });
    }

    /** E03 绑定实际章节候选，绝不把摘要或重新生成的文本充作原文。 */
    public function registerChapter(User $actor, string $novel, string $version): string
    {
        return DB::transaction(function () use ($actor, $novel, $version) {
            app(NovelIntentService::class)->novel($actor, $novel, true);
            $v = app(DraftingService::class)->read($actor, $novel, 'chapter', $version);
            if ($id = DB::table('source_documents')->where('novel_id', $novel)->where('chapter_version_id', $version)->value('id')) {
                return (string) $id;
            }

            return (string) DB::table('source_documents')->insertGetId(['novel_id' => $novel, 'chapter_version_id' => $version, 'kind' => 'chapter', 'body' => $v['body'], 'source_hash' => $v['body_hash'], 'normalization' => 'body-lf-utf8-sha256-v1']);
        });
    }

    public function evidence(User $actor, string $novel, string $source, string $hash, int $start, int $end, string $quote): string
    {
        app(NovelIntentService::class)->novel($actor, $novel);
        $s = $this->row('source_documents', $novel, $source);
        if (! hash_equals($s->source_hash, $hash) || ! hash_equals($hash, hash('sha256', $s->body)) || $start < 0 || $end <= $start || $end > mb_strlen($s->body, 'UTF-8') || mb_substr($s->body, $start, $end - $start, 'UTF-8') !== $quote) {
            throw new NovelProblem('INVALID_EVIDENCE', '来源哈希、码点范围或原文引文不匹配。', 422);
        }

        return (string) DB::table('evidence_spans')->insertGetId(['novel_id' => $novel, 'source_version_id' => $source, 'source_hash' => $hash, 'start_offset' => $start, 'end_offset' => $end, 'quote' => $quote, 'offset_unit' => 'unicode_codepoint']);
    }

    // 候选和补丁同事务追加；修正必须另建事件，不修改旧提取结果。
    public function propose(User $actor, string $novel, array $input): string
    {
        $rules = ['evidence_id' => 'required|string|regex:/^[1-9][0-9]{0,18}$/D', 'definition_version_id' => 'required|string|regex:/^[1-9][0-9]{0,18}$/D', 'holder_id' => 'nullable|string|regex:/^[1-9][0-9]{0,18}$/D', 'fact_kind' => ['required', Rule::in(self::KINDS)], 'action' => ['required', Rule::in(['set', 'revoke', 'replace'])], 'supersedes_id' => 'nullable|string|regex:/^[1-9][0-9]{0,18}$/D', 'chapter_sequence' => 'required|integer|min:1', 'narrative_order' => 'required|integer|min:1', 'attribute' => 'required|string|regex:/^[a-z][a-z0-9_]{0,79}$/D', 'confidence' => ['required', Rule::in(['confirmed', 'uncertain'])], 'story_time' => 'present|array', 'reason' => 'required|string|max:2000', 'value' => 'present'];
        if (array_diff(array_keys($input), array_keys($rules))) {
            throw new NovelProblem('INVALID_EVENT', '事件包含未声明字段。', 422);
        }
        $d = Validator::make($input, $rules)->validate();
        // 标准化可选字段和整数类型，避免数据库类型转换改变回放哈希。
        $d += ['holder_id' => null, 'supersedes_id' => null];
        $d['chapter_sequence'] = (int) $d['chapter_sequence'];
        $d['narrative_order'] = (int) $d['narrative_order'];
        self::encode($d);

        return DB::transaction(function () use ($actor, $novel, $d) {
            app(NovelIntentService::class)->novel($actor, $novel, true);
            $ref = app(WorldService::class)->assertReference($actor, $novel, $d['definition_version_id']);
            $this->verifyEvidence($novel, $d['evidence_id']);
            $subjective = ! in_array($d['fact_kind'], ['fact', 'hidden_truth'], true);
            if ($subjective !== ($d['holder_id'] !== null)) {
                throw new NovelProblem('INVALID_HOLDER', '角色说法必须指定持有者；客观事实和隐藏真相不得指定持有者。', 422);
            }
            if ($d['holder_id'] !== null) {
                $holder = $this->row('entities', $novel, $d['holder_id']);
                if ($holder->type !== 'character' || ! $holder->approved_version_id) {
                    throw new NovelProblem('INVALID_HOLDER', '持有者必须是批准的角色。', 422);
                }
                app(WorldService::class)->assertReference($actor, $novel, (string) $holder->approved_version_id);
            }
            $d['entity_id'] = (string) $ref['entity_id'];
            if (($d['action'] === 'set') !== ($d['supersedes_id'] === null)) {
                throw new NovelProblem('INVALID_CORRECTION', '替代或撤销必须指向旧事件。', 422);
            }
            if ($d['supersedes_id']) {
                $old = $this->row('proposed_events', $novel, $d['supersedes_id']);
                if ($old->action === 'revoke' || [$old->chapter_sequence, $old->narrative_order] >= [$d['chapter_sequence'], $d['narrative_order']]) {
                    throw new NovelProblem('INVALID_CORRECTION', '只能修正叙述位置在前的有效事件。', 422);
                }
                foreach (['entity_id', 'holder_id', 'fact_kind', 'attribute'] as $field) {
                    if ((string) $old->$field !== (string) $d[$field]) {
                        throw new NovelProblem('INVALID_CORRECTION', '修正不能跨实体、持有者或事实类别。', 422);
                    }
                }
            }
            $hash = hash('sha256', self::encode($d));
            $id = (string) DB::table('proposed_events')->insertGetId(array_replace($d, ['value' => self::encode($d['value']), 'story_time' => self::encode($d['story_time'])]) + ['novel_id' => $novel, 'actor_id' => $actor->id, 'content_hash' => $hash]);
            $patch = $this->patch($d);
            DB::table('proposed_state_patches')->insert(['novel_id' => $novel, 'event_id' => $id, 'patch' => self::encode($patch), 'patch_hash' => hash('sha256', self::encode($patch))]);

            return $id;
        });
    }

    private function patch(array $event): array
    {
        return array_intersect_key($event, array_flip(['entity_id', 'holder_id', 'fact_kind', 'attribute', 'value', 'action', 'supersedes_id']));
    }

    // 只读取显式事件清单，按叙述位置重放；不读取可变摘要或已有投影。
    public function replay(User $actor, string $novel, array $eventIds, int $chapter): array
    {
        app(NovelIntentService::class)->novel($actor, $novel);
        if ($chapter < 1 || ! array_is_list($eventIds) || count($eventIds) > 10000 || count(array_unique($eventIds)) !== count($eventIds)) {
            throw new NovelProblem('INVALID_STREAM', '事件清单必须唯一且章序有效，单次最多一万条。', 422);
        }
        $events = [];
        foreach ($eventIds as $id) {
            if (! is_string($id)) {
                throw new NovelProblem('INVALID_STREAM', '事件标识必须为十进制字符串。', 422);
            }
            $e = $this->row('proposed_events', $novel, $id);
            $d = [];
            foreach (['evidence_id', 'definition_version_id', 'holder_id', 'fact_kind', 'action', 'supersedes_id', 'chapter_sequence', 'narrative_order', 'attribute', 'confidence', 'story_time', 'reason', 'value', 'entity_id'] as $key) {
                $d[$key] = in_array($key, ['value', 'story_time'], true) ? json_decode($e->$key, true, 512, JSON_THROW_ON_ERROR) : $e->$key;
                if (str_ends_with($key, '_id') && $d[$key] !== null) {
                    $d[$key] = (string) $d[$key];
                }
            }
            $p = DB::table('proposed_state_patches')->where('event_id', $id)->first();
            if (! hash_equals($e->content_hash, hash('sha256', self::encode($d))) || ! $p || self::encode(json_decode($p->patch, true)) !== self::encode($this->patch($d)) || ! hash_equals($p->patch_hash, hash('sha256', self::encode($this->patch($d))))) {
                throw new NovelProblem('INVALID_EVENT_HASH', '事件或补丁哈希复核失败。', 422);
            }
            $this->verifyEvidence($novel, $d['evidence_id']);
            $events[] = $d + ['id' => $id, 'content_hash' => $e->content_hash];
        }
        usort($events, fn ($a, $b) => [$a['chapter_sequence'], $a['narrative_order']] <=> [$b['chapter_sequence'], $b['narrative_order']]);
        $positions = [];
        $active = [];
        $pending = [];
        $local = 0;
        foreach ($events as $e) {
            $position = $e['chapter_sequence'].':'.$e['narrative_order'];
            if (isset($positions[$position])) {
                throw new NovelProblem('AMBIGUOUS_ORDER', '同一回放流的叙述位置不能重复。', 422);
            }
            $positions[$position] = true;
            if ($e['chapter_sequence'] > $chapter) {
                continue;
            }
            $local++;
            if ($e['confidence'] === 'uncertain') {
                $pending[] = $e['id'];

                continue;
            }
            if ($e['supersedes_id'] !== null) {
                if (! isset($active[$e['supersedes_id']])) {
                    throw new NovelProblem('INCOMPLETE_CORRECTION', '修正目标缺失、待确认或已被撤销替代。', 422);
                }
                unset($active[$e['supersedes_id']]);
            }
            if ($e['action'] !== 'revoke') {
                $active[$e['id']] = $e;
            }
        }
        $state = array_fill_keys(self::KINDS, []);
        foreach ($active as $e) {
            // 客观事实与角色说法使用独立容器，隐藏真相永不合并到客观层。
            $key = $e['entity_id'].':'.($e['holder_id'] ?? 'none').':'.$e['attribute'];
            $state[$e['fact_kind']][$key] = ['entity_id' => $e['entity_id'], 'holder_id' => $e['holder_id'], 'attribute' => $e['attribute'], 'value' => $e['value'], 'source_event_id' => $e['id'], 'evidence_id' => $e['evidence_id'], 'introduced_at' => $e['chapter_sequence'], 'story_time' => $e['story_time']];
        }
        $streamHash = hash('sha256', self::encode(array_map(fn ($e) => [$e['id'], $e['content_hash']], $events)));

        return ['scope' => 'candidate', 'canonical' => false, 'chapter_sequence' => $chapter, 'local_state_version' => $local, 'stream_hash' => $streamHash, 'event_ids' => array_column($events, 'id'), 'pending_event_ids' => $pending, 'state' => $state, 'state_hash' => hash('sha256', self::encode($state))];
    }

    // 每次从事件重建，在书行锁下原子替换派生缓存；不信任缓存哈希或内容。
    public function rebuild(User $actor, string $novel, array $eventIds, int $chapter): array
    {
        return DB::transaction(function () use ($actor, $novel, $eventIds, $chapter) {
            app(NovelIntentService::class)->novel($actor, $novel, true);
            $r = $this->replay($actor, $novel, $eventIds, $chapter);
            $key = ['novel_id' => $novel, 'stream_hash' => $r['stream_hash'], 'chapter_sequence' => $chapter];
            DB::table('state_snapshots')->where($key)->delete();
            $snapshot = DB::table('state_snapshots')->insertGetId($key + ['scope' => 'candidate', 'local_state_version' => $r['local_state_version'], 'event_ids' => self::encode($r['event_ids']), 'payload' => self::encode($r['state']), 'payload_hash' => $r['state_hash']]);
            $entities = [];
            foreach ($r['state'] as $kind => $values) {
                foreach ($values as $key => $v) {
                    $entities[$v['entity_id']][$kind][$key] = $v;
                }
            }
            foreach ($entities as $entity => $attributes) {
                DB::table('entity_state_versions')->insert(['novel_id' => $novel, 'snapshot_id' => $snapshot, 'entity_id' => $entity, 'attributes' => self::encode($attributes)]);
            }

            return $r + ['snapshot_id' => (string) $snapshot];
        });
    }

    // 提取结果绑定调用方明确指定的正文来源；新正文不能复用旧引文，即使字面相同。
    public function assertSource(User $actor, string $novel, array $eventIds, string $source, string $hash): void
    {
        app(NovelIntentService::class)->novel($actor, $novel);
        $current = $this->row('source_documents', $novel, $source);
        if (! hash_equals($current->source_hash, $hash)) {
            throw new NovelProblem('STALE_EXTRACTION', '当前来源哈希不匹配。', 422);
        }
        foreach ($eventIds as $id) {
            $event = $this->row('proposed_events', $novel, $id);
            $evidence = $this->row('evidence_spans', $novel, (string) $event->evidence_id);
            if ((string) $evidence->source_version_id !== $source || ! hash_equals($evidence->source_hash, $hash)) {
                throw new NovelProblem('STALE_EXTRACTION', '旧正文的提取事件不得重新绑定到新正文。', 422);
            }
            $this->verifyEvidence($novel, (string) $event->evidence_id);
        }
    }

    private function verifyEvidence(string $novel, string $id): void
    {
        $e = $this->row('evidence_spans', $novel, $id);
        $s = $this->row('source_documents', $novel, (string) $e->source_version_id);
        if ($e->source_hash !== $s->source_hash || hash('sha256', $s->body) !== $s->source_hash || $e->offset_unit !== 'unicode_codepoint' || $e->start_offset < 0 || $e->end_offset <= $e->start_offset || $e->end_offset > mb_strlen($s->body, 'UTF-8') || mb_substr($s->body, $e->start_offset, $e->end_offset - $e->start_offset, 'UTF-8') !== $e->quote) {
            throw new NovelProblem('INVALID_EVIDENCE', '持久证据与来源不匹配。', 422);
        }
    }

    private function row(string $table, string $novel, string $id): object
    {
        if (! preg_match('/^[1-9][0-9]{0,18}$/D', $id) || (strlen($id) === 19 && strcmp($id, '9223372036854775807') > 0)) {
            throw new NovelProblem('NOT_FOUND', '记录不存在。', 404);
        }

        return DB::table($table)->where('novel_id', $novel)->where('id', $id)->first() ?? throw new NovelProblem('NOT_FOUND', '记录不存在或不属于当前小说。', 404);
    }
}
