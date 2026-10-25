<?php

namespace App\Filament\Pages;

use App\Exceptions\NovelProblem;
use App\Services\DraftingService;
use App\Services\ExtractionPipelineService;
use App\Services\ExtractionService;
use App\Services\GenerationService;
use App\Services\NovelIntentService;
use App\Services\OutboxService;
use App\Services\StoryStateService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** UI16 候选事件账本：可比较、定位原文和只重提取，不提供正式状态写入。 */
class ExtractionLedger extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $navigationLabel = '正文提取与硬约束';

    protected static ?string $title = '正文提取与候选事件账本';

    protected string $view = 'filament.extraction-ledger';

    public string $novel = '';

    public string $version = '';

    public string $baseline = '';

    public string $parent = '';

    public string $ruleContext = '';

    public string $output = '{"events":[],"dependencies":[],"knowledge":[],"relationships":[],"foreshadows":[]}';

    public string $reason = '';

    public string $selected = '';

    public string $notice = '';

    public string $entity = '';

    public string $factKind = '';

    public string $route = '';

    public string $policy = '';

    public string $prices = '';

    public string $run = '';

    public int $atChapter = 1;

    #[Locked]
    public ?array $projection = null;

    #[Locked]
    public ?array $detail = null;

    #[Locked]
    public ?array $body = null;

    public function mount(): void
    {
        $this->novel = (string) request()->query('novel', '');
        $this->version = (string) request()->query('version', '');
        $this->baseline = (string) request()->query('baseline', '');
        $parent = (string) request()->query('parent', '');
        if ($parent !== '' && $this->novel !== '') {
            $this->selected = $parent;
            $this->prepareReextract();
        }
    }

    public function listing(): array
    {
        return $this->novel === '' ? [] : app(ExtractionService::class)->listing(auth()->user(), $this->novel);
    }

    public function loadBody(): void
    {
        $this->attempt(function () {
            $this->body = app(DraftingService::class)->read(auth()->user(), $this->novel, 'chapter', $this->version);
        });
    }

    public function extract(): void
    {
        $this->attempt(function () {
            $body = app(DraftingService::class)->read(auth()->user(), $this->novel, 'chapter', $this->version);
            $book = app(NovelIntentService::class)->novel(auth()->user(), $this->novel);
            $this->detail = app(ExtractionService::class)->extract(auth()->user(), $this->novel, ['chapter_version_id' => $this->version, 'body_hash' => $body['body_hash'], 'expected_version' => $book->row_version, 'expected_state_version' => $book->state_version, 'parent_id' => $this->parent ?: null, 'baseline_id' => $this->baseline ?: null, 'rule_context_id' => $this->ruleContext ?: null, 'offline_output' => json_decode($this->output, true, 64, JSON_THROW_ON_ERROR), 'reason' => $this->reason], (string) Str::uuid());
            $this->selected = $this->detail['id'];
            $this->body = $body;
            $this->notice = '离线提取记录已保存；只验证候选，没有改写正文或提交正式状态。';
        });
    }

    public function inspect(): void
    {
        $this->attempt(function () {
            $this->detail = app(ExtractionService::class)->read(auth()->user(), $this->novel, $this->selected);
            $this->body = app(DraftingService::class)->read(auth()->user(), $this->novel, 'chapter', $this->detail['chapter_version_id']);
        });
    }

    public function prepareReextract(): void
    {
        $this->attempt(function () {
            $old = app(ExtractionService::class)->read(auth()->user(), $this->novel, $this->selected);
            $this->parent = $old['id'];
            $this->version = $old['chapter_version_id'];
            $this->baseline = $old['baseline_id'] ?? '';
            $this->ruleContext = $old['frozen_input']['rule_context_id'] ?? '';
            $this->output = json_encode($old['output'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $this->notice = '已选择同一正文与前章基线；修正离线响应后再次提取，原记录保留。';
        });
    }

    /** 回放或重建只针对当前有效候选流，不写正式投影或提交游标。 */
    public function replay(bool $rebuild = false): void
    {
        $this->attempt(function () use ($rebuild) {
            $this->projection = DB::transaction(function () use ($rebuild) {
                app(NovelIntentService::class)->novel(auth()->user(), $this->novel, true);
                $current = app(ExtractionService::class)->read(auth()->user(), $this->novel, $this->selected);
                if (! $current['current_eligible'] || $this->atChapter < 1 || $this->atChapter > $current['chapter_sequence']) {
                    throw new NovelProblem('INVALID_CANDIDATE_REPLAY', '只能回放当前有效候选且不能超过已验证章位。');
                }
                $s = app(StoryStateService::class);

                return $rebuild ? $s->rebuild(auth()->user(), $this->novel, $current['candidate_event_ids'], $this->atChapter) : $s->replay(auth()->user(), $this->novel, $current['candidate_event_ids'], $this->atChapter);
            });
        });
    }

    public function options(): array
    {
        if ($this->novel === '') {
            return ['routes' => [], 'policies' => []];
        }
        app(NovelIntentService::class)->novel(auth()->user(), $this->novel);

        return ['routes' => DB::table('route_versions as v')->join('model_routes as r', 'r.id', '=', 'v.parent_id')->where('v.status', 'published')->where('r.stage', 'extraction.events')->get(['v.id', 'r.key'])->all(), 'policies' => DB::table('budget_policies')->get(['id', 'key'])->all()];
    }

    /** 与纯验证明确分开；该入口经过冻结路由、预算与持久请求证据。 */
    public function startPipeline(): void
    {
        $this->attempt(function () {
            $body = app(DraftingService::class)->read(auth()->user(), $this->novel, 'chapter', $this->version);
            $book = app(NovelIntentService::class)->novel(auth()->user(), $this->novel);
            $cfg = preg_match('/^[1-9][0-9]{0,17}$/D', $this->route) ? DB::table('route_versions')->find($this->route) : null;
            $params = $cfg ? json_decode($cfg->content, true)['parameters'] : [];
            $result = app(ExtractionPipelineService::class)->start(auth()->user(), $this->novel, ['chapter_version_id' => $this->version, 'body_hash' => $body['body_hash'], 'expected_version' => $book->row_version, 'expected_state_version' => $book->state_version, 'parent_id' => $this->parent ?: null, 'baseline_id' => $this->baseline ?: null, 'rule_context_id' => $this->ruleContext ?: null, 'offline_output' => json_decode($this->output, true, 64, JSON_THROW_ON_ERROR), 'reason' => $this->reason, 'route_version_id' => $this->route, 'billing' => ['policy_id' => $this->policy, 'price_version_ids' => array_values(array_filter(array_map('trim', explode(',', $this->prices)))), 'category' => 'generation', 'chapter_id' => $body['node_id'], 'max_output_tokens' => $params['max_output_tokens'] ?? 0, 'max_reasoning_tokens' => 0]], (string) Str::uuid());
            $this->run = $result['run_id'];
            $this->notice = '离线提取运行已冻结，可执行回放或由专用队列继续；没有调用真实模型。';
        });
    }

    public function replayPipeline(): void
    {
        $this->attempt(function () {
            app(GenerationService::class)->read(auth()->user(), $this->novel, $this->run);
            $event = DB::table('outbox_events')->where('run_id', $this->run)->orderByDesc('id')->value('id');
            if (! $event) {
                throw new NovelProblem('NOT_FOUND', '提取运行没有投递意图。', 404);
            }
            app(OutboxService::class)->consume((string) $event);
            $data = app(GenerationService::class)->read(auth()->user(), $this->novel, $this->run);
            $id = DB::table('extraction_attempts')->where('run_id', $this->run)->orderByDesc('id')->value('id');
            if ($id) {
                $this->selected = (string) $id;
                $this->inspect();
            }
            $this->notice = '运行状态：'.$data['run']['status'].'；请查看候选硬约束报告及请求证据。';
        });
    }

    public function events(): array
    {
        return array_values(array_filter($this->detail['events'] ?? [], fn ($e) => ($this->entity === '' || $e['entity_id'] === $this->entity) && ($this->factKind === '' || $e['fact_kind'] === $this->factKind)));
    }

    private function attempt(callable $fn): void
    {
        try {
            $fn();
        } catch (NovelProblem|ValidationException|\JsonException $e) {
            $this->notice = $e instanceof ValidationException ? implode(' ', $e->validator->errors()->all()) : $e->getMessage();
        }
    }
}
