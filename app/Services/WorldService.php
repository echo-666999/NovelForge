<?php

namespace App\Services;

use App\Exceptions\NovelProblem;
use App\Models\CharacterProfile;
use App\Models\EntityAlias;
use App\Models\EntityApproval;
use App\Models\EntityDefinitionVersion;
use App\Models\EntityInitialRelation;
use App\Models\EntityIntroductionPolicy;
use App\Models\Novel;
use App\Models\PremiseVersion;
use App\Models\User;
use App\Models\WorldEntity;
use App\Models\WorldRule;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class WorldService
{
    public const POLICY_SCHEMA = 'minor-v1';

    public const POLICY_CONSTRAINTS = ['type' => 'character', 'role' => 'extra', 'criticality' => 'minor', 'allow_main_plot' => false, 'allow_abilities' => false, 'allow_rules' => false, 'allow_relations' => false];

    public const TYPES = ['character', 'location', 'organization', 'item', 'ability', 'rule'];

    public const PROFILE = ['role', 'identity', 'appearance', 'values', 'goal', 'bottom_line', 'voice', 'abilities', 'limitations', 'faction'];

    public function execute(User $actor, string $id, string $command, array $data, string $key): array
    {
        app(NovelIntentService::class)->authorize($actor);
        Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D']])->validate();
        $hash = hash('sha256', NovelIntentService::canonical($data));
        try {
            return DB::transaction(function () use ($actor, $id, $command, $data, $key, $hash) {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [NovelIntentService::canonical([$actor->id, $id, 'world.'.$command, $key])]);
                $novel = app(NovelIntentService::class)->novel($actor, $id, true);
                $where = ['actor_id' => $actor->id, 'novel_scope' => $id, 'command' => 'world.'.$command, 'key' => $key];
                if ($old = DB::table('novel_commands')->where($where)->first()) {
                    if ($old->request_hash !== $hash) {
                        throw new NovelProblem('IDEMPOTENCY_CONFLICT', '操作键已用于不同内容。');
                    }

                    return json_decode($old->response, true, flags: JSON_THROW_ON_ERROR);
                }
                Validator::make($data, ['expected_version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000'])->validate();
                if ($novel->row_version != (int) $data['expected_version']) {
                    throw new NovelProblem('STALE_STATE', '设定已变化，请刷新后重试。');
                }
                if (! in_array($novel->status, ['draft', 'planning'])) {
                    throw new NovelProblem('INVALID_TRANSITION', '当前小说不可修改设定。');
                }
                $result = match ($command) {
                    'definition' => $this->saveDefinition($actor, $novel, $data),
                    'approve' => $this->approve($actor, $novel, $data, false),
                    'accept-minor' => $this->approve($actor, $novel, $data, true),
                    'policy' => $this->policy($actor, $novel, $data),
                    'merge-aliases' => $this->mergeAliases($actor, $novel, $data),
                    'retire' => $this->retire($novel, $data),
                    default => throw new NovelProblem('INVALID_TRANSITION', '不支持的设定操作。'),
                };
                $novel->row_version++;
                $novel->status = 'planning';
                $novel->save();
                $result['novel'] = $novel->fresh()->toArray();
                DB::table('novel_commands')->insert($where + ['request_hash' => $hash, 'response' => NovelIntentService::canonical($result), 'created_at' => now()]);

                return $result;
            });
        } catch (QueryException $e) {
            if (in_array($e->errorInfo[0] ?? null, ['23503', '23505', '23514'])) {
                throw new NovelProblem('DEFINITION_CONFLICT', '设定归属、版本或不可变历史约束冲突。');
            }throw $e;
        }
    }

    private function entity(string $novel, string $id): WorldEntity
    {
        $this->validId($id);
        $e = WorldEntity::where('novel_id', $novel)->find($id);
        if (! $e) {
            throw new NovelProblem('NOT_FOUND', '实体不存在。', 404);
        }

        return $e;
    }

    private function validId(string $id): void
    {
        if (! preg_match('/^[1-9][0-9]{0,18}$/D', $id) || (strlen($id) === 19 && strcmp($id, '9223372036854775807') > 0)) {
            throw new NovelProblem('NOT_FOUND', '版本或实体不存在。', 404);
        }
    }

    private function definition(string $novel, string $id): EntityDefinitionVersion
    {
        $this->validId($id);
        $v = EntityDefinitionVersion::where('novel_id', $novel)->where('status', 'sealed')->find($id);
        if (! $v) {
            throw new NovelProblem('NOT_FOUND', '设定版本不存在。', 404);
        }

        return $v;
    }

    private function saveDefinition(User $actor, Novel $novel, array $data, ?string $aliasOrigin = null): array
    {
        $v = Validator::make($data, [
            'entity_id' => 'nullable|integer|min:1', 'type' => ['required', Rule::in(self::TYPES)], 'name' => 'required|string|max:255', 'description' => 'required|string|max:20000',
            'criticality' => ['required', Rule::in(['core', 'minor'])], 'source_version_id' => 'required|integer|min:1', 'source_quote' => 'required|string|max:20000',
            'aliases' => 'sometimes|array|max:50', 'aliases.*' => 'required|string|max:255', 'conflict' => 'nullable|string|max:20000',
            'profile' => 'sometimes|array:'.implode(',', self::PROFILE), ...array_fill_keys(array_map(fn ($k) => 'profile.'.$k, self::PROFILE), 'nullable|string|max:10000'),
            'rule' => 'nullable|string|max:20000', 'scope' => 'nullable|string|max:10000', 'introduced_chapter' => 'required|integer|min:1|max:'.$novel->target_chapters,
            'affects_main_plot' => 'required|boolean', 'relations' => 'sometimes|array|max:50',
            'relations.*' => 'required|array:target_definition_version_id,relation_type,description', 'relations.*.target_definition_version_id' => 'required|integer|min:1',
            'relations.*.relation_type' => 'required|string|max:255', 'relations.*.description' => 'required|string|max:10000',
            'current_state' => 'prohibited', 'alive' => 'prohibited', 'location_id' => 'prohibited', 'knowledge' => 'prohibited', 'state_version' => 'prohibited',
        ])->validate();
        $source = PremiseVersion::where('novel_id', $novel->id)->find($v['source_version_id']);
        if (! $source) {
            throw new NovelProblem('NOT_FOUND', '同书原始来源不存在。', 404);
        }
        // Quotes must belong to one original text field, never to serialized JSON metadata.
        $found = false;
        foreach ($source->input as $text) {
            if (is_string($text) && str_contains($text, $v['source_quote'])) {
                $found = true;
                break;
            }
        }
        if (! $found) {
            throw new NovelProblem('EVIDENCE_INVALID', '来源引用必须是原始输入文本中的准确片段。', 422);
        }
        $v['profile'] = array_filter($v['profile'] ?? [], fn ($x) => $x !== null && $x !== '');
        if ($v['type'] === 'character') {
            Validator::make($v, ['profile.role' => ['required', Rule::in(['primary', 'supporting', 'extra'])], 'profile.identity' => 'required|string', 'profile.voice' => 'required|string', 'profile.limitations' => 'required|string'])->validate();
        } elseif (! empty($v['profile'])) {
            throw new NovelProblem('VALIDATION_FAILED', '只有人物可以填写人物卡。', 422);
        }
        if ($v['type'] === 'rule' && (empty($v['rule']) || empty($v['scope']))) {
            throw new NovelProblem('VALIDATION_FAILED', '世界规则必须填写规则及适用范围。', 422);
        }
        if (! empty($v['rule']) && empty($v['scope'])) {
            throw new NovelProblem('VALIDATION_FAILED', '规则缺少适用范围。', 422);
        }
        $entity = empty($v['entity_id']) ? new WorldEntity(['novel_id' => $novel->id, 'type' => $v['type'], 'name' => $v['name']]) : $this->entity($novel->id, (string) $v['entity_id']);
        if ($entity->retired || $entity->type !== $v['type']) {
            throw new NovelProblem('INVALID_TRANSITION', '停用实体不可修改，稳定实体不能变更类型。');
        }
        $aliases = [];
        foreach ($v['aliases'] ?? [] as $alias) {
            $normalized = mb_strtolower(trim($alias), 'UTF-8');
            if ($normalized === '') {
                throw new NovelProblem('VALIDATION_FAILED', '别名不能为空。', 422);
            }$aliases[$normalized] = $alias;
        }
        $v['aliases'] = array_values($aliases);
        $v['relations'] = $v['relations'] ?? [];
        $v['profile'] = $v['profile'] ?? [];
        foreach ($v['relations'] as &$rel) {
            $target = $this->definition($novel->id, (string) $rel['target_definition_version_id']);
            if ($entity->exists && $target->entity_id === $entity->id) {
                throw new NovelProblem('VALIDATION_FAILED', '初始关系不能指向自身。', 422);
            }$rel['target_definition_version_id'] = $target->id;
        }unset($rel);
        if ($entity->exists) {
            $entity->row_version++;
        }$entity->name = $v['name'];
        $entity->save();
        unset($v['entity_id']);
        $v['source_version_id'] = (string) $v['source_version_id'];
        $v['introduced_chapter'] = (int) $v['introduced_chapter'];
        $v['affects_main_plot'] = (bool) $v['affects_main_plot'];
        $v['conflict'] = $v['conflict'] ?? '';
        $v['rule'] = $v['rule'] ?? '';
        $v['scope'] = $v['scope'] ?? '';
        $version = EntityDefinitionVersion::create(['novel_id' => $novel->id, 'entity_id' => $entity->id, 'version' => (EntityDefinitionVersion::where('entity_id', $entity->id)->max('version') ?? 0) + 1, 'source_version_id' => $v['source_version_id'], 'source_quote' => $v['source_quote'], 'alias_origin_version_id' => $aliasOrigin, 'status' => 'building', 'definition' => $v, 'content_hash' => hash('sha256', NovelIntentService::canonical($v)), 'reason' => $data['reason'], 'actor_id' => $actor->id, 'created_at' => now()]);
        $base = ['novel_id' => $novel->id, 'entity_definition_version_id' => $version->id];
        foreach ($aliases as $normalized => $alias) {
            EntityAlias::create($base + ['alias' => $alias, 'normalized_alias' => $normalized]);
        }
        if ($v['profile']) {
            CharacterProfile::create($base + ['profile' => $v['profile']]);
        }
        if ($v['rule'] !== '') {
            WorldRule::create($base + ['rule' => $v['rule'], 'scope' => $v['scope']]);
        }
        foreach ($v['relations'] as $rel) {
            EntityInitialRelation::create($base + $rel);
        }
        $version->status = 'sealed';
        $version->save();
        $entity->current_version_id = $version->id;
        $entity->save();

        return ['entity' => $entity->fresh()->toArray(), 'definition' => $this->readVersion($actor, $novel->id, $version->id)];
    }

    private function approve(User $actor, Novel $novel, array $data, bool $automatic): array
    {
        $a = Validator::make($data, ['definition_version_id' => 'required|integer|min:1', 'policy_version_id' => ($automatic ? 'required' : 'prohibited').'|integer|min:1'])->validate();
        $version = $this->definition($novel->id, (string) $a['definition_version_id']);
        $e = $this->entity($novel->id, $version->entity_id);
        $d = $version->definition;
        if ($e->retired || $e->current_version_id !== $version->id) {
            throw new NovelProblem('STALE_DEFINITION', '只能批准未停用实体的当前候选版本。');
        }
        if (EntityApproval::where('entity_definition_version_id', $version->id)->exists()) {
            throw new NovelProblem('INVALID_TRANSITION', '此版本已经批准。');
        }
        if (! empty($d['conflict'])) {
            throw new NovelProblem('DEFINITION_CONFLICT', '请先追加解决规则冲突的新版本，再批准。');
        }
        foreach ($d['relations'] as $rel) {
            $this->assertReference($actor, $novel->id, $rel['target_definition_version_id']);
        }
        $policy = null;
        if ($automatic) {
            $policy = EntityIntroductionPolicy::where('novel_id', $novel->id)->latest('version')->first();
            if (! $policy || $policy->schema_version !== self::POLICY_SCHEMA || NovelIntentService::canonical($policy->constraints) !== NovelIntentService::canonical(self::POLICY_CONSTRAINTS) || $policy->id !== (string) $a['policy_version_id'] || ! $policy->enabled) {
                throw new NovelProblem('POLICY_BLOCKED', '自动接纳未开启或策略已过期。');
            }
            if ($d['type'] !== 'character' || $d['criticality'] !== 'minor' || ($d['profile']['role'] ?? '') !== 'extra' || $d['affects_main_plot'] || ! empty($d['profile']['abilities']) || $d['relations'] || $d['rule'] !== '' || $d['introduced_chapter'] < $policy->start_chapter || $d['introduced_chapter'] > $policy->end_chapter || $d['introduced_chapter'] > $novel->target_chapters) {
                throw new NovelProblem('POLICY_BLOCKED', '仅允许范围内无特殊能力、无关系或规则、无主线影响的非关键路人。');
            }
            if (EntityApproval::where('policy_version_id', $policy->id)->count() >= $policy->max_acceptances) {
                throw new NovelProblem('POLICY_LIMIT', '本策略版本的自动接纳数量已用完。');
            }
        }
        $approval = EntityApproval::create(['novel_id' => $novel->id, 'entity_definition_version_id' => $version->id, 'mode' => $automatic ? 'policy' : 'manual', 'policy_version_id' => $policy?->id, 'reason' => $data['reason'], 'actor_id' => $actor->id, 'created_at' => now()]);
        $e->approved_version_id = $version->id;
        $e->row_version++;
        $e->save();

        return ['approval' => $approval->toArray(), 'entity' => $e->fresh()->toArray(), 'canonical_state_changed' => false];
    }

    private function policy(User $actor, Novel $novel, array $data): array
    {
        $v = Validator::make($data, ['enabled' => 'required|boolean', 'start_chapter' => 'required|integer|min:1', 'end_chapter' => 'required|integer|gte:start_chapter|max:'.$novel->target_chapters, 'max_acceptances' => 'required|integer|min:1|max:1000'])->validate();
        $v['enabled'] = (bool) $v['enabled'];
        foreach (['start_chapter', 'end_chapter', 'max_acceptances'] as $k) {
            $v[$k] = (int) $v[$k];
        }
        $v['schema_version'] = self::POLICY_SCHEMA;
        $v['constraints'] = self::POLICY_CONSTRAINTS;
        $policy = EntityIntroductionPolicy::create($v + ['novel_id' => $novel->id, 'version' => (EntityIntroductionPolicy::where('novel_id', $novel->id)->max('version') ?? 0) + 1, 'content_hash' => hash('sha256', NovelIntentService::canonical($v)), 'reason' => $data['reason'], 'actor_id' => $actor->id, 'created_at' => now()]);

        return ['policy' => $policy->toArray()];
    }

    private function mergeAliases(User $actor, Novel $novel, array $data): array
    {
        $v = Validator::make($data, ['entity_id' => 'required|integer|min:1', 'from_definition_version_id' => 'required|integer|min:1'])->validate();
        $target = $this->entity($novel->id, (string) $v['entity_id']);
        $from = $this->definition($novel->id, (string) $v['from_definition_version_id']);
        if ($target->id === $from->entity_id) {
            throw new NovelProblem('VALIDATION_FAILED', '请选择另一个实体的别名候选。', 422);
        }
        if ($target->type !== $from->definition['type']) {
            throw new NovelProblem('VALIDATION_FAILED', '不同类型不能合并别名候选。', 422);
        }
        $current = $this->definition($novel->id, $target->current_version_id)->definition;
        $current['aliases'] = array_values(array_unique([...$current['aliases'], $from->definition['name'], ...$from->definition['aliases']]));

        return $this->saveDefinition($actor, $novel, $current + ['entity_id' => $target->id, 'reason' => $data['reason']], $from->id);
    }

    private function retire(Novel $novel, array $data): array
    {
        $v = Validator::make($data, ['entity_id' => 'required|integer|min:1'])->validate();
        $e = $this->entity($novel->id, (string) $v['entity_id']);
        if ($e->retired) {
            throw new NovelProblem('INVALID_TRANSITION', '实体已停用。');
        }$e->retired = true;
        $e->row_version++;
        $e->save();

        return ['entity' => $e->toArray(), 'canonical_state_changed' => false];
    }

    public function assertReference(User $actor, string $novel, string $version): array
    {
        app(NovelIntentService::class)->novel($actor, $novel);
        $v = $this->definition($novel, $version);
        $e = $this->entity($novel, $v->entity_id);
        if ($e->retired || ! EntityApproval::where('entity_definition_version_id', $v->id)->exists()) {
            throw new NovelProblem('UNAPPROVED_ENTITY', '该实体版本未批准或已停用，不得用于新的有效引用。', 422);
        }

        return ['entity_id' => $e->id, 'definition_version_id' => $v->id, 'content_hash' => $v->content_hash, 'definition_approved' => true, 'canonical_state_changed' => false];
    }

    public function readVersion(User $actor, string $novel, string $id): array
    {
        app(NovelIntentService::class)->novel($actor, $novel);
        $v = $this->definition($novel, $id);
        if (! hash_equals($v->content_hash, hash('sha256', NovelIntentService::canonical($v->definition)))) {
            throw new NovelProblem('SNAPSHOT_INVALID', '设定快照哈希不匹配。');
        }

        return $v->toArray() + ['approval' => EntityApproval::where('entity_definition_version_id', $v->id)->first()?->toArray(), 'canonical_state' => null];
    }

    public function detail(User $actor, string $novel, string $id): array
    {
        app(NovelIntentService::class)->novel($actor, $novel);
        $e = $this->entity($novel, $id);

        return ['entity' => $e->toArray(), 'versions' => EntityDefinitionVersion::where('entity_id', $id)->where('status', 'sealed')->orderByDesc('version')->get()->map(fn ($v) => $this->readVersion($actor, $novel, $v->id))->all(),
            'references' => EntityInitialRelation::where('novel_id', $novel)->whereIn('target_definition_version_id', EntityDefinitionVersion::where('entity_id', $id)->select('id'))->get()->toArray(), 'alias_merge_references' => EntityDefinitionVersion::where('novel_id', $novel)->whereIn('alias_origin_version_id', EntityDefinitionVersion::where('entity_id', $id)->select('id'))->get(['id', 'entity_id', 'version', 'alias_origin_version_id'])->toArray(), 'reference_scope' => 'initial_definition_relations_and_alias_lineage_only', 'canonical_state' => null];
    }

    public function listing(User $actor, string $novel, array $filters = []): array
    {
        app(NovelIntentService::class)->novel($actor, $novel);
        $v = Validator::make($filters, ['type' => ['nullable', Rule::in(self::TYPES)], 'status' => ['nullable', Rule::in(['candidate', 'approved', 'conflicting', 'retired'])], 'q' => 'nullable|string|max:255', 'role' => ['nullable', Rule::in(['primary', 'supporting', 'extra'])], 'faction' => 'nullable|string|max:255', 'unreferenced' => 'nullable|boolean', 'limit' => 'sometimes|integer|min:1|max:100', 'cursor' => 'nullable|string|max:2000'])->validate();
        $query = WorldEntity::where('novel_id', $novel)->whereNotNull('current_version_id');
        if (! empty($v['type'])) {
            $query->where('type', $v['type']);
        }
        if (! empty($v['q'])) {
            $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v['q']).'%';
            $query->where(function ($q) use ($needle) {
                $q->where('name', 'ilike', $needle)->orWhereIn('current_version_id', EntityAlias::where('alias', 'ilike', $needle)->select('entity_definition_version_id'));
            });
        }
        if (! empty($v['status'])) {
            match ($v['status']) {
                'retired' => $query->where('retired', true),
                'approved' => $query->where('retired', false)->whereColumn('current_version_id', 'approved_version_id'),
                'candidate' => $query->where('retired', false)->whereRaw('current_version_id IS DISTINCT FROM approved_version_id')->whereIn('current_version_id', EntityDefinitionVersion::whereRaw("COALESCE(definition->>'conflict','') = ''")->select('id')),
                'conflicting' => $query->where('retired', false)->whereIn('current_version_id', EntityDefinitionVersion::whereRaw("COALESCE(definition->>'conflict','') <> ''")->select('id')),
            };
        }
        foreach (['role', 'faction'] as $key) {
            if (! empty($v[$key])) {
                $query->whereIn('current_version_id', CharacterProfile::where('profile->'.$key, $v[$key])->select('entity_definition_version_id'));
            }
        }
        if (! empty($v['unreferenced'])) {
            $query->whereNotIn('id', EntityDefinitionVersion::whereIn('id', EntityInitialRelation::select('target_definition_version_id'))->select('entity_id'));
        }
        try {
            $cursor = empty($v['cursor']) ? null : Cursor::fromEncoded($v['cursor']);
            if (! empty($v['cursor'])) {
                if (! $cursor) {
                    throw new \InvalidArgumentException;
                }$this->validId((string) ($cursor->toArray()['id'] ?? ''));
            }
        } catch (\Throwable) {
            throw new NovelProblem('VALIDATION_FAILED', '无效游标。', 422);
        }
        $page = $query->orderBy('id')->cursorPaginate($v['limit'] ?? 20, ['*'], 'cursor', $cursor);

        return ['items' => $page->getCollection()->map(function ($e) use ($actor, $novel) {
            $current = $this->readVersion($actor, $novel, $e->current_version_id);

            return $e->toArray() + ['current_definition' => $current, 'display_status' => $e->retired ? 'retired' : (! empty($current['definition']['conflict']) ? 'conflicting' : ($e->approved_version_id === $e->current_version_id ? 'approved' : 'candidate')), 'initial_reference_count' => EntityInitialRelation::whereIn('target_definition_version_id', EntityDefinitionVersion::where('entity_id', $e->id)->select('id'))->count(), 'canonical_reference_count' => null];
        })->all(), 'next_cursor' => $page->nextCursor()?->encode(), 'policies' => EntityIntroductionPolicy::where('novel_id', $novel)->orderByDesc('version')->get()->toArray(), 'canonical_state' => null];
    }
}
