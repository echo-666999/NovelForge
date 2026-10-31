<?php

namespace App\Services;

use App\Exceptions\NovelProblem;
use Illuminate\Support\Facades\DB;

/** 从不可变阶段产物反查真实请求，调用者不能自行声明来源可信。 */
final class RealResultOrigin
{
    public function forArtifact(object $run, string $artifact): array
    {
        $origin = ['run_id' => (string) $run->id, 'artifact_id' => $artifact];
        $frozen = json_decode($run->frozen_config, true);
        if (($frozen['execution_mode'] ?? 'offline_fake') !== 'production') {
            return $origin;
        }
        $requests = DB::table('ai_requests as q')->join('generation_artifacts as a', 'a.step_id', '=', 'q.step_id')
            ->where('a.id', $artifact)->where('a.run_id', $run->id)->where('q.run_id', $run->id)
            ->where('q.novel_id', $run->novel_id)->where('q.status', 'succeeded')->where('q.evidence_kind', 'real_provider')->pluck('q.id');
        if ($requests->count() !== 1) {
            throw new NovelProblem('REAL_SOURCE_REQUIRED', '真实产物必须对应唯一成功的真实请求。');
        }

        return $origin + ['source_request_id' => (string) $requests->first()];
    }
}
