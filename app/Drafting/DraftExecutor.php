<?php

namespace App\Drafting;

use App\Exceptions\NovelProblem;
use App\Generation\StageExecutor;
use App\Generation\StageResult;
use App\Models\User;
use App\Services\DraftingService;
use App\Services\ProviderGateway;
use Illuminate\Support\Facades\DB;

/** 场景独立执行；已核销网关响应可跨进程复用，正文与有效产物在完成事务内一起保存。 */
final class DraftExecutor implements StageExecutor
{
    public function execute(array $input): StageResult
    {
        $step = DB::table('generation_steps')->find($input['_lease']['id']);
        $run = DB::table('generation_runs')->find($step->run_id);
        $frozen = json_decode($run->frozen_config, true, flags: JSON_THROW_ON_ERROR);
        app(DraftingService::class)->verifyFrozen($run, $frozen);
        $cfg = $frozen['configuration'];
        $selected = collect($cfg['models'])->firstWhere('model_version_id', $input['configuration_selection']['model_version_id']);
        $reasoning = $frozen['billing']['prices'][$selected['model_version_id']]['reasoning_mode'] === 'separate' ? $frozen['billing']['max_reasoning_tokens'] : 0;
        if (strlen($cfg['rendered_prompt']) + $frozen['billing']['max_output_tokens'] + $reasoning + 256 > $selected['declared_capabilities']['context_tokens']) {
            throw new NovelProblem('CONTEXT_CAPACITY_EXCEEDED', '冻结正文上下文超出所选模型容量。');
        }
        $actor = User::findOrFail($run->actor_id);
        $request = app(ProviderGateway::class)->execute($actor, (string) $run->novel_id, (string) $step->id, $input['configuration_selection']['model_version_id'], ['prompt' => $cfg['rendered_prompt'], 'output_schema' => BodyContract::schema()], 'draft-scene', new DraftReplayAdapter($input['node']['input']['offline_output']));
        if ($request['status'] !== 'succeeded') {
            return new StageResult('blocked', ['text' => '离线正文响应未通过网关，请查看请求证据。'], $request['error_code'] ?? 'DRAFT_REQUEST_BLOCKED');
        }
        $reply = json_decode(app(ProviderGateway::class)->evidenceText($actor, (string) $run->novel_id, $request['id'], 'response'), true, flags: JSON_THROW_ON_ERROR);

        return new StageResult('succeeded', $reply['output']);
    }
}
