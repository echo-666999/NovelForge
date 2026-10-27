<?php

namespace App\Filament\Concerns;

use App\Services\NovelIntentService;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/** 页面重试保留原载荷和命令键；服务仍独立核对并发、预算与来源。 */
trait WritesProductionCommands
{
    #[Locked]
    public string $productionNonce = '';

    #[Locked]
    public array $productionCommands = [];

    public function mountWritesProductionCommands(): void
    {
        $this->productionNonce = (string) Str::uuid();
    }

    public function newProductionOperation(): void
    {
        $this->productionNonce = (string) Str::uuid();
        $this->productionCommands = [];
    }

    protected function productionWrite(string $action, array $identity, array $payload, callable $write): mixed
    {
        $semantic = $payload;
        // 这些是书/当前候选的派生并发号，不能使成功后的同一次重试变成新命令。
        if (in_array($action, ['PlanningPipelineService.start', 'PlanningPipelineService.adopt', 'ChapterReviewService.review', 'ReviewPipelineService.start', 'RevisionService.create', 'DraftingService.start', 'DraftingService.assemble'], true)) {
            unset($semantic['expected_version'],$semantic['expected_state_version'],$semantic['expected_candidate_version']);
        }
        $hash = hash('sha256', NovelIntentService::canonical([$action, (string) ($this->novelId ?? $this->novel ?? ''), $identity, $semantic]));
        if (! isset($this->productionCommands[$hash])) {
            $this->productionCommands[$hash] = ['payload' => $payload, 'key' => 'ui07:'.hash('sha256', $this->productionNonce.':'.$hash)];
        }
        $command = $this->productionCommands[$hash];

        return $write($command['payload'], $command['key']);
    }
}
