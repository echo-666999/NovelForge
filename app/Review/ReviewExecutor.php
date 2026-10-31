<?php

namespace App\Review;

use App\Exceptions\NovelProblem;
use App\Generation\StageExecutionMode;
use App\Generation\StageExecutor;
use App\Generation\StageResult;
use App\Models\User;
use App\Services\ProviderGateway;
use App\Services\ReviewPipelineService;
use Illuminate\Support\Facades\DB;

/** 离线评审请求在完成事务外执行，已有成功响应可跨进程复用。 */
final class ReviewExecutor implements StageExecutor
{
    public function execute(array $input): StageResult
    {
        $step = DB::table('generation_steps')->find($input['_lease']['id']);
        $run = DB::table('generation_runs')->find($step->run_id);
        $frozen = json_decode($run->frozen_config, true, flags: JSON_THROW_ON_ERROR);
        app(ReviewPipelineService::class)->verifyFrozen($run, $frozen);
        $cfg = $frozen['configuration'];
        $selected = collect($cfg['models'])->firstWhere('model_version_id', $input['configuration_selection']['model_version_id']);
        $reason = $frozen['billing']['prices'][$selected['model_version_id']]['reasoning_mode'] === 'separate' ? $frozen['billing']['max_reasoning_tokens'] : 0;
        if (strlen($cfg['rendered_prompt']) + $frozen['billing']['max_output_tokens'] + $reason + 256 > $selected['declared_capabilities']['context_tokens']) {
            throw new NovelProblem('CONTEXT_CAPACITY_EXCEEDED', '冻结评审上下文超过所选模型容量。');
        }
        $actor = User::findOrFail($run->actor_id);
        $request = app(ProviderGateway::class)->execute($actor, (string) $run->novel_id, (string) $step->id, $selected['model_version_id'], ['prompt' => $cfg['rendered_prompt'], 'output_schema' => ReviewContract::schema()], 'review-chapter', StageExecutionMode::adapter($frozen, $input['node']['stage'], $input['configuration_selection']['model_version_id']));
        if ($request['status'] !== 'succeeded') {
            return new StageResult('blocked', ['text' => '评审响应未通过网关，请保留原正文并检查请求证据。'], $request['error_code'] ?? 'REVIEW_REQUEST_BLOCKED');
        }
        // 业务产物通过统一协议边界还原；不把原始响应改写成内部格式。
        $output = app(ProviderGateway::class)->businessOutput($actor, (string) $run->novel_id, $request['id'], ['prompt' => $cfg['rendered_prompt'], 'output_schema' => ReviewContract::schema()]);

        return new StageResult('succeeded', $output);
    }
}
