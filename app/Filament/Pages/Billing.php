<?php

namespace App\Filament\Pages;

use App\Exceptions\NovelProblem;
use App\Filament\Concerns\EditsProductionPayloads;
use App\Filament\Concerns\HasProductionChoices;
use App\Filament\Concerns\ReadsProductionFrames;
use App\Filament\Support\ProductionSchemas;
use App\Services\BillingConfigurationService;
use App\Services\NovelIntentService;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/** 费用、预留和未知风险分开显示，不用零金额掩盖未核销请求。 */
class Billing extends Page
{
    use EditsProductionPayloads;
    use HasProductionChoices;
    use ReadsProductionFrames;

    // UI03：分组与顺序显式固定，不依赖页面发现次序。
    protected static string|\UnitEnum|null $navigationGroup = '配置与运维';

    protected static ?int $navigationSort = 50;

    protected static ?string $title = '价格与原子预算';

    protected string $view = 'filament.billing';

    #[Url]
    public string $novel = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $currency = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $run = '';

    #[Url]
    public string $model = '';

    #[Url]
    public string $requestStatus = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    #[Url]
    public int $page = 1;

    public string $pricePayload = '';

    public string $policyPayload = '';

    public string $reconcilePayload = '';

    public string $billingNotice = '';

    #[Locked]
    public array $billingCommandKeys = [];

    /** 同一动作与完全相同的输入复用幂等键，不因重复点击新增价格或费用。 */
    public function saveBilling(string $action): void
    {
        $property = ['price' => 'pricePayload', 'policy' => 'policyPayload', 'reconcile' => 'reconcilePayload'][$action] ?? null;
        abort_unless($property, 422);
        $this->billingNotice = '';
        $this->beginProductionAction();
        try {
            $data = $this->productionValue($property);
            $hash = hash('sha256', NovelIntentService::canonical([$action, $data]));
            $key = $this->billingCommandKeys[$hash] ??= (string) Str::uuid();
            app(BillingConfigurationService::class)->execute(auth()->user(), $action, $data, $key);
            $this->billingNotice = '已保存版本或凭证；相同输入重试复用原记录，不调用模型。';
        } catch (NovelProblem|ValidationException $e) {
            if ($e instanceof ValidationException) {
                $this->productionErrors($e);
            }
            $this->billingNotice = $e instanceof ValidationException ? implode(' ', $e->validator->errors()->all()) : $e->getMessage();
        }
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public function updated(string $field): void
    {
        if ($field !== 'page') {
            $this->page = 1;
        }
    }

    public function catalog(): array
    {
        return app(BillingConfigurationService::class)->listing(auth()->user(), ['novel_id' => $this->novel ?: null, 'status' => $this->status ?: null, 'currency' => $this->currency ?: null, 'category' => $this->category ?: null, 'page' => $this->page, 'run_id' => $this->run ?: null, 'model_profile_version_id' => $this->model ?: null, 'request_status' => $this->requestStatus ?: null, 'date_from' => $this->dateFrom ?: null, 'date_to' => $this->dateTo ?: null]);
    }

    /** 编辑字段结构来自已实现契约。 */
    protected function productionPayloads(): array
    {
        return ['pricePayload' => ProductionSchemas::billing('price'), 'policyPayload' => ProductionSchemas::billing('policy'), 'reconcilePayload' => ProductionSchemas::billing('reconcile')];
    }
}
