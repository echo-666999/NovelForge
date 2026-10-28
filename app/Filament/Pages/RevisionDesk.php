<?php

namespace App\Filament\Pages;

use App\Exceptions\NovelProblem;
use App\Extraction\ExtractionContract;
use App\Filament\Concerns\EditsProductionPayloads;
use App\Filament\Concerns\HasNovelNavigation;
use App\Filament\Concerns\HasProductionChoices;
use App\Filament\Concerns\ReadsProductionFrames;
use App\Filament\Concerns\WritesProductionCommands;
use App\Filament\Support\ProductionSchemas;
use App\Planning\PlanningContract;
use App\Review\ReviewContract;
use App\Revision\RevisionContract;
use App\Services\ChapterReviewService;
use App\Services\GenerationService;
use App\Services\NovelIntentService;
use App\Services\OutboxService;
use App\Services\RevisionPipelineService;
use App\Services\RevisionService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** UI14先展示原文、范围与差异，再允许逐项采纳；不自动改写整章。 */
class RevisionDesk extends Page
{
    use EditsProductionPayloads;
    use HasNovelNavigation;
    use HasProductionChoices;
    use ReadsProductionFrames;
    use WritesProductionCommands;

    // UI03：分组与顺序显式固定，不依赖页面发现次序。
    protected static string|\UnitEnum|null $navigationGroup = '生产与质量';

    protected static ?int $navigationSort = 40;

    // UI22：二级菜单使用原生圆点，仅一级分组显示图标。
    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = '受限修订工作台';

    protected static ?string $title = '受限修订与完整章节复评';

    protected string $view = 'filament.revision-desk';

    public string $review = '';

    public string $issues = '';

    public string $scopeJson = '';

    public string $protectedJson = '[]';

    public string $facts = '';

    public string $mode = 'offline';

    public string $reason = '';

    public string $selected = '';

    public string $proposal = '';

    public array $patchKeys = [];

    public string $patchJson = '{"patches":[]}';

    public string $manualText = '';

    public int $range = 0;

    public string $extractionJson = '';

    public string $reviewJson = '';

    public string $reviewer = 'offline';

    public string $route = '';

    public string $budget = '';

    public string $prices = '';

    public string $run = '';

    public string $notice = '';

    #[Locked]
    public ?array $detail = null;

    public function mount(): void
    {
        $this->novel = (string) request()->query('novel', '');
        $this->review = (string) request()->query('review', '');
    }

    /** 选择另一任务后先重新读取，防止沿用旧补丁选择与旧的手工范围。 */
    public function updatedSelected(): void
    {
        $this->detail = null;
        $this->proposal = '';
        $this->patchKeys = [];
    }

    private function attempt(callable $fn): void
    {
        $this->beginProductionAction();
        try {
            $fn();
        } catch (NovelProblem|ValidationException|\JsonException $e) {
            if ($e instanceof ValidationException) {
                $this->productionErrors($e);
            }
            $this->notice = $e instanceof ValidationException ? implode(' ', $e->validator->errors()->all()) : $e->getMessage();
        }
    }

    public function prepare(): void
    {
        $this->attempt(function () {
            $r = app(ChapterReviewService::class)->read(auth()->user(), $this->novel, $this->review);
            $body = $r['frozen_input']['context']['body'];
            $end = mb_strpos($body, "\n");
            $end = $end === false ? mb_strlen($body) : $end;
            $this->scopeJson = json_encode(['kind' => 'paragraph', 'ranges' => [['start' => 0, 'end' => $end, 'quote' => mb_substr($body, 0, $end)]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $this->issues = implode(',', array_column($r['issues'], 'id'));
            $this->notice = '已默认选择首段，请明确选择问题、允许范围和必须保留引文。';
        });
    }

    public function createTask(): void
    {
        $this->attempt(function () {
            $n = app(NovelIntentService::class)->novel(auth()->user(), $this->novel);
            $this->detail = $this->productionWrite('RevisionService.create', [], ['review_id' => $this->review, 'issue_ids' => array_values(array_filter(array_map('trim', explode(',', $this->issues)))), 'allowed_scope' => $this->productionValue('scopeJson', 'allowed_scope'), 'preserve_facts' => array_values(array_filter(array_map('trim', explode(',', $this->facts)))), 'preserve_quotes' => $this->productionValue('protectedJson', 'preserve_quotes'), 'mode' => $this->mode, 'expected_version' => $n->row_version, 'expected_state_version' => $n->state_version, 'reason' => $this->reason], fn ($frozenPayload, $commandKey) => app(RevisionService::class)->create(auth()->user(), $this->novel, $frozenPayload, $commandKey));
            $this->selected = $this->detail['id'];
            $this->notice = '修订问题和范围已冻结；状态：'.$this->detail['display_status'];
        });
    }

    public function listing(): array
    {
        return $this->novel === '' ? [] : app(RevisionService::class)->listing(auth()->user(), $this->novel);
    }

    public function inspect(): void
    {
        if (! preg_match('/^[1-9][0-9]{0,17}$/D', $this->selected)) {
            $this->notice = '请选择实际记录后读取。';

            return;
        }
        $this->attempt(function () {
            $this->detail = app(RevisionService::class)->read(auth()->user(), $this->novel, $this->selected);
            $this->run = $this->detail['run_id'] ?? '';
        });
    }

    public function propose(): void
    {
        $this->attempt(function () {
            $this->detail = $this->productionWrite('RevisionService.propose', [$this->selected], ['offline_output' => $this->productionValue('patchJson', 'offline_output'), 'reason' => $this->reason], fn ($frozenPayload, $commandKey) => app(RevisionService::class)->propose(auth()->user(), $this->novel, $this->selected, $frozenPayload, $commandKey));
            $last = end($this->detail['proposals']);
            $this->proposal = $last['id'];
            $this->notice = '提案已保存，尚未改动正文。';
        });
    }

    public function decide(string $kind): void
    {
        $this->attempt(function () use ($kind) {
            $t = app(RevisionService::class)->read(auth()->user(), $this->novel, $this->selected);
            $d = ['kind' => $kind, 'expected_version' => $t['input']['source']['version'], 'reason' => $this->reason];
            if ($kind === 'accept') {
                $d += ['proposal_id' => $this->proposal, 'patch_keys' => $this->patchKeys];
            }if ($kind === 'manual') {
                $r = $t['input']['allowed_scope']['ranges'][$this->range] ?? throw new NovelProblem('INVALID_REVISION_SCOPE', '请选择冻结范围。');
                $d['manual_output'] = ['patches' => [['key' => 'manual', 'issue_ids' => $t['input']['issue_ids'], 'start' => $r['start'], 'end' => $r['end'], 'quote' => $r['quote'], 'replacement' => $this->manualText, 'reason' => $this->reason]]];
            }$this->detail = $this->productionWrite('RevisionService.decide', [$this->selected], $d, fn ($frozenPayload, $commandKey) => app(RevisionService::class)->decide(auth()->user(), $this->novel, $this->selected, $frozenPayload, $commandKey));
            $this->notice = '决定已保存，状态：'.$this->detail['display_status'].'；候选必须重新取证及完整章节复评。';
        });
    }

    public function loadManual(): void
    {
        $this->attempt(function () {
            $t = app(RevisionService::class)->read(auth()->user(), $this->novel, $this->selected);
            $this->manualText = $t['input']['allowed_scope']['ranges'][$this->range]['quote'] ?? '';
        });
    }

    public function confirm(): void
    {
        $this->attempt(function () {
            $this->detail = $this->productionWrite('RevisionService.confirm', [$this->selected], ['reason' => $this->reason], fn ($frozenPayload, $commandKey) => app(RevisionService::class)->confirm(auth()->user(), $this->novel, $this->selected, $frozenPayload, $commandKey));
            $this->notice = '已追加人工候选确认；旧评分和失败记录不变，尚未正式提交。';
        });
    }

    public function assess(): void
    {
        $this->attempt(function () {
            $this->detail = $this->productionWrite('RevisionService.assess', [$this->selected], ['extraction_output' => $this->productionValue('extractionJson', 'extraction_output'), 'review_output' => $this->productionValue('reviewJson', 'review_output'), 'reviewer_type' => $this->reviewer, 'reason' => $this->reason], fn ($frozenPayload, $commandKey) => app(RevisionService::class)->assess(auth()->user(), $this->novel, $this->selected, $frozenPayload, $commandKey));
            $this->notice = '新正文已重新取证并整章复评：'.$this->detail['display_status'].'；未提交正式剧情。';
        });
    }

    public function startPipeline(): void
    {
        $this->attempt(function () {
            $t = app(RevisionService::class)->read(auth()->user(), $this->novel, $this->selected);
            $route = preg_match('/^[1-9][0-9]{0,17}$/D', $this->route) ? DB::table('route_versions')->find($this->route) : null;
            $params = $route ? json_decode($route->content, true)['parameters'] : [];
            $r = $this->productionWrite('RevisionPipelineService.start', [$this->selected], ['route_version_id' => $this->route, 'offline_output' => $this->productionValue('patchJson', 'offline_output'), 'billing' => ['policy_id' => $this->budget, 'price_version_ids' => array_values(array_filter(array_map('trim', explode(',', $this->prices)))), 'category' => 'generation', 'chapter_id' => $t['chapter_node_id'], 'max_output_tokens' => $params['max_output_tokens'] ?? 0, 'max_reasoning_tokens' => 0], 'reason' => $this->reason], fn ($frozenPayload, $commandKey) => app(RevisionPipelineService::class)->start(auth()->user(), $this->novel, $this->selected, $frozenPayload, $commandKey));
            $this->run = $r['run_id'];
            $this->notice = '离线补丁运行已冻结；不会自动采纳。';
        });
    }

    public function replayPipeline(): void
    {
        $this->attempt(function () {
            app(GenerationService::class)->read(auth()->user(), $this->novel, $this->run);
            $e = DB::table('outbox_events')->where('run_id', $this->run)->orderByDesc('id')->value('id');
            if (! $e) {
                throw new NovelProblem('NOT_FOUND', '没有投递意图。', 404);
            }app(OutboxService::class)->consume((string) $e);
            $this->inspect();
            $this->notice = '运行状态：'.app(GenerationService::class)->read(auth()->user(), $this->novel, $this->run)['run']['status'];
        });
    }

    /** 编辑字段结构来自已实现契约。 */
    protected function productionPayloads(): array
    {
        return ['scopeJson' => ProductionSchemas::scope(), 'protectedJson' => PlanningContract::list(ProductionSchemas::span(), 100), 'patchJson' => RevisionContract::schema(), 'extractionJson' => ExtractionContract::schema(), 'reviewJson' => ReviewContract::schema()];
    }

    /** 切书不保留上一书的正文、补丁、提取或待处理决定。 */
    public function updatedNovel(): void
    {
        $this->review = $this->selected = $this->proposal = $this->issues = $this->facts = $this->run = '';
        $this->detail = null;
        $this->patchKeys = [];
        $this->scopeJson = $this->extractionJson = $this->reviewJson = '';
        $this->protectedJson = '[]';
        $this->patchJson = '{"patches":[]}';
    }
}
