<?php

namespace App\Filament\Resources\NovelResource\Pages;

use App\Exceptions\NovelProblem;
use App\Filament\Concerns\EditsProductionPayloads;
use App\Filament\Concerns\HasProductionChoices;
use App\Filament\Concerns\HasStageExecutionMode;
use App\Filament\Concerns\ReadsProductionFrames;
use App\Filament\Concerns\WritesProductionCommands;
use App\Filament\Resources\NovelResource;
use App\Planning\PlanningContract;
use App\Services\NovelIntentService;
use App\Services\OutboxService;
use App\Services\PlanningPipelineService;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** UI05–08 共用候选工作区；旧版与当前版并列显示，采纳和批准明确分开。 */
class PlanningCandidates extends Page
{
    use EditsProductionPayloads;
    use HasProductionChoices;
    use HasStageExecutionMode;
    use ReadsProductionFrames;
    use WritesProductionCommands;

    protected static string $resource = NovelResource::class;

    protected static ?string $title = '规划候选';

    protected string $view = 'filament.planning-candidates';

    public const LABELS = ['intake' => '输入提炼', 'blueprint' => '蓝图候选', 'outline' => '卷弧骨架', 'chapter_plan' => '章节细化', 'scene_plan' => '场景细化'];

    #[Locked]
    public string $novelId;

    public string $phase = 'intake';

    public int $startChapter = 1;

    public int $endChapter = 10;

    public string $parent = '';

    public string $route = '';

    public string $policy = '';

    public string $prices = '';

    public string $offlineOutput = '';

    public string $reason = '';

    public string $selected = '';

    public string $comparison = '';

    public string $notice = '';

    #[Locked]
    public ?array $detail = null;

    #[Locked]
    public ?array $previous = null;

    public function mount(string $record): void
    {
        $this->novelId = app(NovelIntentService::class)->novel(auth()->user(), $record)->id;
    }

    public function listing(): array
    {
        return app(PlanningPipelineService::class)->listing(auth()->user(), $this->novelId);
    }

    public function options(): array
    {
        app(NovelIntentService::class)->novel(auth()->user(), $this->novelId);

        return ['routes' => DB::table('route_versions as v')->join('model_routes as r', 'r.id', '=', 'v.parent_id')->where('v.status', 'published')->where('v.content->mode', $this->executionMode)->where('r.stage', 'planning.'.$this->phase)->get(['v.id', 'r.key', 'v.content'])->all(), 'policies' => DB::table('budget_policies')->orderByDesc('id')->get(['id', 'key'])->all(), 'prices' => DB::table('price_versions')->orderByDesc('id')->get(['id', 'model_version_id', 'currency'])->all()];
    }

    public function startCandidate(): void
    {
        $this->attempt(function () {
            $output = $this->executionMode === 'production' ? null : $this->productionValue('offlineOutput', 'offline_output');
            $cfg = preg_match('/^[1-9][0-9]{0,17}$/D', $this->route) ? DB::table('route_versions')->find($this->route) : null;
            $parameters = $cfg ? json_decode($cfg->content, true)['parameters'] : [];
            $result = $this->productionWrite('PlanningPipelineService.start', [], ['start_chapter' => in_array($this->phase, ['chapter_plan', 'scene_plan'], true) ? $this->startChapter : null, 'end_chapter' => in_array($this->phase, ['chapter_plan', 'scene_plan'], true) ? $this->endChapter : null, 'phase' => $this->phase, 'parent_id' => $this->parent ?: null, 'route_version_id' => $this->route, 'billing' => ['policy_id' => $this->policy, 'price_version_ids' => array_values(array_filter(array_map('trim', explode(',', $this->prices)))), 'category' => 'planning', 'max_output_tokens' => $parameters['max_output_tokens'] ?? 0, 'max_reasoning_tokens' => 0], 'offline_output' => $output, 'expected_version' => app(NovelIntentService::class)->novel(auth()->user(), $this->novelId)->row_version, 'reason' => $this->reason], fn ($frozenPayload, $commandKey) => app(PlanningPipelineService::class)->start(auth()->user(), $this->novelId, $frozenPayload, $commandKey));
            $this->selected = $result['id'];
            $this->inspect();
            $this->notice = '规划任务已冻结，可在运行中心查看执行模式与结果；候选不会自动采纳。';
        });
    }

    public function inspect(): void
    {
        $this->detail = null;
        $this->previous = null;
        $this->attempt(function () {
            $this->detail = app(PlanningPipelineService::class)->read(auth()->user(), $this->novelId, $this->selected);
            if ($this->comparison !== '') {
                $this->previous = app(PlanningPipelineService::class)->read(auth()->user(), $this->novelId, $this->comparison);
            }
        });
    }

    public function replay(): void
    {
        $this->attempt(function () {
            $detail = app(PlanningPipelineService::class)->read(auth()->user(), $this->novelId, $this->selected);
            $event = DB::table('outbox_events')->where('run_id', $detail['data']['run']['id'])->orderByDesc('id')->value('id');
            app(OutboxService::class)->consume((string) $event);
            $this->inspect();
        });
    }

    public function adopt(): void
    {
        $this->attempt(function () {
            $detail = app(PlanningPipelineService::class)->read(auth()->user(), $this->novelId, $this->selected);
            $artifact = collect($detail['data']['artifacts'])->last(fn ($a) => $a['type'] === 'planning_candidate');
            $this->detail = $this->productionWrite('PlanningPipelineService.adopt', [$this->selected], ['artifact_id' => $artifact['id'] ?? null, 'expected_version' => app(NovelIntentService::class)->novel(auth()->user(), $this->novelId)->row_version, 'reason' => $this->reason], fn ($frozenPayload, $commandKey) => app(PlanningPipelineService::class)->adopt(auth()->user(), $this->novelId, $this->selected, $frozenPayload, $commandKey));
            $this->notice = '候选已采纳为规划草稿；要求决定、蓝图批准和计划冻结仍须在对应页面完成。';
        });
    }

    public function schemaPreview(): string
    {
        if (! in_array($this->phase, PlanningContract::PHASES, true)) {
            return '请选择合法的规划阶段。';
        }

        return json_encode(PlanningContract::schema(in_array($this->phase, PlanningContract::PHASES, true) ? $this->phase : 'intake'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    private function attempt(callable $fn): void
    {
        $this->beginProductionAction();
        $this->notice = '';
        try {
            $fn();
        } catch (NovelProblem|ValidationException|\JsonException $e) {
            if ($e instanceof ValidationException) {
                $this->productionErrors($e);
            }
            $this->notice = $e instanceof ValidationException ? implode(' ', $e->validator->errors()->all()) : $e->getMessage();
        }
    }

    /** 固定阶段契约只创建空字段，不伪造规划结果。 */
    protected function productionPayloads(): array
    {
        return ['offlineOutput' => PlanningContract::schema(in_array($this->phase, PlanningContract::PHASES, true) ? $this->phase : 'intake')];
    }

    public function updatedSelected(): void
    {
        $this->detail = $this->previous = null;
    }
}
