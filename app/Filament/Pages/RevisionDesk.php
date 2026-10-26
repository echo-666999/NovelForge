<?php

namespace App\Filament\Pages;

use App\Exceptions\NovelProblem;
use App\Services\ChapterReviewService;
use App\Services\GenerationService;
use App\Services\NovelIntentService;
use App\Services\OutboxService;
use App\Services\RevisionPipelineService;
use App\Services\RevisionService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** UI14先展示原文、范围与差异，再允许逐项采纳；不自动改写整章。 */
class RevisionDesk extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $navigationLabel = '受限修订工作台';

    protected static ?string $title = '受限修订与完整章节复评';

    protected string $view = 'filament.revision-desk';

    public string $novel = '';

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

    private function attempt(callable $fn): void
    {
        try {
            $fn();
        } catch (NovelProblem|ValidationException|\JsonException $e) {
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
            $this->detail = app(RevisionService::class)->create(auth()->user(), $this->novel, ['review_id' => $this->review, 'issue_ids' => array_values(array_filter(array_map('trim', explode(',', $this->issues)))), 'allowed_scope' => json_decode($this->scopeJson, true, 64, JSON_THROW_ON_ERROR), 'preserve_facts' => array_values(array_filter(array_map('trim', explode(',', $this->facts)))), 'preserve_quotes' => json_decode($this->protectedJson, true, 64, JSON_THROW_ON_ERROR), 'mode' => $this->mode, 'expected_version' => $n->row_version, 'expected_state_version' => $n->state_version, 'reason' => $this->reason], (string) Str::uuid());
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
        $this->attempt(function () {
            $this->detail = app(RevisionService::class)->read(auth()->user(), $this->novel, $this->selected);
            $this->run = $this->detail['run_id'] ?? '';
        });
    }

    public function propose(): void
    {
        $this->attempt(function () {
            $this->detail = app(RevisionService::class)->propose(auth()->user(), $this->novel, $this->selected, ['offline_output' => json_decode($this->patchJson, true, 64, JSON_THROW_ON_ERROR), 'reason' => $this->reason], (string) Str::uuid());
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
            }$this->detail = app(RevisionService::class)->decide(auth()->user(), $this->novel, $this->selected, $d, (string) Str::uuid());
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
            $this->detail = app(RevisionService::class)->confirm(auth()->user(), $this->novel, $this->selected, ['reason' => $this->reason], (string) Str::uuid());
            $this->notice = '已追加人工候选确认；旧评分和失败记录不变，尚未正式提交。';
        });
    }

    public function assess(): void
    {
        $this->attempt(function () {
            $this->detail = app(RevisionService::class)->assess(auth()->user(), $this->novel, $this->selected, ['extraction_output' => json_decode($this->extractionJson, true, 64, JSON_THROW_ON_ERROR), 'review_output' => json_decode($this->reviewJson, true, 64, JSON_THROW_ON_ERROR), 'reviewer_type' => $this->reviewer, 'reason' => $this->reason], (string) Str::uuid());
            $this->notice = '新正文已重新取证并整章复评：'.$this->detail['display_status'].'；未提交正式剧情。';
        });
    }

    public function startPipeline(): void
    {
        $this->attempt(function () {
            $t = app(RevisionService::class)->read(auth()->user(), $this->novel, $this->selected);
            $route = preg_match('/^[1-9][0-9]{0,17}$/D', $this->route) ? DB::table('route_versions')->find($this->route) : null;
            $params = $route ? json_decode($route->content, true)['parameters'] : [];
            $r = app(RevisionPipelineService::class)->start(auth()->user(), $this->novel, $this->selected, ['route_version_id' => $this->route, 'offline_output' => json_decode($this->patchJson, true, 64, JSON_THROW_ON_ERROR), 'billing' => ['policy_id' => $this->budget, 'price_version_ids' => array_values(array_filter(array_map('trim', explode(',', $this->prices)))), 'category' => 'generation', 'chapter_id' => $t['chapter_node_id'], 'max_output_tokens' => $params['max_output_tokens'] ?? 0, 'max_reasoning_tokens' => 0], 'reason' => $this->reason], (string) Str::uuid());
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
}
