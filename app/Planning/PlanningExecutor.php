<?php

namespace App\Planning;

use App\Exceptions\NovelProblem;
use App\Generation\StageExecutor;
use App\Generation\StageResult;
use App\Models\User;
use App\Services\PlanningPipelineService;
use App\Services\ProviderGateway;
use Illuminate\Support\Facades\DB;

/** 规划执行器只回放启动时冻结的离线响应，网关仍执行真实持久预算和证据协议。 */
final class PlanningExecutor implements StageExecutor
{
    public function execute(array $input): StageResult
    {
        $step = DB::table('generation_steps')->find($input['_lease']['id']);
        $run = DB::table('generation_runs')->find($step->run_id);
        $frozen = json_decode($run->frozen_config, true, flags: JSON_THROW_ON_ERROR);
        $actor = User::findOrFail($run->actor_id);
        $cfg = $frozen['configuration'];
        // 显式切换备用模型后也重验冻结文本容量，不因主模型曾通过而忽略备用上限。
        $selected = collect($cfg['models'])->firstWhere('model_version_id', $input['configuration_selection']['model_version_id']);
        $reasoning = $frozen['billing']['prices'][$selected['model_version_id']]['reasoning_mode'] === 'separate' ? $frozen['billing']['max_reasoning_tokens'] : 0;
        if (strlen($cfg['rendered_prompt']) + $frozen['billing']['max_output_tokens'] + $reasoning + 256 > $selected['declared_capabilities']['context_tokens']) {
            throw new NovelProblem('CONTEXT_CAPACITY_EXCEEDED', '冻结规划上下文超出所选模型容量。');
        }
        $request = app(ProviderGateway::class)->execute($actor, (string) $run->novel_id, (string) $step->id, $input['configuration_selection']['model_version_id'], ['prompt' => $cfg['rendered_prompt'], 'output_schema' => $cfg['prompt']['content']['output_schema']], 'planning-candidate', new PlanningReplayAdapter($input['node']['input']['offline_output']));
        if ($request['status'] !== 'succeeded') {
            return new StageResult('blocked', ['text' => '离线响应未通过网关校验，请查看请求证据。'], $request['error_code'] ?? 'PLANNING_REQUEST_BLOCKED');
        }
        $reply = json_decode(app(ProviderGateway::class)->evidenceText($actor, (string) $run->novel_id, $request['id'], 'response'), true, flags: JSON_THROW_ON_ERROR);
        $candidate = $reply['output'];
        app(PlanningPipelineService::class)->validateCandidate($actor, (string) $run->id, $candidate);

        return new StageResult('succeeded', $candidate);
    }
}
