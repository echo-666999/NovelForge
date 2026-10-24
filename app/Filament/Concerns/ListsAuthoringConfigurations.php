<?php

namespace App\Filament\Concerns;

use App\Exceptions\NovelProblem;
use App\Services\AuthoringConfigurationService;
use Livewire\Attributes\Url;

/** 三个配置页面复用筛选与只读比较，不在页面直接更新数据库。 */
trait ListsAuthoringConfigurations
{
    #[Url]
    public string $q = '';

    #[Url]
    public string $stage = '';

    #[Url]
    public string $language = '';

    #[Url]
    public string $status = '';

    #[Url]
    public int $page = 1;

    #[Url]
    public string $compareLeft = '';

    #[Url]
    public string $compareRight = '';

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
        return app(AuthoringConfigurationService::class)->listing(auth()->user(), $this->configurationKind(), ['q' => $this->q, 'stage' => $this->stage, 'language' => $this->language, 'status' => $this->status ?: null, 'page' => $this->page]);
    }

    public function comparison(): ?array
    {
        if (! $this->compareLeft || ! $this->compareRight) {
            return null;
        }
        try {
            $service = app(AuthoringConfigurationService::class);
            $left = $service->version($this->configurationKind(), $this->compareLeft);
            $right = $service->version($this->configurationKind(), $this->compareRight);
            if ($left['parent_id'] !== $right['parent_id']) {
                return ['error' => '只能比较同一配置的两个版本。'];
            }

            return ['left' => $left, 'right' => $right];
        } catch (NovelProblem) {
            return ['error' => '比较版本不存在或不可用。'];
        }
    }
}
