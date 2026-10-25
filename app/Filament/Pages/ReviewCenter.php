<?php

namespace App\Filament\Pages;

use App\Exceptions\NovelProblem;
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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** UI13/UI27：只读原文、评分与问题，非阻塞意见不能改写硬门禁。 */
class ReviewCenter extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = '评审中心与策略';

    protected static ?string $title = '评审中心与文风策略';

    protected string $view = 'filament.review-center';

    public string $novel = '';

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

    private function attempt(callable $fn): void
    {
        try {
            $fn();
        } catch (NovelProblem|ValidationException|\JsonException $e) {
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

        return ['chapter_version_id' => $this->version, 'body_hash' => $b['body_hash'], 'extraction_id' => $this->extraction, 'policy_version_id' => $this->policy, 'expected_version' => $n->row_version, 'expected_state_version' => $n->state_version, 'offline_output' => json_decode($this->output, true, 64, JSON_THROW_ON_ERROR), 'reviewer_type' => $this->reviewer, 'reason' => $this->reason];
    }

    public function review(): void
    {
        $this->attempt(function () {
            $this->detail = app(ChapterReviewService::class)->review(auth()->user(), $this->novel, $this->payload(), (string) Str::uuid());
            $this->selected = $this->detail['id'];
            $this->notice = '评分与问题已保存；通过仅为候选资格，仍需人工阅读和正式提交。';
        });
    }

    public function inspect(): void
    {
        $this->attempt(function () {
            $this->detail = app(ChapterReviewService::class)->read(auth()->user(), $this->novel, $this->selected);
        });
    }

    public function decide(): void
    {
        $this->attempt(function () {
            app(ChapterReviewService::class)->decide(auth()->user(), $this->novel, $this->selected, ['issue_id' => $this->issue, 'decision' => $this->decision, 'reason' => $this->reason], (string) Str::uuid());
            $this->inspect();
            $this->notice = '文学提示意见已追加；原评审和硬门禁不变。';
        });
    }

    public function policyAction(string $action): void
    {
        $this->attempt(function () use ($action) {
            $d = $action === 'save' ? ['key' => $this->policyKey, 'expected_version' => $this->policyVersion, 'style_version_id' => $this->style, 'content' => json_decode($this->policyJson, true, 64, JSON_THROW_ON_ERROR), 'reason' => $this->reason] : ['policy_version_id' => $this->policy, 'reason' => $this->reason];
            $p = app(ReviewPolicyService::class)->execute(auth()->user(), $action, $d, (string) Str::uuid());
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
            $r = app(ReviewPipelineService::class)->start(auth()->user(), $this->novel, $d + ['route_version_id' => $this->route, 'billing' => ['policy_id' => $this->budget, 'price_version_ids' => array_values(array_filter(array_map('trim', explode(',', $this->prices)))), 'category' => 'review', 'chapter_id' => $b['node_id'], 'max_output_tokens' => $params['max_output_tokens'] ?? 0, 'max_reasoning_tokens' => 0]], (string) Str::uuid());
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
}
