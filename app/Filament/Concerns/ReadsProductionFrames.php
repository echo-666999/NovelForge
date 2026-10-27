<?php

namespace App\Filament\Concerns;

use App\Exceptions\NovelProblem;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** 只读失败是独立状态，不能通过空列表、零金额或健康标签掩盖。 */
trait ReadsProductionFrames
{
    /** 只跟踪筛选错误，成功读取后不清除尚未修正的编辑字段错误。 */
    #[Locked]
    public array $productionReadErrorKeys = [];

    public function productionReadFrame(array $methods): array
    {
        $data = [];
        try {
            foreach ($methods as $method) {
                abort_unless(in_array($method, ['catalog', 'detail', 'state', 'listing', 'policies'], true) && method_exists($this, $method), 422);
                $data[$method] = $this->$method();
            }

            if ($this->productionReadErrorKeys) {
                $this->resetErrorBag($this->productionReadErrorKeys);
                $this->productionReadErrorKeys = [];
            }

            return ['ok' => true, 'data' => $data];
        } catch (ValidationException $e) {
            $this->productionErrors($e, []);
            $this->productionReadErrorKeys = $this->getErrorBag()->keys();

            return ['ok' => false, 'message' => '筛选字段或范围无效，请修正后重试。'];
        } catch (NovelProblem $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (QueryException $e) {
            report($e);

            return ['ok' => false, 'message' => '暂时无法读取资料；这不表示没有记录或费用为零，请稍后重试。'];
        }
    }

    public function resetProductionFilters(): void
    {
        $fields = match (class_basename($this)) {
            'Billing' => ['novel', 'status', 'currency', 'category', 'run', 'model', 'requestStatus', 'dateFrom', 'dateTo', 'page'],
            'AuditCenter' => ['novel', 'trace', 'action', 'status', 'dateFrom', 'dateTo', 'highRisk', 'page'],
            'RunCenter' => ['run', 'status', 'error', 'trace', 'dateFrom', 'dateTo', 'page', 'requestPage', 'stepStatus', 'attempt', 'artifactType'],
            'MemoryLibrary' => ['branch', 'kind', 'status', 'indexStatus', 'character', 'chapter', 'page'],
            'DraftEditor' => ['status', 'missing', 'wordIssue'],
            default => [],
        };
        if ($fields) {
            $this->reset(array_values(array_filter($fields, fn ($p) => property_exists($this, $p))));
        }
        $this->resetErrorBag();
    }
}
