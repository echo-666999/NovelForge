<?php

namespace App\Filament\Pages;

use App\Exceptions\NovelProblem;
use App\Services\HybridSearchService;
use App\Services\NovelIntentService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/** 检索实验室明确展示离线编码、索引代次、过滤范围和通道贡献。 */
class SearchLab extends Page
{
    protected static ?string $title = '检索实验室与索引';

    protected static string|\UnitEnum|null $navigationGroup = '创作管理';

    protected string $view = 'filament.search-lab';

    #[Url]
    public string $novel = '';

    public string $branch = '';

    public string $profile = '';

    public string $build = '';

    public string $profileName = '离线字符哈希';

    public int $dimensions = 128;

    public string $metric = 'cosine';

    public string $source = '';

    public string $evidence = '';

    public string $event = '';

    public string $memory = '';

    public string $chunk = '';

    public string $documentTitle = '';

    public string $aliases = '';

    public int $introduced = 1;

    public string $query = '';

    public int $chapter = 1;

    public string $audience = 'author';

    public string $pov = '';

    public string $context = '';

    public bool $vector = true;

    public string $reason = '';

    public string $notice = '';

    #[Locked]
    public ?array $result = null;

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
        $this->refreshVersion();
    }

    public function updatedNovel(): void
    {
        $this->branch = $this->profile = $this->build = '';
        $this->result = null;
        $this->refreshVersion();
    }

    public function books(): array
    {
        return DB::table('novels')->where('owner_id', auth()->id())->get(['id', 'title'])->all();
    }

    public function state(): ?array
    {
        return $this->novel === '' ? null : app(HybridSearchService::class)->listing(auth()->user(), $this->novel);
    }

    public function branches(): array
    {
        if (! $this->state()) {
            return [];
        }

        return DB::table('generation_runs')->where('novel_id', $this->novel)->get(['id', 'status'])->all();
    }

    public function refreshVersion(): void
    {
        $this->expectedVersion = $this->novel === '' ? null : app(NovelIntentService::class)->novel(auth()->user(), $this->novel)->row_version;
        $this->commandKey = (string) Str::uuid();
    }

    public function registerProfile(): void
    {
        $this->command('profile', ['name' => $this->profileName, 'encoder' => 'offline_hash_v1', 'dimensions' => $this->dimensions, 'distance_metric' => $this->metric]);
    }

    public function createBuild(): void
    {
        $this->command('build', ['branch_id' => $this->branch, 'profile_id' => $this->profile]);
    }

    public function addDocument(): void
    {
        $d = ['build_id' => $this->build, 'source_version_id' => $this->source, 'title' => $this->documentTitle, 'aliases' => array_values(array_filter(array_map('trim', explode("\n", $this->aliases)))), 'introduced_chapter' => $this->introduced];
        foreach (['evidence' => 'evidence_id', 'event' => 'event_id', 'memory' => 'memory_id', 'chunk' => 'chunk_id'] as $prop => $key) {
            if ($this->$prop !== '') {
                $d[$key] = $this->$prop;
            }
        }
        $this->command('document', $d);
    }

    public function validateBuild(): void
    {
        $this->command('validate', ['build_id' => $this->build]);
    }

    public function activateBuild(): void
    {
        $this->command('activate', ['build_id' => $this->build]);
    }

    public function runQuery(): void
    {
        $this->result = null;
        $this->attempt(function () {
            $d = ['scope' => 'candidate', 'branch_id' => $this->branch, 'build_id' => $this->build, 'profile_id' => $this->profile, 'query' => $this->query, 'chapter' => $this->chapter, 'audience' => $this->audience, 'vector' => $this->vector, 'limit' => 20];
            if ($this->audience === 'pov') {
                $d += ['pov_id' => $this->pov, 'context_id' => $this->context];
            }
            $this->result = app(HybridSearchService::class)->search(auth()->user(), $this->novel, $d);
        });
    }

    private function command(string $command, array $d): void
    {
        $this->attempt(function () use ($command, $d) {
            $r = app(HybridSearchService::class)->execute(auth()->user(), $this->novel, $command, $d + ['expected_version' => $this->expectedVersion, 'reason' => $this->reason], $this->commandKey);
            $this->expectedVersion = $r['row_version'];
            $this->commandKey = (string) Str::uuid();
            if (isset($r['profile_id'])) {
                $this->profile = $r['profile_id'];
            }if (isset($r['build_id'])) {
                $this->build = $r['build_id'];
            }
            $this->notice = '索引操作已保存；没有调用真实模型。';
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
