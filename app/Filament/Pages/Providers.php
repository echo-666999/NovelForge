<?php

namespace App\Filament\Pages;

use App\Services\NovelIntentService;
use App\Services\ProviderConfigurationService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/** 只读安全投影与原生POST表单，密钥永不进入页面状态。 */
class Providers extends Page
{
    // UI03：分组与顺序显式固定，不依赖页面发现次序。
    protected static string|\UnitEnum|null $navigationGroup = '配置与运维';

    protected static ?int $navigationSort = 20;

    protected static ?string $title = '供应商与模型';

    protected string $view = 'filament.providers';

    #[Url]
    public string $q = '';

    #[Url]
    public string $interface = '';

    #[Url]
    public string $enabled = '';

    #[Url]
    public int $page = 1;

    #[Url]
    public string $structured = '';

    #[Locked]
    public array $historyVersions = [];

    #[Locked]
    public string $historyTitle = '';

    public string $historyLeft = '';

    public string $historyRight = '';

    #[Locked]
    public string $providerEditor = '';

    #[Locked]
    public string $modelEditor = '';

    /** 原生POST失败后只展开对应条目，其他历史表单不提前渲染。 */
    public function mount(): void
    {
        $form = session('provider_form', '');
        if (is_string($form) && preg_match('/^(provider|model)-(new|[1-9][0-9]{0,17})$/D', $form, $m)) {
            if ($m[1] === 'provider') {
                $this->providerEditor = $m[2];
            } else {
                $this->modelEditor = $m[2];
            }
        }
    }

    public function openProviderEditor(string $id): void
    {
        $catalog = $this->catalog();
        abort_unless(collect($catalog['providers'])->contains('id', $id), 404);
        $this->providerEditor = $id;
        $this->dispatch('provider-editor-opened', kind: 'provider');
    }

    public function openModelEditor(string $id): void
    {
        $catalog = $this->catalog();
        abort_unless(collect($catalog['models'])->contains('version_id', $id), 404);
        $this->modelEditor = $id;
        $this->dispatch('provider-editor-opened', kind: 'model');
    }

    /** UI23：仅切换展示状态；原生POST及服务契约不变，密钥不进入Livewire。 */
    public function beginProviderCreation(string $kind): void
    {
        app(NovelIntentService::class)->authorize(auth()->user());
        abort_unless(in_array($kind, ['provider', 'model'], true), 404);
        if ($kind === 'provider') {
            $this->providerEditor = 'new';
        } else {
            $this->modelEditor = 'new';
        }
        $this->dispatch('provider-editor-opened', kind: $kind);
    }

    /** 弹窗关闭只卸载对应编辑器，避免取消后残留未提交的密钥输入。 */
    public function closeProviderEditor(string $kind): void
    {
        if ($kind === 'provider') {
            $this->providerEditor = '';
        } elseif ($kind === 'model') {
            $this->modelEditor = '';
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

    /** 实际供应商选择器独立于列表分页，不读取凭据或secret引用。 */
    public function providerOptions(): array
    {
        app(NovelIntentService::class)->authorize(auth()->user());

        return DB::table('providers')->orderBy('name')->pluck('name', 'id')->all();
    }

    public function catalog(): array
    {
        return app(ProviderConfigurationService::class)->listing(auth()->user(), ['q' => $this->q ?: null, 'interface_type' => $this->interface ?: null, 'enabled' => $this->enabled === '' ? null : $this->enabled, 'declared_structured_output' => $this->structured ?: null, 'page' => $this->page]);
    }

    /** 只读历史严格选择安全列，密钥值、密文和secret引用不进入页面状态。 */
    public function readConfigurationHistory(string $kind, string $id): void
    {
        app(NovelIntentService::class)->authorize(auth()->user());
        abort_unless(preg_match('/^[1-9][0-9]{0,17}$/D', $id), 404);
        if ($kind === 'provider') {
            $name = DB::table('providers')->where('id', $id)->value('name');
            abort_unless($name, 404);
            $rows = DB::table('provider_versions')->where('provider_id', $id)->orderByDesc('version')->limit(100)
                ->select(['id', 'version', 'interface_type', 'endpoint', 'allowed_hosts', 'timeout_seconds', 'connect_timeout_seconds', 'requests_per_minute', 'max_concurrency', 'enabled', 'reason', 'created_at'])->selectRaw('secret_id IS NOT NULL AS has_key')->get();
            $this->historyTitle = $name.' · 供应商配置历史';
        } elseif ($kind === 'model') {
            $model = DB::table('model_profile_versions as v')->join('model_profiles as m', 'm.id', '=', 'v.model_profile_id')->where('v.id', $id)->first(['v.model_profile_id', 'm.provider_model_id']);
            abort_unless($model, 404);
            $rows = DB::table('model_profile_versions')->where('model_profile_id', $model->model_profile_id)->orderByDesc('version')->limit(100)->get(['id', 'version', 'declared_capabilities', 'verification_status', 'reason', 'created_at']);
            $this->historyTitle = $model->provider_model_id.' · 模型声明历史';
        } else {
            abort(404);
        }
        $this->historyVersions = [];
        foreach ($rows as $row) {
            $value = (array) $row;
            foreach (['allowed_hosts', 'declared_capabilities'] as $field) {
                if (isset($value[$field])) {
                    $value[$field] = json_decode($value[$field], true, flags: JSON_THROW_ON_ERROR);
                }
            }
            $this->historyVersions[(string) $row->id] = ['version' => $row->version, 'value' => $value];
        }
        $ids = array_keys($this->historyVersions);
        $this->historyRight = (string) ($ids[0] ?? '');
        $this->historyLeft = (string) ($ids[1] ?? $ids[0] ?? '');
        $this->dispatch('provider-history-opened');
    }
}
