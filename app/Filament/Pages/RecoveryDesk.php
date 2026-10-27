<?php

namespace App\Filament\Pages;

use App\Exceptions\NovelProblem;
use App\Filament\Concerns\HasNovelNavigation;
use App\Filament\Concerns\HasProductionChoices;
use App\Services\RecoveryService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/** 人工处理卡只调用与API相同的服务，没有强制通过或直接改写正文。 */
class RecoveryDesk extends Page
{
    use HasNovelNavigation;
    use HasProductionChoices;

    // UI03：分组与顺序显式固定，不依赖页面发现次序。
    protected static string|\UnitEnum|null $navigationGroup = '生产与质量';

    protected static ?int $navigationSort = 70;

    protected static ?string $title = '运行恢复台';

    protected string $view = 'filament.recovery-desk';

    #[Url]
    public string $run = '';

    public string $scope = '';

    public string $reason = '';

    public string $modelVersion = '';

    public string $changedScopes = '';

    public string $filter = '';

    public string $notice = '';

    #[Locked]
    public ?array $preview = null;

    #[Locked]
    public string $commandKey = '';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public function runs(): array
    {
        // 范围始终限制为当前管理员自己的书；没有数据库运行时展示空态。
        return DB::table('generation_runs')->whereIn('novel_id', DB::table('novels')->where('owner_id', auth()->id())->select('id'))->orderByDesc('id')->limit(100)->get(['id', 'novel_id', 'status'])->all();
    }

    /** 切换运行后必须重新核对恢复预览，旧决定不能落到新运行。 */
    public function updatedRun(): void
    {
        $this->preview = null;
        $this->commandKey = '';
    }

    public function inspect(): void
    {
        $this->preview = null;
        $this->notice = '';
        try {
            $this->preview = app(RecoveryService::class)->preview(auth()->user(), $this->novel, $this->run, $this->changes());
            $this->commandKey = (string) Str::uuid();
        } catch (NovelProblem $e) {
            $this->notice = $e->getMessage();
        }
    }

    private function changes(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->changedScopes))));
    }

    public function decide(string $action): void
    {
        if (! $this->preview || $this->preview['run_id'] !== $this->run || $this->preview['novel_id'] !== $this->novel) {
            $this->notice = '请先预览当前运行。';

            return;
        }
        try {
            $payload = ['reason' => $this->reason, 'preview_hash' => $this->preview['preview_hash'], 'changed_scopes' => $this->changes()];
            if ($this->scope !== '') {
                $payload['scope'] = $this->scope;
            }
            if ($this->modelVersion !== '') {
                $payload['model_version_id'] = $this->modelVersion;
            }
            $result = app(RecoveryService::class)->execute(auth()->user(), $this->novel, $this->run, $action, $payload, $this->commandKey);
            $this->inspect();
            $this->notice = $result['outcome'] === 'applied' ? '操作已保存，原产物及费用记录保留。' : '决策及来源已保存；相关业务执行器尚未实现，未执行提取或改写。';
        } catch (NovelProblem|ValidationException $e) {
            if ($e instanceof ValidationException) {
                $this->productionErrors($e);
            }
            $this->notice = $e instanceof NovelProblem ? $e->getMessage() : '请填写原因，并检查动作和字段。';
        }
    }

    /** 新书必须重新读取该书的运行与恢复预览。 */
    public function updatedNovel(): void
    {
        $this->run = $this->commandKey = '';
        $this->preview = null;
    }
}
