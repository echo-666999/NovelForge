<?php

namespace App\Services;

use App\Context\ContextComposer;
use App\Exceptions\NovelProblem;
use App\Extraction\ExtractionContract;
use App\Models\User;
use App\Review\ReviewContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** 注册离线七维评审：预算请求在阶段完成事务外执行，不进行修订或提交。 */
final class ReviewPipelineService
{
    public function start(User $actor, string $novel, array $d, string $key): array
    {
        ExtractionContract::transport($d);
        Validator::make(['d' => $d, 'key' => $key], ['d' => 'required|array:chapter_version_id,body_hash,extraction_id,policy_version_id,expected_version,expected_state_version,offline_output,reason,route_version_id,billing', 'key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D']])->validate();
        Validator::make($d, ['chapter_version_id' => 'required|integer|min:1', 'body_hash' => 'required|string|size:64', 'extraction_id' => 'required|integer|min:1', 'policy_version_id' => 'required|integer|min:1', 'expected_version' => 'required|integer|min:1', 'expected_state_version' => 'required|integer|min:0', 'offline_output' => 'required|array', 'reason' => 'required|string|max:2000', 'route_version_id' => 'required|integer|min:1', 'billing' => 'required|array'])->validate();
        if (strlen(NovelIntentService::canonical($d)) > 1000000) {
            throw new NovelProblem('REQUEST_TOO_LARGE', '评审运行输入过大。', 422);
        }

        return DB::transaction(function () use ($actor, $novel, $d, $key) {
            $book = app(NovelIntentService::class)->novel($actor, $novel, true);
            $s = app(ChapterReviewService::class);
            $where = ['actor_id' => $actor->id, 'novel_scope' => $novel, 'command' => 'review.start', 'key' => $key];
            if ($old = DB::table('novel_commands')->where($where)->first()) {
                if ($old->request_hash !== $s->hash($d)) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '运行键已绑定其他评审输入。');
                }

                return json_decode(NovelIntentService::canonical(json_decode($old->response, true)), true, flags: JSON_THROW_ON_ERROR);
            }
            $request = array_diff_key($d, array_flip(['route_version_id', 'billing']));
            $request['reviewer_type'] = 'offline';
            $input = $s->snapshot($actor, $novel, $request);
            // 结构不合格可直接保存阻塞评审，不能花预算让文学高分冲掉硬门禁。
            if ($input['hard']) {
                throw new NovelProblem('HARD_CONSTRAINT_BLOCKED', '硬门禁未通过，请先保存并处理结构报告，不能启动文学模型阶段。');
            }
            $cfg = app(AuthoringConfigurationService::class)->freeze($actor, ['route_version_id' => (string) $d['route_version_id'], 'variables' => ['context' => NovelIntentService::canonical($input)]], 'review.chapter');
            if ($s->hash($cfg['prompt']['content']['output_schema']) !== $s->hash(ReviewContract::schema()) || $cfg['route']['content']['mode'] !== 'offline_fake' || $cfg['style']['id'] !== $input['policy']['style_version_id']) {
                throw new NovelProblem('REVIEW_CONFIGURATION_MISMATCH', '评审须为注册离线协议，并精确复用正文文风版本。');
            }
            if (($d['billing']['category'] ?? '') !== 'review' || (string) ($d['billing']['chapter_id'] ?? '') !== $input['chapter_id']) {
                throw new NovelProblem('INVALID_BILLING_SCOPE', '预算须绑定本章及review用途。', 422);
            }
            $billing = app(BudgetService::class)->freeze($actor, $novel, $d['billing'], $cfg);
            $model = $cfg['models'][0];
            $mode = $billing['prices'][$model['model_version_id']]['reasoning_mode'];
            $manifest = app(ContextComposer::class)->compose([['category' => 'review_contract', 'zone' => '作者评审上下文；不代表角色知情', 'text' => $cfg['rendered_prompt']."\n评审输出契约：".NovelIntentService::canonical(ReviewContract::schema()), 'required' => true]], $model['declared_capabilities']['context_tokens'], $billing['max_output_tokens'], $billing['max_reasoning_tokens'], $mode, 256);
            if ($manifest['status'] !== 'assembled') {
                throw new NovelProblem('CONTEXT_CAPACITY_EXCEEDED', '正文、前章及硬约束不可裁剪，冻结容量不足。');
            }
            $cfg['rendered_prompt'] = $manifest['final_prompt'];
            $frozen = ['schema_version' => 'review-v1', 'row_version' => $book->row_version, 'chapter_version_id' => $input['chapter_version_id'], 'body_hash' => $input['body_hash'], 'review_input' => $input, 'request' => array_diff_key($request, ['offline_output' => true]), 'configuration' => $cfg, 'billing' => $billing, 'context' => $manifest, 'nodes' => [['key' => 'review', 'stage' => 'review.chapter', 'depends_on' => [], 'input' => ['offline_output' => $d['offline_output']]]]];
            $body = DB::table('chapter_versions')->where('novel_id', $novel)->find($input['chapter_version_id']);
            $outline = DB::table('rolling_plans')->where('novel_id', $novel)->where('id', $body->plan_id)->value('outline_version_id');
            $id = (string) DB::table('generation_runs')->insertGetId(['novel_id' => $novel, 'actor_id' => $actor->id, 'outline_version_id' => $outline, 'configuration_route_version_id' => $cfg['route']['id'], 'expected_state_version' => $book->state_version, 'command_key' => 'e04:'.hash('sha256', $key), 'request_hash' => $s->hash($d), 'frozen_config' => NovelIntentService::canonical($frozen), 'input_hash' => $s->hash($frozen), 'created_at' => now(), 'updated_at' => now()]);
            app(OutboxService::class)->enqueue($actor, $novel, $id, 'review');
            $r = ['run_id' => $id, 'status_url' => '/api/v1/novels/'.$novel.'/runs/'.$id, 'mode' => 'offline_response_replay'];
            $r = json_decode(NovelIntentService::canonical($r), true, flags: JSON_THROW_ON_ERROR);
            DB::table('novel_commands')->insert($where + ['request_hash' => $s->hash($d), 'response' => NovelIntentService::canonical($r), 'created_at' => now()]);

            return $r;
        });
    }

    public function verifyFrozen(object $run, array $f): void
    {
        $s = app(ChapterReviewService::class);
        $actor = User::findOrFail($run->actor_id);
        $now = $s->snapshot($actor, (string) $run->novel_id, $f['request'], false);
        if ($s->hash($f) !== $run->input_hash || $s->hash($now) !== $s->hash($f['review_input']) || $now['hard'] || (string) $now['state_version'] !== (string) $run->expected_state_version || DB::table('novels')->where('id', $run->novel_id)->value('status') === 'archived') {
            throw new NovelProblem('DEPENDENCY_MISMATCH', '评审正文、上下文或完整E03校验已过期。');
        }
    }

    public function generated(object $run, string $artifact, array $output): array
    {
        $f = json_decode($run->frozen_config, true);

        return app(ChapterReviewService::class)->review(User::findOrFail($run->actor_id), (string) $run->novel_id, $f['request'] + ['offline_output' => $output], 'artifact:'.$artifact, ['run_id' => (string) $run->id, 'artifact_id' => $artifact], $f['review_input']);
    }
}
