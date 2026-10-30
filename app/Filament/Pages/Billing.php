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
use Illuminate\Support\Facades\DB;
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

    /** UI24仅控制弹窗展示，不重置字段、价格版本或既有幂等键。 */
    #[Locked]
    public string $billingEditor = '';

    #[Locked]
    public bool $billingSaveFailed = false;

    #[Locked]
    public ?array $billingDetail = null;

    public function beginBillingEditor(string $action): void
    {
        app(NovelIntentService::class)->authorize(auth()->user());
        abort_unless(in_array($action, ['price', 'policy', 'reconcile'], true), 422);
        $this->resetValidation();
        $this->billingSaveFailed = false;
        $this->billingEditor = $action;
        $this->productionEditor($action.'Payload');
        $this->dispatch('billing-editor-opened');
    }

    /** 关闭只是原折叠编辑区的展示替代；保留相同输入及重试身份。 */
    public function closeBillingEditor(): void
    {
        $this->billingEditor = '';
        $this->resetValidation();
        $this->dispatch('close-modal', id: 'nf-billing-editor-modal');
    }

    /** 详情只取当前授权目录中的既有投影，不扩大账本或版本读取范围。 */
    public function inspectBilling(string $kind, string $id): void
    {
        $key = ['price' => 'prices', 'policy' => 'policies', 'request' => 'requests'][$kind] ?? null;
        abort_unless($key, 404);
        $row = collect($this->catalog()[$key])->firstWhere($kind === 'request' ? 'request_id' : 'id', $id);
        abort_unless($row, 404);
        $this->billingDetail = ['kind' => $kind, 'record' => $row];
        $this->dispatch('open-modal', id: 'nf-billing-detail-modal');
    }

    /** 同一动作与完全相同的输入复用幂等键，不因重复点击新增价格或费用。 */
    public function saveBilling(string $action): void
    {
        $property = ['price' => 'pricePayload', 'policy' => 'policyPayload', 'reconcile' => 'reconcilePayload'][$action] ?? null;
        abort_unless($property, 422);
        $this->billingNotice = '';
        $this->billingSaveFailed = false;
        $this->beginProductionAction();
        try {
            $data = $this->productionValue($property);
            $hash = hash('sha256', NovelIntentService::canonical([$action, $data]));
            $key = $this->billingCommandKeys[$hash] ??= (string) Str::uuid();
            app(BillingConfigurationService::class)->execute(auth()->user(), $action, $data, $key);
            $this->billingNotice = '已保存版本或凭证；相同输入重试复用原记录，不调用模型。';
            // UI24只在既有服务成功后关闭弹窗，保存与幂等过程保持原样。
            $this->billingEditor = '';
            $this->dispatch('close-modal', id: 'nf-billing-editor-modal');
        } catch (NovelProblem|ValidationException $e) {
            $this->billingSaveFailed = true;
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
        $catalog = app(BillingConfigurationService::class)->listing(auth()->user(), ['novel_id' => $this->novel ?: null, 'status' => $this->status ?: null, 'currency' => $this->currency ?: null, 'category' => $this->category ?: null, 'page' => $this->page, 'run_id' => $this->run ?: null, 'model_profile_version_id' => $this->model ?: null, 'request_status' => $this->requestStatus ?: null, 'date_from' => $this->dateFrom ?: null, 'date_to' => $this->dateTo ?: null]);
        // 按价格实际关联的能力版本批量读取模型名称，历史价格仍保留原始关联。
        $catalog['model_names'] = DB::table('model_profile_versions as v')
            ->join('model_profiles as m', 'm.id', '=', 'v.model_profile_id')
            ->whereIn('v.id', array_column($catalog['prices'], 'model_version_id'))
            ->pluck('m.provider_model_id', 'v.id')->all();

        return $catalog;
    }

    /** 编辑字段结构来自已实现契约。 */
    protected function productionPayloads(): array
    {
        return ['pricePayload' => ProductionSchemas::billing('price'), 'policyPayload' => ProductionSchemas::billing('policy'), 'reconcilePayload' => ProductionSchemas::billing('reconcile')];
    }
}
