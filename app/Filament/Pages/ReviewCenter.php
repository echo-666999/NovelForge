<?php

namespace App\Filament\Pages;

use App\Exceptions\NovelProblem;
use App\Filament\Concerns\EditsProductionPayloads;
use App\Filament\Concerns\HasNovelNavigation;
use App\Filament\Concerns\HasProductionChoices;
use App\Filament\Concerns\ReadsProductionFrames;
use App\Filament\Concerns\WritesProductionCommands;
use App\Filament\Support\JsonFields;
use App\Filament\Support\ProductionSchemas;
use App\Review\ReviewContract;
use App\Services\AuthoringConfigurationService;
use App\Services\ChapterReviewService;
use App\Services\DraftingService;
use App\Services\GenerationService;
use App\Services\NovelIntentService;
use App\Services\OutboxService;
use App\Services\ReviewPipelineService;
use App\Services\ReviewPolicyService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** UI13/UI27：只读原文、评分与问题，非阻塞意见不能改写硬门禁。 */
class ReviewCenter extends Page
{
    use EditsProductionPayloads;
    use HasNovelNavigation;
    use HasProductionChoices;
    use ReadsProductionFrames;
    use WritesProductionCommands;

    // UI03：分组与顺序显式固定，不依赖页面发现次序。
    protected static string|\UnitEnum|null $navigationGroup = '生产与质量';

    protected static ?int $navigationSort = 30;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = '评审中心与策略';

    protected static ?string $title = '评审中心与文风策略';

    protected string $view = 'filament.review-center';

    public string $version = '';

    public string $extraction = '';

    public string $policy = '';

    public string $selected = '';

    public string $output = '';

    public string $reason = '';

    public string $notice = '';

    public string $reviewer = 'offline';

    public string $verdict = '';

    public string $severity = '';

    public string $category = '';

    public string $reviewerFilter = '';

    public string $run = '';

    public string $route = '';

    public string $budget = '';

    public string $prices = '';

    public string $policyKey = '';

    public int $policyVersion = 0;

    public string $style = '';

    public string $policyJson = '';

    public string $comparePolicy = '';

    public string $issue = '';

    public string $decision = 'accepted';

    #[Locked]
    public ?array $detail = null;

    #[Locked]
    public ?array $comparison = null;

    public function mount(): void
    {
        $this->novel = (string) request()->query('novel', '');
        $this->version = (string) request()->query('version', '');
        $this->policyJson = json_encode(ReviewContract::defaults(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /** 当前选择与已读详情分开，切换记录不能继续操作旧证据。 */
    public function updatedSelected(): void
    {
        $this->detail = null;
        $this->issue = '';
    }

    private function attempt(callable $fn): void
    {
        try {
            $fn();
        } catch (NovelProblem|ValidationException|\JsonException $e) {
            if ($e instanceof ValidationException) {
                $this->productionErrors($e);
            }
            $this->notice = $e instanceof ValidationException ? implode(' ', $e->validator->errors()->all()) : $e->getMessage();
        }
    }

    public function policies(): array
    {
        return app(ReviewPolicyService::class)->listing(auth()->user());
    }

    public function listing(): array
    {
        if ($this->novel === '') {
            return [];
        }

        return array_values(array_filter(app(ChapterReviewService::class)->listing(auth()->user(), $this->novel), fn ($r) => ($this->verdict === '' || $r['display_status'] === $this->verdict) && ($this->reviewerFilter === '' || $r['reviewer_type'] === $this->reviewerFilter)));
    }

    public function issues(): array
    {
        return array_values(array_filter($this->detail['issues'] ?? [], fn ($i) => ($this->severity === '' || $i['severity'] === $this->severity) && ($this->category === '' || $i['category'] === $this->category)));
    }

    private function payload(): array
    {
        $b = app(DraftingService::class)->read(auth()->user(), $this->novel, 'chapter', $this->version);
        $n = app(NovelIntentService::class)->novel(auth()->user(), $this->novel);

        return ['chapter_version_id' => $this->version, 'body_hash' => $b['body_hash'], 'extraction_id' => $this->extraction, 'policy_version_id' => $this->policy, 'expected_version' => $n->row_version, 'expected_state_version' => $n->state_version, 'offline_output' => $this->productionValue('output'), 'reviewer_type' => $this->reviewer, 'reason' => $this->reason];
    }

    public function review(): void
    {
        $this->attempt(function () {
            $this->detail = $this->productionWrite('ChapterReviewService.review', [], $this->payload(), fn ($frozenPayload, $commandKey) => app(ChapterReviewService::class)->review(auth()->user(), $this->novel, $frozenPayload, $commandKey));
            $this->selected = $this->detail['id'];
            $this->notice = '评分与问题已保存；通过仅为候选资格，仍需人工阅读和正式提交。';
        });
    }

    public function inspect(): void
    {
        if (! preg_match('/^[1-9][0-9]{0,17}$/D', $this->selected)) {
            $this->notice = '请选择实际记录后读取。';

            return;
        }
        $this->attempt(function () {
            $this->detail = app(ChapterReviewService::class)->read(auth()->user(), $this->novel, $this->selected);
        });
    }

    public function decide(): void
    {
        $this->attempt(function () {
            $this->productionWrite('ChapterReviewService.decide', [$this->selected], ['issue_id' => $this->issue, 'decision' => $this->decision, 'reason' => $this->reason], fn ($frozenPayload, $commandKey) => app(ChapterReviewService::class)->decide(auth()->user(), $this->novel, $this->selected, $frozenPayload, $commandKey));
            $this->inspect();
            $this->notice = '文学提示意见已追加；原评审和硬门禁不变。';
        });
    }

    public function policyAction(string $action): void
    {
        $this->attempt(function () use ($action) {
            $d = $action === 'save' ? ['key' => $this->policyKey, 'expected_version' => $this->policyVersion, 'style_version_id' => $this->style, 'content' => $this->productionValue('policyJson'), 'reason' => $this->reason] : ['policy_version_id' => $this->policy, 'reason' => $this->reason];
            $p = $this->productionWrite('ReviewPolicyService.execute', [$action], $d, fn ($frozenPayload, $commandKey) => app(ReviewPolicyService::class)->execute(auth()->user(), $action, $frozenPayload, $commandKey));
            $this->policy = $p['id'];
            $this->policyVersion = $p['version'];
            $this->notice = '策略状态：'.$p['status'].'。仅影响明确选择它的新评审。';
        });
    }

    public function loadPolicy(): void
    {
        $this->attempt(function () {
            $p = app(ReviewPolicyService::class)->read(auth()->user(), $this->policy);
            $this->policyKey = $p['key'];
            $this->policyVersion = $p['version'];
            $this->style = $p['style_version_id'];
            $this->policyJson = json_encode($p['content'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $this->comparison = ['selected' => $p, 'compared' => $this->comparePolicy === '' ? null : app(ReviewPolicyService::class)->read(auth()->user(), $this->comparePolicy), 'style' => app(AuthoringConfigurationService::class)->version('style', $p['style_version_id'])];
        });
    }

    public function startPipeline(): void
    {
        $this->attempt(function () {
            $d = $this->payload();
            unset($d['reviewer_type']);
            $b = app(DraftingService::class)->read(auth()->user(), $this->novel, 'chapter', $this->version);
            $route = preg_match('/^[1-9][0-9]{0,17}$/D', $this->route) ? DB::table('route_versions')->find($this->route) : null;
            $params = $route ? json_decode($route->content, true)['parameters'] : [];
            $r = $this->productionWrite('ReviewPipelineService.start', [], $d + ['route_version_id' => $this->route, 'billing' => ['policy_id' => $this->budget, 'price_version_ids' => array_values(array_filter(array_map('trim', explode(',', $this->prices)))), 'category' => 'review', 'chapter_id' => $b['node_id'], 'max_output_tokens' => $params['max_output_tokens'] ?? 0, 'max_reasoning_tokens' => 0]], fn ($frozenPayload, $commandKey) => app(ReviewPipelineService::class)->start(auth()->user(), $this->novel, $frozenPayload, $commandKey));
            $this->run = $r['run_id'];
            $this->notice = '离线评审运行已冻结，尚未代表评审通过。';
        });
    }

    public function replayPipeline(): void
    {
        $this->attempt(function () {
            app(GenerationService::class)->read(auth()->user(), $this->novel, $this->run);
            $event = DB::table('outbox_events')->where('run_id', $this->run)->orderByDesc('id')->value('id');
            if (! $event) {
                throw new NovelProblem('NOT_FOUND', '运行没有投递意图。', 404);
            }app(OutboxService::class)->consume((string) $event);
            $id = DB::table('reviews')->where('run_id', $this->run)->value('id');
            if ($id) {
                $this->selected = (string) $id;
                $this->inspect();
            }$this->notice = '运行状态：'.app(GenerationService::class)->read(auth()->user(), $this->novel, $this->run)['run']['status'];
        });
    }

    /** 编辑字段结构来自已实现契约。 */
    protected function productionPayloads(): array
    {
        return ['output' => ReviewContract::schema(), 'policyJson' => ProductionSchemas::infer(ReviewContract::defaults())];
    }

    #[Locked]
    public ?array $reviewSource = null;

    /** 先阅读实际正文再逐维评分；读取不创建评审或模型请求。 */
    public function loadReviewSource(): void
    {
        $this->reviewSource = null;
        $this->attempt(function () {
            if (! preg_match('/^[1-9][0-9]{0,17}$/D', $this->version)) {
                throw ValidationException::withMessages(['version' => '请选择实际章节正文版本。']);
            }
            $this->reviewSource = app(DraftingService::class)->read(auth()->user(), $this->novel, 'chapter', $this->version);
        });
    }

    public function updatedVersion(): void
    {
        $this->reviewSource = null;
        $this->extraction = '';
    }

    public function updatedNovel(): void
    {
        $this->version = $this->extraction = $this->selected = $this->run = '';
        $this->detail = $this->reviewSource = null;
        $this->output = '';
    }

    /** 唯一精确匹配才定位，重复引文不猜测；偏移使用UTF8码点而非JS字符串长度。 */
    public function locateReviewQuote(string $path): void
    {
        $this->treePath($path);
        if (explode('.', $path)[1] !== 'output' || ! $this->reviewSource || $this->reviewSource['id'] !== $this->version || $this->reviewSource['novel_id'] !== $this->novel) {
            throw ValidationException::withMessages([$path => '请先读取当前章节正文。']);
        }
        $node = data_get($this, $path);
        $quote = collect($node['children'])->firstWhere('key', 'quote')['node']['value'] ?? '';
        $body = $this->reviewSource['body'];
        $at = $quote === '' ? false : mb_strpos($body, $quote, 0, 'UTF-8');
        if ($at === false || mb_strpos($body, $quote, $at + 1, 'UTF-8') !== false) {
            throw ValidationException::withMessages([$path => '引文不存在或重复，请核对原文并明确偏移，不会猜测位置。']);
        }
        foreach ($node['children'] as $i => $row) {
            if (in_array($row['key'], ['start', 'end'], true)) {
                $node['children'][$i]['node'] = JsonFields::node($row['key'] === 'start' ? $at : $at + mb_strlen($quote, 'UTF-8'));
            }
        }
        data_set($this, $path, $node);
        $this->syncJsonInputs();
    }
}
