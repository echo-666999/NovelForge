<?php

namespace App\Services;

use App\Exceptions\NovelProblem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** 标注只在检索结束后计分，绝不传入编码器或排序器。 */
final class SearchEvaluationService
{
    public function evaluate(User $actor, string $novel, string $build, array $cases, string $corpusHash): array
    {
        return DB::transaction(function () use ($actor, $novel, $build, $cases, $corpusHash) {
            app(NovelIntentService::class)->novel($actor, $novel, true);
            Validator::make(['cases' => $cases, 'corpus_hash' => $corpusHash], ['cases' => 'required|array|min:1|max:1000', 'corpus_hash' => 'required|regex:/^[a-f0-9]{64}$/', 'cases.*.query_id' => 'required|string|distinct', 'cases.*.category' => 'required|string', 'cases.*.filters' => 'required|array', 'cases.*.relevant_source_ids' => 'required|array|min:1', 'cases.*.relevant_source_ids.*' => 'required|integer|min:1', 'cases.*.forbidden_source_ids' => 'present|array', 'cases.*.forbidden_source_ids.*' => 'required|integer|min:1'])->validate();
            $b = DB::table('index_builds')->where('novel_id', $novel)->where('id', $build)->where('status', 'active')->first() ?? throw new NovelProblem('INDEX_NOT_ACTIVE', '评测必须绑定当前活动索引。');
            $rows = [];
            $leaks = 0;
            $sum = 0;
            $categories = [];
            foreach ($cases as $case) {
                // 档案、代次及Top20由评测固定，不能由单条查询偷偷覆盖。
                $filters = array_replace($case['filters'], ['scope' => 'candidate', 'build_id' => $build, 'profile_id' => (string) $b->profile_id, 'branch_id' => (string) $b->branch_id, 'limit' => 20]);
                $start = hrtime(true);
                $r = app(HybridSearchService::class)->search($actor, $novel, $filters);
                $latency = (hrtime(true) - $start) / 1000000;
                $got = array_column($r['results'], 'source_version_id');
                $relevant = array_unique(array_map('strval', $case['relevant_source_ids']));
                $hits = count(array_intersect($got, $relevant));
                $recall = $hits / count($relevant);
                $forbidden = array_values(array_intersect($got, array_map('strval', $case['forbidden_source_ids'])));
                $sum += $recall;
                $leaks += count($forbidden);
                $categories[$case['category']] ??= ['queries' => 0, 'recall_sum' => 0.0];
                $categories[$case['category']]['queries']++;
                $categories[$case['category']]['recall_sum'] += $recall;
                $rows[] = ['query_id' => $case['query_id'], 'category' => $case['category'], 'recall_at_20' => $recall, 'leaked_sources' => $forbidden, 'latency_ms' => $latency, 'eligible_documents' => $r['eligible_documents'], 'channel_counts' => $r['channel_counts'], 'results' => $r['results']];
            }
            foreach ($categories as &$c) {
                $c['recall_at_20'] = $c['recall_sum'] / $c['queries'];
                unset($c['recall_sum']);
            }unset($c);
            $report = ['scope' => 'candidate', 'encoder' => 'offline_hash_v1', 'real_model_evaluated' => false, 'formal_revision_evaluated' => false, 'query_count' => count($cases), 'recall_at_20' => $sum / count($cases), 'leak_count' => $leaks, 'passed' => $sum / count($cases) >= 0.9 && $leaks === 0, 'categories' => $categories, 'queries' => $rows];
            $id = DB::table('search_evaluations')->insertGetId(['novel_id' => $novel, 'index_build_id' => $build, 'manifest_hash' => $b->manifest_hash, 'corpus_hash' => $corpusHash, 'queries_hash' => hash('sha256', NovelIntentService::canonical($cases)), 'report' => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'actor_id' => $actor->id]);

            return ['id' => (string) $id, 'report' => $report];
        });
    }
}
