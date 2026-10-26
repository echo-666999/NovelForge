<?php

namespace App\Filament\Concerns;

use App\Exceptions\NovelProblem;
use App\Services\NovelIntentService;
use App\Services\NovelNavigationService;
use Livewire\Attributes\Url;

/** 统一书级独立Page的URL上下文，切书由GET导航创建干净页面实例。 */
trait HasNovelNavigation
{
    #[Url]
    public string $novel = '';

    public static function getNavigationUrl(): string
    {
        return app(NovelNavigationService::class)->sidebarUrl(parent::getNavigationUrl());
    }

    public function mountHasNovelNavigation(): void
    {
        if (request()->query->has('novel')) {
            $id = request()->query('novel');
            if (! is_string($id)) {
                throw new NovelProblem('NOT_FOUND', '小说不存在。', 404);
            }
            $this->novel = $id;
        }
        // 兼容原来只有运行/阶段/正文/评审ID的深链接；有显式小说时必须同书。
        $reference = match (class_basename($this)) {
            'RunCenter', 'RecoveryDesk' => ['run', 'generation_runs'],
            'ContextInspector' => ['step', 'generation_steps'],
            'ExtractionLedger', 'ReviewCenter' => ['version', 'chapter_versions'],
            'RevisionDesk' => ['review', 'reviews'],
            default => null,
        };
        if ($reference && request()->query->has($reference[0]) && $this->{$reference[0]} !== '') {
            $derived = app(NovelNavigationService::class)->referenceNovel(auth()->user(), $reference[1], $this->{$reference[0]});
            if ($this->novel !== '' && $this->novel !== $derived) {
                throw new NovelProblem('NOT_FOUND', '来源不属于当前小说。', 404);
            }
            $this->novel = $derived;
        }
        if ($this->novel !== '') {
            app(NovelIntentService::class)->novel(auth()->user(), $this->novel);
            if (method_exists($this, 'refreshVersion')) {
                $this->refreshVersion();
            }
        }
    }

    public function bookNavigation(): array
    {
        return app(NovelNavigationService::class)->context(auth()->user(), $this->novel, class_basename($this));
    }
}
