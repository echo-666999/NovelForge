<?php

namespace App\Filament\Pages;

use App\Exceptions\NovelProblem;
use App\Services\CanonicalMemoryService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

/** UI22/23/24正式派生诊断，不提供修改正式事实或隐式放宽POV的动作。 */
class CanonicalMemoryCenter extends Page
{
    protected static ?string $title = '正式记忆与索引';

    protected static string|\UnitEnum|null $navigationGroup = '生产与质量';

    protected string $view = 'filament.canonical-memory-center';

    #[Url]
    public string $novel = '';

    public string $reason = '';

    public string $notice = '';

    public string $query = '';

    public string $pov = '';

    public string $audience = 'author';

    public int $chapter = 1;

    public bool $vector = true;

    public ?array $result = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public function books(): array
    {
        return DB::table('novels')->where('owner_id', auth()->id())->get(['id', 'title'])->all();
    }

    public function state(): ?array
    {
        return $this->novel === '' ? null : app(CanonicalMemoryService::class)->dashboard(auth()->user(), $this->novel);
    }

    public function memories(): array
    {
        $s = $this->state();

        return ! $s || ! $s['revision_id'] ? [] : app(CanonicalMemoryService::class)->memories(auth()->user(), $this->novel, ['revision_id' => $s['revision_id']])['items'];
    }

    public function retry(string $id): void
    {
        $this->attempt(fn () => app(CanonicalMemoryService::class)->retry(auth()->user(), $this->novel, $id, $this->reason, (string) Str::uuid()));
    }

    public function search(): void
    {
        $this->result = null;
        $this->attempt(function () {
            $s = $this->state();
            $this->result = app(CanonicalMemoryService::class)->search(auth()->user(), $this->novel, ['revision_id' => $s['revision_id'] ?? '', 'chapter' => $this->chapter, 'query' => $this->query, 'audience' => $this->audience, 'pov_id' => $this->pov, 'vector' => $this->vector]);
        });
    }

    public function updatedNovel(): void
    {
        $this->result = null;
        $this->notice = '';
    }

    private function attempt(callable $fn): void
    {
        $this->notice = '';
        try {
            $fn();
            $this->notice = '已处理；摘要和索引不改写正式事实。';
        } catch (NovelProblem|ValidationException $e) {
            $this->notice = $e instanceof NovelProblem ? $e->getMessage() : implode(' ', $e->validator->errors()->all());
        }
    }
}
