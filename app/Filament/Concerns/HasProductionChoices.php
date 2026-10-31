<?php

namespace App\Filament\Concerns;

use App\Services\NovelIntentService;
use App\Services\ReviewPolicyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 只读选项使用白名单表与当前授权书，页面选项不取代应用服务的来源有效性校验。 */
trait HasProductionChoices
{
    protected array $productionChoiceCache = [];

    public function productionChoices(string $kind): array
    {
        $book = (string) ($this->novelId ?? $this->novel ?? '');
        $key = ($this->executionMode ?? 'offline_fake').':'.$book.':'.$kind.':'.($this->version ?? '').':'.($this->review ?? '').':'.($this->selected ?? '');
        if (isset($this->productionChoiceCache[$key])) {
            return $this->productionChoiceCache[$key];
        }
        app(NovelIntentService::class)->authorize(auth()->user());
        if ($book !== '') {
            app(NovelIntentService::class)->novel(auth()->user(), $book);
        }
        if ($kind === 'scope') {
            return array_column($this->preview['rows'] ?? [], 'scope', 'scope');
        }
        if ($kind === 'book') {
            return DB::table('novels')->where('owner_id', auth()->id())->orderByDesc('id')->pluck('title', 'id')->all();
        }
        // 策略生命周期为追加记录，不能把计算状态当成策略表的物理列。
        if ($kind === 'policy') {
            return $this->productionChoiceCache[$key] = collect(app(ReviewPolicyService::class)->listing(auth()->user()))->mapWithKeys(fn ($p) => [(string) $p['id'] => $p['key'].' · v'.$p['version'].' · '.$p['status'].' · #'.$p['id']])->all();
        }
        // 模型能力选项展示完整业务身份；保留所有历史版本及原版本ID，避免价格关联错模型。
        if ($kind === 'model') {
            return $this->productionChoiceCache[$key] = DB::table('model_profile_versions as v')
                ->join('model_profiles as m', 'm.id', '=', 'v.model_profile_id')
                ->join('providers as p', 'p.id', '=', 'm.provider_id')
                ->orderByDesc('v.id')
                ->get(['v.id', 'v.version', 'm.provider_model_id', 'p.name'])
                ->mapWithKeys(fn ($v) => [(string) $v->id => $v->name.' / '.$v->provider_model_id.' · 能力 v'.$v->version.' · #'.$v->id])
                ->all();
        }
        $spec = match ($kind) {
            'volume' => ['volumes', ['sequence', 'title']], 'story_arc' => ['story_arcs', ['sequence', 'title']], 'requirement' => ['requirement_versions', ['content']],
            'chapter' => ['chapters', ['sequence', 'title']], 'scene' => ['scenes', ['sequence', 'title', 'parent_id']],
            'chapter_version' => ['chapter_versions', ['title', 'version', 'node_id']], 'scene_version' => ['scene_versions', ['title', 'version', 'node_id']],
            'review' => ['reviews', ['chapter_version_id', 'reviewer_type']], 'extraction' => ['extraction_attempts', ['chapter_version_id']],
            'review_issue' => ['review_issues', ['category', 'severity']], 'revision_task' => ['revision_tasks', ['source_version_id']], 'proposal' => ['revision_proposals', ['task_id']],
            'character' => ['entities', ['name']], 'source' => ['source_documents', ['source_hash']], 'event' => ['proposed_events', ['attribute']], 'evidence' => ['evidence_spans', ['source_version_id', 'quote']],
            'context' => ['story_context_versions', ['version', 'reason']], 'manifest' => ['context_manifests', ['step_id']], 'revision' => ['canonical_revisions', ['cursor', 'status']],
            'memory' => ['memory_items', ['collection_id', 'version']], 'chunk' => ['memory_chunks', ['memory_id', 'chunk_no']],
            'budget' => ['budget_policies', ['key', 'version', 'currency']], 'price' => ['price_versions', ['model_version_id', 'version', 'currency']],
            'policy' => ['review_policy_versions', ['key', 'version']], 'style' => ['style_versions', ['version', 'status']], 'model' => ['model_profile_versions', ['version']],
            'run' => ['generation_runs', ['status', 'novel_id']], 'step' => ['generation_steps', ['scope_key', 'stage']], 'request' => ['ai_requests', ['status']],
            default => null,
        };
        if ($kind === 'route') {
            $q = DB::table('route_versions as v')->join('model_routes as r', 'r.id', '=', 'v.parent_id')->where('v.status', 'published')->where('v.content->mode', $this->executionMode ?? 'offline_fake');
            $stage = match (class_basename($this)) {
                'ReviewCenter' => 'review.chapter','RevisionDesk' => 'revision.propose',default => null
            };
            if ($stage) {
                $q->where('r.stage', $stage);
            }

            return $this->productionChoiceCache[$key] = $q->get(['v.id', 'r.key', 'r.stage'])->mapWithKeys(fn ($r) => [(string) $r->id => $r->key.' · '.$r->stage.' · #'.$r->id])->all();
        }
        if ($kind === 'definition') {
            if ($book === '') {
                return [];
            }

            return $this->productionChoiceCache[$key] = DB::table('entity_definition_versions as d')->join('entities as e', 'e.id', '=', 'd.entity_id')->where('e.novel_id', $book)->where('e.retired', false)->whereExists(fn ($q) => $q->selectRaw('1')->from('entity_approvals as a')->whereColumn('a.entity_definition_version_id', 'd.id'))->orderByDesc('d.id')->get(['d.id', 'd.version', 'e.name'])->mapWithKeys(fn ($d) => [(string) $d->id => $d->name.' · v'.$d->version.' · #'.$d->id])->all();
        }
        if (! $spec) {
            throw ValidationException::withMessages(['production' => '选项种类未注册。']);
        }
        [$table,$columns] = $spec;
        $q = DB::table($table);
        $global = in_array($kind, ['budget', 'price', 'policy', 'style', 'model'], true);
        if (! $global) {
            if ($book === '') {
                if (! in_array($kind, ['run', 'request'], true)) {
                    return [];
                }
                $q->whereIn('novel_id', DB::table('novels')->where('owner_id', auth()->id())->select('id'));
            } else {
                $q->where('novel_id', $book);
            }
        }
        // 额外保留事实只能来自当前评审对应的提取记录。
        if ($kind === 'event' && class_basename($this) === 'RevisionDesk') {
            $q->whereIn('id', DB::table('extraction_events')->whereIn('attempt_id', DB::table('reviews')->where('novel_id', $book)->where('id', $this->review ?: '0')->select('extraction_id'))->select('event_id'));
        }
        if (class_basename($this) === 'SearchLab' && in_array($kind, ['source', 'evidence', 'event', 'memory', 'chunk'], true)) {
            $sources = DB::table('source_documents')->where('novel_id', $book)->where('run_id', $this->branch ?: '0')->select('id');
            if ($kind === 'source') {
                $q->where('run_id', $this->branch ?: '0');
            }
            if ($kind === 'evidence') {
                $q->whereIn('source_version_id', $sources);
            }
            if ($kind === 'event') {
                $q->whereIn('evidence_id', DB::table('evidence_spans')->whereIn('source_version_id', $sources)->select('id'));
            }
            if ($kind === 'memory') {
                $q->where('branch_id', $this->branch ?: '0');
            }
            if ($kind === 'chunk') {
                $q->whereIn('memory_id', DB::table('memory_items')->where('novel_id', $book)->where('branch_id', $this->branch ?: '0')->when($this->memory !== '', fn ($m) => $m->where('id', $this->memory))->select('id'));
            }
        }
        if ($kind === 'character') {
            $q->where('type', 'character')->whereNotNull('approved_version_id')->where('retired', false);
        }
        if ($kind === 'manifest' && property_exists($this, 'step')) {
            $q->where('step_id', $this->step ?: '0');
        }
        if (in_array($kind, ['extraction', 'review'], true) && ($bodyVersion = (string) ($this->version ?? $this->candidate ?? '')) !== '') {
            $q->where('chapter_version_id', $bodyVersion);
        }
        if ($kind === 'review_issue') {
            $q->where('review_id', class_basename($this) === 'ReviewCenter' ? ($this->selected ?: '0') : ($this->review ?: '0'));
        }
        if ($kind === 'proposal') {
            $q->where('task_id', $this->selected ?: '0');
        }
        $prefix = ['chapter' => '章节', 'scene' => '场景', 'chapter_version' => '章节正文', 'scene_version' => '场景正文', 'review' => '评审', 'extraction' => '提取', 'review_issue' => '问题', 'revision_task' => '修订任务', 'proposal' => '提案', 'context' => '候选时间线', 'manifest' => '上下文清单', 'price' => '报价', 'budget' => '预算', 'policy' => '评审策略', 'style' => '文风', 'model' => '模型能力', 'request' => '请求', 'revision' => '正式基线'];

        return $this->productionChoiceCache[$key] = $q->orderByDesc('id')->get(array_merge(['id'], $columns))->mapWithKeys(function ($row) use ($kind, $columns, $prefix) {
            $parts = [];
            foreach ($columns as $c) {
                $v = $row->$c;
                if ($v !== null && $v !== '') {
                    $parts[] = mb_substr((string) $v, 0, $c === 'quote' ? 60 : 80);
                }
            }

            return [(string) $row->id => ($prefix[$kind] ?? '记录').' #'.$row->id.' · '.implode(' / ', $parts)];
        })->all();
    }

    /** 多选保留旧CSV传输约定；值必须来自当前允许的实际记录，不重排已有选择。 */
    public function toggleProductionReference(string $property, string $id): void
    {
        if (! property_exists($this, $property)) {
            throw ValidationException::withMessages(['production' => '字段不存在。']);
        }
        $kind = $this->productionReferenceFields()[$property] ?? throw ValidationException::withMessages(['production' => '未知引用字段。']);
        if (! array_key_exists($id, $this->productionChoices($kind))) {
            throw ValidationException::withMessages(['production' => '记录不在当前授权范围。']);
        }
        $values = array_values(array_filter(array_map('trim', explode(',', $this->$property))));
        $this->$property = implode(',', in_array($id, $values, true) ? array_values(array_filter($values, fn ($v) => $v !== $id)) : [...$values, $id]);
    }

    protected function productionReferenceFields(): array
    {
        return ['prices' => 'price', 'issues' => 'review_issue', 'facts' => 'event', 'changedScopes' => 'scope'];
    }

    /** 仅当前动作实际读取的载荷参与错误投影；客户端不能选择错误作用域。 */
    protected array $productionErrorRoots = [];

    protected function beginProductionAction(): void
    {
        $this->productionErrorRoots = [];
        $this->resetErrorBag();
        // 新动作不沿用上一动作的错误通知，避免导入区仍显示旧预算错误。
        foreach (['notice', 'billingNotice'] as $property) {
            if (property_exists($this, $property)) {
                $this->$property = '';
            }
        }
    }

    /** 显式前缀优先；未限定且同名的错误只进摘要，绝不广播到多个编辑树。 */
    protected function productionErrors(ValidationException $error, ?array $roots = null): void
    {
        $roots ??= $this->productionErrorRoots;
        $mapped = [];
        foreach ($error->errors() as $key => $messages) {
            $candidates = [];
            $prefixed = array_filter($roots, fn ($spec) => $spec['prefix'] !== '' && ($key === $spec['prefix'] || str_starts_with($key, $spec['prefix'].'.')));
            foreach ($prefixed ?: $roots as $root => $spec) {
                $logical = $prefixed ? ltrim(substr($key, strlen($spec['prefix'])), '.') : $key;
                $paths = [];
                $walk = function (array $node, string $path, string $name) use (&$walk, &$paths): void {
                    $paths[$name] = $path.(in_array($node['type'], ['object', 'array'], true) ? '' : '.value');
                    foreach ($node['children'] as $i => $row) {
                        $part = $node['type'] === 'array' ? (string) $i : $row['key'];
                        $walk($row['node'], $path.'.children.'.$i.'.node', $name === '' ? $part : $name.'.'.$part);
                    }
                };
                $walk($this->trees[$root], 'trees.'.$root, '');
                if (isset($paths[$logical])) {
                    $candidates[] = $paths[$logical];
                } elseif (in_array($key, $paths, true)) {
                    $candidates[] = $key;
                } elseif ($prefixed || isset($spec['schema']['properties'][explode('.', $logical)[0]])) {
                    // 缺失的必填字段没有控件，关联到最近的既有容器而非同名筛选。
                    while ($logical !== '' && ! isset($paths[$logical])) {
                        $logical = str_contains($logical, '.') ? substr($logical, 0, strrpos($logical, '.')) : '';
                    }
                    $candidates[] = $paths[$logical];
                }
            }
            $candidates = array_values(array_unique($candidates));
            // 无前缀表示整个表单载荷：未识别的服务字段也只能落到本表单容器。
            $directRoot = count($roots) === 1 && array_values($roots)[0]['prefix'] === '' ? array_key_first($roots) : null;
            $target = count($candidates) === 1 ? $candidates[0] : ($directRoot !== null ? 'trees.'.$directRoot : (count($candidates) > 1 || str_starts_with($key, 'trees.') ? 'production' : $key));
            $mapped[$target] = array_values(array_unique([...($mapped[$target] ?? []), ...$messages]));
            if (str_starts_with($target, 'trees.') && property_exists($this, 'expandedJson')) {
                // 只展开错误所在的树祖先，确保深层控件可供键盘定位。
                $parts = explode('.', $target);
                for ($i = 2; $i <= count($parts); $i += 3) {
                    $this->expandedJson[implode('.', array_slice($parts, 0, $i))] = true;
                }
            }
        }
        foreach (['chapter_version_id' => 'version', 'extraction_id' => 'extraction', 'policy_version_id' => 'policy'] as $key => $field) {
            if (isset($mapped[$key])) {
                $mapped[$field] = $mapped[$key];
                unset($mapped[$key]);
            }
        }
        $this->setErrorBag($mapped);
        $this->dispatch('nf-validation-failed', sourceId: $this->getId());
    }
}
