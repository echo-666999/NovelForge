<?php

namespace App\Filament\Resources\NovelResource\Pages;

use App\Drafting\BodyContract;
use App\Exceptions\NovelProblem;
use App\Filament\Resources\NovelResource;
use App\Services\DraftingService;
use App\Services\GenerationService;
use App\Services\NovelIntentService;
use App\Services\OutboxService;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** UI09 只编辑候选；自动保存与手动保存共用乐观锁，失败时保留用户输入。 */
class DraftEditor extends Page
{
    protected static string $resource = NovelResource::class;

    protected static ?string $title = '候选正文';

    protected string $view = 'filament.draft-editor';

    #[Locked]
    public string $novelId;

    public string $kind = 'chapter';

    #[Locked]
    public string $loadedKind = 'chapter';

    public string $selected = '';

    public string $comparison = '';

    public string $bodyTitle = '';

    public string $body = '';

    public string $reason = '作者编辑候选';

    public string $notice = '';

    public string $plan = '';

    public string $scene = '';

    public string $pov = '';

    public string $route = '';

    public string $policy = '';

    public string $prices = '';

    public string $offlineBody = '';

    public string $run = '';

    public string $chapter = '';

    public string $assemblyTitle = '';

    public string $status = '';

    public bool $missing = false;

    public bool $wordIssue = false;

    #[Locked]
    public ?array $detail = null;

    #[Locked]
    public ?array $previous = null;

    #[Locked]
    public string $savedBody = '';

    #[Locked]
    public string $savedTitle = '';

    public function mount(string $record): void
    {
        $this->novelId = app(NovelIntentService::class)->novel(auth()->user(), $record)->id;
    }

    public function listing(): array
    {
        return app(DraftingService::class)->listing(auth()->user(), $this->novelId, ['status' => $this->status ?: null, 'missing' => $this->missing, 'word_issue' => $this->wordIssue]);
    }

    public function options(): array
    {
        app(NovelIntentService::class)->novel(auth()->user(), $this->novelId);

        return ['plans' => DB::table('rolling_plans')->where('novel_id', $this->novelId)->orderByDesc('id')->get(['id', 'version'])->all(), 'routes' => DB::table('route_versions as v')->join('model_routes as r', 'r.id', '=', 'v.parent_id')->where('v.status', 'published')->where('r.stage', 'drafting.scene')->get(['v.id', 'r.key'])->all(), 'policies' => DB::table('budget_policies')->get(['id', 'key'])->all(), 'prices' => DB::table('price_versions')->get(['id', 'model_version_id'])->all(), 'povs' => DB::table('entities')->where('novel_id', $this->novelId)->where('type', 'character')->whereNotNull('approved_version_id')->get(['id', 'name'])->all()];
    }

    public function inspect(): void
    {
        $this->attempt(function () {
            $detail = app(DraftingService::class)->read(auth()->user(), $this->novelId, $this->kind, $this->selected);
            $previous = $this->comparison ? app(DraftingService::class)->read(auth()->user(), $this->novelId, $this->kind, $this->comparison) : null;
            if ($previous && $previous['node_id'] !== $detail['node_id']) {
                throw new NovelProblem('INVALID_COMPARISON', '请选择同一节点的两个版本。');
            }
            $this->loadedKind = $this->kind;
            $this->detail = $detail;
            $this->previous = $previous;
            $this->body = $this->savedBody = $detail['body'];
            $this->bodyTitle = $this->savedTitle = $detail['title'];
        });
    }

    public function save(bool $automatic = false): void
    {
        if (! $this->detail || ($this->body === $this->savedBody && $this->bodyTitle === $this->savedTitle)) {
            return;
        }
        $this->attempt(function () use ($automatic) {
            $d = $this->detail;
            $version = app(DraftingService::class)->save(auth()->user(), $this->novelId, $this->loadedKind, $d['node_id'], ['plan_id' => $d['plan_id'], 'source_version_id' => $d['id'], 'expected_version' => $d['version'], 'title' => $this->bodyTitle, 'body' => $this->body, 'reason' => $automatic ? '候选自动保存：'.$this->reason : $this->reason], (string) Str::uuid());
            $this->selected = $version['id'];
            $this->detail = $version;
            $this->savedBody = $this->body;
            $this->savedTitle = $this->bodyTitle;
            $this->dispatch('draft-saved');
            $this->notice = ($automatic ? '已自动保存候选' : '已保存候选').' v'.$version['version'].'；没有提交正式正文。';
        });
    }

    public function autoSave(): void
    {
        $this->save(true);
    }

    public function count(): int
    {
        return BodyContract::count($this->body);
    }

    public function startScene(): void
    {
        $this->attempt(function () {
            $listing = app(DraftingService::class)->listing(auth()->user(), $this->novelId);
            $scene = null;
            $chapter = null;
            foreach ($listing['items'] as $ch) {
                foreach ($ch['scenes'] as $sc) {
                    if ($sc['id'] === $this->scene) {
                        $scene = $sc;
                        $chapter = $ch;
                    }
                }
            }
            $cfg = preg_match('/^[1-9][0-9]{0,17}$/D', $this->route) ? DB::table('route_versions')->find($this->route) : null;
            $parameters = $cfg ? json_decode($cfg->content, true)['parameters'] : [];
            $result = app(DraftingService::class)->start(auth()->user(), $this->novelId, ['plan_id' => $this->plan, 'scene_id' => $this->scene, 'pov_id' => $this->pov, 'route_version_id' => $this->route, 'expected_version' => app(NovelIntentService::class)->novel(auth()->user(), $this->novelId)->row_version, 'expected_candidate_version' => $scene['candidate']['version'] ?? 0, 'billing' => ['policy_id' => $this->policy, 'price_version_ids' => array_filter(array_map('trim', explode(',', $this->prices))), 'category' => 'generation', 'chapter_id' => $chapter['id'] ?? null, 'max_output_tokens' => $parameters['max_output_tokens'] ?? 0, 'max_reasoning_tokens' => 0], 'offline_output' => ['body' => $this->offlineBody], 'reason' => $this->reason], (string) Str::uuid());
            $this->run = $result['run_id'];
            $this->notice = '离线场景任务已保存，请执行回放或由专用生成队列处理。';
        });
    }

    public function replay(): void
    {
        $this->attempt(function () {
            app(GenerationService::class)->read(auth()->user(), $this->novelId, $this->run);
            $event = DB::table('outbox_events')->where('run_id', $this->run)->orderByDesc('id')->value('id');
            if (! $event) {
                throw new NovelProblem('NOT_FOUND', '运行没有投递意图。', 404);
            }
            app(OutboxService::class)->consume((string) $event);
            $this->notice = '回放已处理；请检查场景版本和运行证据。';
        });
    }

    public function assemble(): void
    {
        $this->attempt(function () {
            $chapter = collect(app(DraftingService::class)->listing(auth()->user(), $this->novelId)['items'])->firstWhere('id', $this->chapter);
            $result = app(DraftingService::class)->assemble(auth()->user(), $this->novelId, $this->chapter, ['plan_id' => $this->plan, 'scene_version_ids' => array_column(array_column($chapter['scenes'] ?? [], 'candidate'), 'id'), 'expected_version' => $chapter['candidate']['version'] ?? 0, 'title' => $this->assemblyTitle, 'reason' => $this->reason], (string) Str::uuid());
            $this->kind = 'chapter';
            $this->selected = $result['id'];
            $this->inspect();
            $this->notice = '已按全部场景的冻结顺序生成章节候选。';
        });
    }

    private function attempt(callable $fn): void
    {
        $this->notice = '';
        try {
            $fn();
        } catch (NovelProblem|ValidationException $e) {
            $this->notice = $e instanceof ValidationException ? implode(' ', $e->validator->errors()->all()) : $e->getMessage();
        }
    }
}
