<?php

namespace App\Services;

use App\Exceptions\NovelProblem;
use App\Extraction\ExtractionContract;
use App\Models\User;
use App\Review\ReviewContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** UI27策略采用不可变内容与追加生命周期；新阈值不改写历史评审。 */
final class ReviewPolicyService
{
    public function execute(User $actor, string $action, array $d, string $key): array
    {
        app(NovelIntentService::class)->authorize($actor);
        ExtractionContract::transport($d);
        Validator::make(['action' => $action, 'key' => $key], ['action' => 'in:save,publish,retire', 'key' => ['required', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/D']])->validate();

        return DB::transaction(function () use ($actor, $action, $d, $key) {
            DB::select("SELECT pg_advisory_xact_lock(hashtext('e04-review-policy'))");
            $hash = hash('sha256', NovelIntentService::canonical(['review-policy', $action, $d]));
            $where = ['actor_id' => $actor->id, 'command_key' => $key];
            if ($old = DB::table('configuration_commands')->where($where)->first()) {
                if ($old->request_hash !== $hash) {
                    throw new NovelProblem('IDEMPOTENCY_CONFLICT', '此键已对应其他策略操作。');
                }

                return json_decode(NovelIntentService::canonical(json_decode($old->response, true)), true, flags: JSON_THROW_ON_ERROR);
            }
            if ($action === 'save') {
                Validator::make($d, ['key' => 'required|string|regex:/^[a-z][a-z0-9_.-]{0,99}$/D', 'expected_version' => 'required|integer|min:0', 'style_version_id' => 'required|integer|min:1', 'content' => 'required|array:minimums,average,max_revision_rounds,template_phrases,exceptions,repeat_threshold', 'reason' => 'required|string|max:2000'])->validate();
                if (array_diff(array_keys($d), ['key', 'expected_version', 'style_version_id', 'content', 'reason'])) {
                    throw new NovelProblem('INVALID_POLICY', '策略存在未知字段。', 422);
                }
                $c = $d['content'];
                Validator::make($c, ['minimums' => 'required|array:'.implode(',', ReviewContract::DIMENSIONS), 'average' => 'required|numeric|min:1|max:5', 'max_revision_rounds' => 'required|integer|min:0|max:3', 'repeat_threshold' => 'required|integer|min:2|max:20', 'template_phrases' => 'present|array|max:50', 'template_phrases.*' => 'required|string|max:100|distinct', 'exceptions' => 'present|array|max:50', 'exceptions.*' => 'required|string|max:100|distinct'])->validate();
                foreach (ReviewContract::DIMENSIONS as $dim) {
                    Validator::make($c, ['minimums.'.$dim => 'required|integer|min:1|max:5'])->validate();
                }
                if ((int) DB::table('review_policy_versions')->where('key', $d['key'])->max('version') !== (int) $d['expected_version']) {
                    throw new NovelProblem('STALE_VERSION', '策略版本已更新。');
                }
                $style = app(AuthoringConfigurationService::class)->version('style', (string) $d['style_version_id']);
                if ($style['status'] !== 'published' || $style['stage'] !== 'drafting.scene') {
                    throw new NovelProblem('POLICY_STYLE_MISMATCH', '评审策略须复用正文生成的已发布文风版本。');
                }
                $id = (string) DB::table('review_policy_versions')->insertGetId(['key' => $d['key'], 'version' => $d['expected_version'] + 1, 'style_version_id' => $d['style_version_id'], 'content' => NovelIntentService::canonical($c), 'content_hash' => hash('sha256', NovelIntentService::canonical($c)), 'actor_id' => $actor->id, 'reason' => $d['reason']]);
            } else {
                Validator::make($d, ['policy_version_id' => 'required|integer|min:1', 'reason' => 'required|string|max:2000'])->validate();
                if (array_diff(array_keys($d), ['policy_version_id', 'reason'])) {
                    throw new NovelProblem('INVALID_POLICY', '生命周期操作含未知字段。', 422);
                }
                $id = (string) $d['policy_version_id'];
                $p = $this->read($actor, $id);
                if (($action === 'publish' && $p['status'] !== 'draft') || ($action === 'retire' && $p['status'] !== 'published')) {
                    throw new NovelProblem('INVALID_TRANSITION', '策略状态不允许此操作。');
                }
                DB::table('review_policy_states')->insert(['policy_version_id' => $id, 'status' => $action === 'publish' ? 'published' : 'retired', 'actor_id' => $actor->id, 'reason' => $d['reason']]);
            }
            $r = json_decode(NovelIntentService::canonical($this->read($actor, $id)), true, flags: JSON_THROW_ON_ERROR);
            DB::table('configuration_commands')->insert($where + ['request_hash' => $hash, 'response' => NovelIntentService::canonical($r)]);

            return $r;
        });
    }

    public function read(User $actor, string $id): array
    {
        app(NovelIntentService::class)->authorize($actor);
        Validator::make(['id' => $id], ['id' => 'required|integer|min:1'])->validate();
        $p = DB::table('review_policy_versions')->find($id) ?? throw new NovelProblem('NOT_FOUND', '评审策略不存在。', 404);
        $r = (array) $p;
        $r['id'] = (string) $p->id;
        $r['style_version_id'] = (string) $p->style_version_id;
        $r['actor_id'] = (string) $p->actor_id;
        $r['content'] = json_decode($p->content, true);
        if (hash('sha256', NovelIntentService::canonical($r['content'])) !== $p->content_hash) {
            throw new NovelProblem('INVALID_POLICY_HASH', '策略内容哈希不一致。');
        }
        $r['status'] = DB::table('review_policy_states')->where('policy_version_id', $id)->orderByDesc('id')->value('status') ?? 'draft';

        return $r;
    }

    public function listing(User $actor): array
    {
        app(NovelIntentService::class)->authorize($actor);

        return DB::table('review_policy_versions')->orderByDesc('id')->limit(100)->pluck('id')->map(fn ($id) => $this->read($actor, (string) $id))->all();
    }
}
