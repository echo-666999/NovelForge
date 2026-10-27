<?php

namespace App\Filament\Pages;

use App\Exceptions\NovelProblem;
use App\Filament\Concerns\HasNovelNavigation;
use App\Filament\Concerns\HasProductionChoices;
use App\Filament\Concerns\ReadsProductionFrames;
use App\Services\LayeredMemoryService;
use App\Services\NovelIntentService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/** 记忆库只维护作者侧候选摘要，不把声明的角色范围当作知识证明。 */
class MemoryLibrary extends Page
{
    use HasNovelNavigation;
    use HasProductionChoices;
    use ReadsProductionFrames;

    // UI03：分组与顺序显式固定，不依赖页面发现次序。
    protected static string|\UnitEnum|null $navigationGroup = '检索与记忆';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = '分层记忆库';

    protected string $view = 'filament.memory-library';

    #[Url]
    public string $branch = '';

    public string $kind = '';

    public string $status = '';

    public string $indexStatus = '';

    public string $character = '';

    public int $chapter = 100000;

    public int $page = 1;

    public array $form = [];

    public string $source = '';

    public string $evidence = '';

    public string $category = 'irreversible';

    public string $statement = '';

    public int $factChapter = 1;

    public string $reason = '';

    public string $notice = '';

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
        $this->newMemory();
        $this->refreshVersion();
    }

    public function updatedNovel(): void
    {
        $this->branch = '';
        $this->updatedBranch();
    }

    public function updatedBranch(): void
    {
        $this->detail = null;
        $this->newMemory();
        $this->source = '';
        $this->evidence = '';
        $this->page = 1;
        $this->refreshVersion();
    }

    public function newMemory(): void
    {
        $this->form = ['kind' => 'episodic', 'name' => '', 'summary' => '', 'introduced_chapter' => 1, 'visibility' => ['mode' => 'author_only', 'character_ids' => []], 'importance' => 3, 'source_ids' => [], 'fact_ids' => [], 'parent_ids' => [], 'chunk_size' => 1000];
    }

    public function books(): array
    {
        return DB::table('novels')->where('owner_id', auth()->id())->get(['id', 'title'])->all();
    }

    public function branches(): array
    {
        if ($this->novel === '') {
            return [];
        }
        app(NovelIntentService::class)->novel(auth()->user(), $this->novel);

        return DB::table('generation_runs')->where('novel_id', $this->novel)->orderByDesc('id')->get(['id', 'status'])->all();
    }

    public function options(): array
    {
        $empty = ['sources' => [], 'facts' => [], 'evidence' => [], 'memories' => [], 'characters' => []];
        if ($this->novel === '' || $this->branch === '') {
            return $empty;
        }
        $this->listing(); // 复用服务的同书分支鉴权，避免伪造页面属性跨书读取。
        $sources = DB::table('source_documents')->where('novel_id', $this->novel)->where('run_id', $this->branch)->get();

        return ['sources' => $sources->all(), 'facts' => DB::table('memory_facts')->where('novel_id', $this->novel)->where('branch_id', $this->branch)->get()->all(), 'evidence' => DB::table('evidence_spans')->where('novel_id', $this->novel)->whereIn('source_version_id', $sources->pluck('id'))->get()->all(), 'memories' => DB::table('memory_items')->where('novel_id', $this->novel)->where('branch_id', $this->branch)->where('status', 'sealed')->get(['id', 'version', 'collection_id'])->all(), 'characters' => DB::table('entities')->where('novel_id', $this->novel)->where('type', 'character')->whereNotNull('approved_version_id')->get(['id', 'name'])->all()];
    }

    public function listing(): ?array
    {
        if ($this->novel === '' || $this->branch === '') {
            return null;
        }
        $f = ['scope' => 'candidate', 'branch_id' => $this->branch, 'chapter' => $this->chapter, 'page' => $this->page];
        foreach (['kind' => 'kind', 'status' => 'status', 'indexStatus' => 'index_status', 'character' => 'character_id'] as $prop => $field) {
            if ($this->$prop !== '') {
                $f[$field] = $this->$prop;
            }
        }

        return app(LayeredMemoryService::class)->listing(auth()->user(), $this->novel, $f);
    }

    public function inspect(string $id): void
    {
        $this->attempt(function () use ($id) {
            $this->detail = app(LayeredMemoryService::class)->read(auth()->user(), $this->novel, $id, ['scope' => 'candidate', 'branch_id' => $this->branch]);
        });
    }

    public function loadRevision(): void
    {
        if (! $this->detail || $this->detail['novel_id'] !== $this->novel || $this->detail['branch_id'] !== $this->branch) {
            return;
        }
        $m = $this->detail;
        $this->form = array_intersect_key($m['payload'], array_flip(['summary', 'introduced_chapter', 'visibility', 'importance', 'source_ids', 'fact_ids', 'chunk_size']));
        $this->form += ['kind' => $m['kind'], 'name' => $m['name'], 'collection_id' => $m['collection_id'], 'parent_ids' => [$m['id']]];
        if (! $m['report']['passed']) {
            $this->form['retry_of_id'] = $m['id'];
        }
        $this->refreshVersion();
        $this->notice = '已载入修订输入，请修改摘要后保存新候选；未调用模型。';
    }

    public function registerFact(): void
    {
        $this->command('fact', ['source_version_id' => $this->source, 'evidence_id' => $this->evidence, 'category' => $this->category, 'statement' => $this->statement, 'introduced_chapter' => $this->factChapter]);
    }

    public function saveMemory(): void
    {
        $this->command('save', $this->form);
    }

    public function activate(): void
    {
        $this->command('activate', ['memory_id' => $this->detail['id'] ?? null]);
    }

    public function disableMemory(): void
    {
        $this->command('invalidate', ['memory_id' => $this->detail['id'] ?? null]);
    }

    public function disableSource(string $id): void
    {
        $this->command('invalidate', ['source_version_id' => $id]);
    }

    public function refreshVersion(): void
    {
        $this->expectedVersion = $this->novel === '' ? null : app(NovelIntentService::class)->novel(auth()->user(), $this->novel)->row_version;
        $this->commandKey = (string) Str::uuid();
    }

    private function command(string $action, array $data): void
    {
        $this->attempt(function () use ($action, $data) {
            $r = app(LayeredMemoryService::class)->execute(auth()->user(), $this->novel, $action, $data + ['branch_id' => $this->branch, 'expected_version' => $this->expectedVersion, 'reason' => $this->reason], $this->commandKey);
            $this->expectedVersion = $r['row_version'];
            $this->commandKey = (string) Str::uuid();
            $id = $r['memory']['id'] ?? ($this->detail['id'] ?? null);
            if ($id) {
                $this->detail = app(LayeredMemoryService::class)->read(auth()->user(), $this->novel, $id, ['scope' => 'candidate', 'branch_id' => $this->branch]);
            }
            $this->notice = isset($r['memory']) && ! $r['memory']['report']['passed'] ? '候选校验失败，已保留报告；旧活动记忆未替换。' : '记忆操作已保存；正式故事状态未改变。';
        });
    }

    private function attempt(callable $fn): void
    {
        $this->notice = '';
        try {
            $fn();
        } catch (NovelProblem|ValidationException $e) {
            if ($e instanceof ValidationException) {
                $this->productionErrors($e);
            }
            $this->notice = $e instanceof NovelProblem ? $e->getMessage() : implode(' ', $e->validator->errors()->all());
        }
    }
}
