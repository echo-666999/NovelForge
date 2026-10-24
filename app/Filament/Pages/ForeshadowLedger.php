<?php

namespace App\Filament\Pages;

use App\Exceptions\NovelProblem;
use App\Services\ForeshadowLedgerService;
use App\Services\NovelIntentService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/** 作者侧伏笔与主支线账本，明确显示未知的正式证据进度。 */
class ForeshadowLedger extends Page
{
    protected static ?string $title = '伏笔与主支线账本';

    protected static string|\UnitEnum|null $navigationGroup = '创作管理';

    protected string $view = 'filament.foreshadow-ledger';

    #[Url]
    public string $novel = '';

    public int $chapter = 1;

    public string $dueFilter = '';

    public bool $requiredOnly = false;

    public string $entityFilter = '';

    public string $lifecycleFilter = '';

    public string $threadKind = '';

    public string $from = '';

    public string $to = '';

    public string $reason = '';

    public string $alternative = '';

    public string $notice = '';

    public array $form = [];

    #[Locked]
    public ?array $detail = null;

    #[Locked]
    public ?int $expectedVersion = null;

    #[Locked]
    public string $commandKey = '';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public function mount(): void
    {
        $this->resetForm();
        $this->refreshVersion();
    }

    public function updatedNovel(): void
    {
        $this->detail = null;
        $this->resetForm();
        $this->refreshVersion();
    }

    public function resetForm(): void
    {
        $this->form = array_fill_keys(ForeshadowLedgerService::TEXT, '');
        $this->form += ['blueprint_version_id' => '', 'thread_id' => null, 'plant_start' => 1, 'plant_end' => 5, 'payoff_target' => 100, 'payoff_start' => 100, 'payoff_end' => 100, 'required' => true, 'importance' => 'major', 'secrecy' => 'author_only', 'reinforcements' => [], 'entity_version_ids' => [], 'knower_version_ids' => [], 'prerequisite_event_ids' => []];
    }

    public function books(): array
    {
        return DB::table('novels')->where('owner_id', auth()->id())->get(['id', 'title'])->all();
    }

    // 所有选项查询先鉴权，不能通过伪造 Livewire 小说标识读取其他作品。
    public function options(): array
    {
        if ($this->novel === '') {
            return ['blueprints' => [], 'threads' => [], 'entities' => [], 'events' => []];
        }
        app(NovelIntentService::class)->novel(auth()->user(), $this->novel);

        return ['blueprints' => DB::table('blueprint_versions')->where('novel_id', $this->novel)->where('status', 'sealed')->orderByDesc('version')->get()->all(), 'threads' => DB::table('blueprint_thread_versions')->where('novel_id', $this->novel)->where('blueprint_version_id', $this->form['blueprint_version_id'] ?: null)->get()->all(), 'entities' => DB::table('entities')->where('novel_id', $this->novel)->whereNotNull('approved_version_id')->get()->all(), 'events' => DB::table('proposed_events')->where('novel_id', $this->novel)->orderBy('id')->get(['id', 'chapter_sequence', 'attribute'])->all()];
    }

    public function ledger(): ?array
    {
        if ($this->novel === '') {
            return null;
        }
        $f = ['chapter' => $this->chapter];
        if (in_array($this->dueFilter, ['due', 'overdue', 'no_evidence'], true)) {
            $f[$this->dueFilter] = true;
        }
        if ($this->requiredOnly) {
            $f['required'] = true;
        }
        foreach (['entityFilter' => 'entity_id', 'lifecycleFilter' => 'lifecycle', 'threadKind' => 'thread_kind', 'from' => 'from', 'to' => 'to'] as $prop => $field) {
            if ($this->$prop !== '') {
                $f[$field] = $this->$prop;
            }
        }

        return app(ForeshadowLedgerService::class)->ledger(auth()->user(), $this->novel, $f);
    }

    public function inspect(string $id): void
    {
        $this->attempt(function () use ($id) {
            $this->detail = app(ForeshadowLedgerService::class)->read(auth()->user(), $this->novel, $id);
            $this->form = $this->detail['payload'];
            $this->refreshVersion();
        });
    }

    public function addReinforcement(): void
    {
        $this->form['reinforcements'][] = ['start' => 20, 'end' => 25, 'new_information' => '', 'choice_impact' => ''];
    }

    public function removeReinforcement(int $index): void
    {
        array_splice($this->form['reinforcements'], $index, 1);
    }

    public function createPlan(): void
    {
        $this->command('create', ['plan' => $this->form]);
    }

    public function freezePlan(): void
    {
        $this->command('freeze', ['plan_id' => $this->detail['id'] ?? null]);
    }

    public function propose(string $kind): void
    {
        $this->command('propose', ['plan_id' => $this->detail['id'] ?? null, 'kind' => $kind, 'plan' => $this->form, 'alternative' => $this->alternative]);
    }

    public function decide(string $id, string $decision): void
    {
        $this->command('decide', ['proposal_id' => $id, 'decision' => $decision]);
    }

    public function refreshVersion(): void
    {
        $this->expectedVersion = $this->novel === '' ? null : app(NovelIntentService::class)->novel(auth()->user(), $this->novel)->row_version;
        $this->commandKey = (string) Str::uuid();
    }

    private function command(string $action, array $data): void
    {
        $this->attempt(function () use ($action, $data) {
            $r = app(ForeshadowLedgerService::class)->execute(auth()->user(), $this->novel, $action, $data + ['expected_version' => $this->expectedVersion, 'reason' => $this->reason], $this->commandKey);
            $this->expectedVersion = $r['row_version'];
            $this->commandKey = (string) Str::uuid();
            $id = $r['plan']['id'] ?? ($this->detail['id'] ?? null);
            if ($id) {
                $this->detail = app(ForeshadowLedgerService::class)->read(auth()->user(), $this->novel, $id);
            }
            $this->notice = '已保存作者规划记录，正式剧情进度未改变。';
        });
    }

    private function attempt(callable $fn): void
    {
        $this->notice = '';
        try {
            $fn();
        } catch (NovelProblem|ValidationException $e) {
            $this->notice = $e instanceof NovelProblem ? $e->getMessage() : implode(' ', $e->validator->errors()->all());
        }
    }
}
