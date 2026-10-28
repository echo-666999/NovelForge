<?php

namespace App\Filament\Pages;

use App\Exceptions\NovelProblem;
use App\Filament\Concerns\HasNovelNavigation;
use App\Services\NovelIntentService;
use App\Services\ReadingService;
use Filament\Pages\Page;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/** E08安全纯文本阅读器；问题和已读不等同于成品验收。 */
class FormalReader extends Page
{
    use HasNovelNavigation;

    protected static string|\UnitEnum|null $navigationGroup = '创作工作台';

    protected static ?int $navigationSort = 40;

    protected static ?string $title = '连续阅读与版本比较';

    protected string $view = 'filament.formal-reader';

    #[Url]
    public string $scope = 'formal';

    #[Url]
    public string $revision = '';

    #[Url]
    public int $chapter = 1;

    public int $count = 1;

    public string $fontSize = '18';

    public string $lineHeight = '1.8';

    public string $otherVersion = '';

    public string $quote = '';

    public int $offset = 0;

    public string $note = '';

    public string $notice = '';

    public string $filter = 'all';

    #[Locked]
    public ?array $comparison = null;

    #[Locked]
    public string $issueVersion = '';

    #[Locked]
    public string $observationKey = '';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public function mount(): void
    {
        // 请求发送前即冻结界面幂等种子，网络重试不会产生新的阅读身份。
        $this->observationKey = (string) Str::uuid();
    }

    public function updatedScope(): void
    {
        $this->revision = '';
        $this->comparison = null;
        $this->otherVersion = '';
    }

    /** 每次读取显式限制连续范围，既不回退候选也不一次渲染万章正文。 */
    public function catalog(): array
    {
        if ($this->novel === '') {
            return [];
        }
        Validator::make(['count' => $this->count, 'fontSize' => $this->fontSize, 'lineHeight' => $this->lineHeight, 'filter' => $this->filter], ['count' => 'integer|min:1|max:20', 'fontSize' => 'in:16,18,20,24', 'lineHeight' => 'in:1.6,1.8,2.0', 'filter' => 'in:all,unread,issues'])->validate();
        $result = app(ReadingService::class)->snapshot(auth()->user(), $this->novel, ['scope' => $this->scope, 'revision_id' => $this->revision ?: null, 'range' => ['type' => 'range', 'from' => $this->chapter, 'to' => $this->chapter + $this->count - 1]]);
        foreach ($result['items'] as &$item) {
            $q = DB::table('reading_observations')->where('novel_id', $this->novel)->where('actor_id', auth()->id())->where('chapter_version_id', $item['chapter_version_id'])->where('revision_id', $result['revision_id']);
            $item['read'] = $q->clone()->where('kind', 'read')->exists();
            $item['issues'] = $q->clone()->where('kind', 'issue')->get()->map(fn ($r) => (array) $r)->all();
            $item['versions'] = DB::table('chapter_versions')->where('novel_id', $this->novel)->where('node_id', $item['chapter_id'])->orderByDesc('version')->get(['id', 'version', 'title'])->map(fn ($r) => (array) $r)->all();
        }unset($item);

        $result['revisions'] = DB::table('canonical_revisions')->where('novel_id', $this->novel)->orderByDesc('id')->get(['id', 'version', 'cursor'])->map(fn ($r) => (array) $r)->all();

        return $result;
    }

    /** 读取错误单独展示，不能以空白书稿冒充无问题。 */
    public function frame(): array
    {
        try {
            return ['data' => $this->catalog(), 'error' => null];
        } catch (ValidationException $e) {
            return ['data' => [], 'error' => implode(' ', array_merge(...array_values($e->errors())))];
        } catch (NovelProblem $e) {
            return ['data' => [], 'error' => $e->getMessage()];
        } catch (QueryException) {
            return ['data' => [], 'error' => '资料暂不可读，请稍后刷新。'];
        }
    }

    public function turn(int $delta): void
    {
        $this->chapter = max(1, $this->chapter + ($delta < 0 ? -$this->count : $this->count));
        $this->comparison = null;
        $this->otherVersion = '';
        $this->quote = '';
        $this->note = '';
        $this->resetValidation();
    }

    /** 写入前重新从服务解析当前页，客户端版本或引文不能替换来源。 */
    /** 关闭只清理当前问题的展示错误，不写入阅读记录。 */
    public function closeIssue(): void
    {
        $this->issueVersion = '';
        $this->resetValidation();
        $this->dispatch('close-modal', id: 'nf-reading-issue');
    }

    public function beginIssue(string $version): void
    {
        abort_unless(collect($this->catalog()['items'])->contains('chapter_version_id', $version), 404);
        $this->issueVersion = $version;
        $this->quote = '';
        $this->note = '';
        $this->offset = 0;
        $this->resetValidation();
        $this->dispatch('open-modal', id: 'nf-reading-issue');
    }

    public function observe(string $version, string $kind): void
    {
        $this->resetValidation();
        try {
            $data = $this->catalog();
            $item = collect($data['items'])->firstWhere('chapter_version_id', $version) ?? throw new NovelProblem('NOT_FOUND', '版本不在当前阅读范围。', 404);
            $d = ['chapter_version_id' => $item['chapter_version_id'], 'revision_id' => $data['revision_id'], 'kind' => $kind, 'note' => $kind === 'read' ? '作者标记已读' : $this->note];
            if ($kind === 'issue') {
                $d += ['quote' => $this->quote, 'start_offset' => $this->offset, 'end_offset' => $this->offset + mb_strlen($this->quote, 'UTF-8')];
            }
            $hash = hash('sha256', NovelIntentService::canonical($d));
            $key = $hash.'-'.($this->observationKey ?: ($this->observationKey = (string) Str::uuid()));
            app(ReadingService::class)->observe(auth()->user(), $this->novel, $d, substr($key, 0, 128));
            $this->notice = '已保存阅读记录；未改变正式状态或作品认可。';
            if ($kind === 'issue') {
                $this->dispatch('close-modal', id: 'nf-reading-issue');
                $this->issueVersion = '';
            }
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());
        } catch (NovelProblem $e) {
            $this->addError('reading', $e->getMessage());
        }
    }

    public function compare(string $version): void
    {
        try {
            $this->comparison = app(ReadingService::class)->compare(auth()->user(), $this->novel, $version, $this->otherVersion);
            $this->dispatch('open-modal', id: 'nf-reading-diff');
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());
        } catch (NovelProblem $e) {
            $this->addError('reading', $e->getMessage());
        }
    }
}
