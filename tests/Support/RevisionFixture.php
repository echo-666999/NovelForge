<?php

namespace Tests\Support;

use App\Planning\PlanningContract;
use App\Revision\RevisionContract;
use App\Services\AuthoringConfigurationService;
use App\Services\DraftingService;
use App\Services\RevisionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** E05真实候选链与明确离线补丁；不用示意正文替代实际版本。 */
final class RevisionFixture
{
    public static function create(int $score = 3): array
    {
        $f = ReviewFixture::create();
        $f['review'] = ReviewFixture::review($f, ReviewFixture::output($f, $score));

        return $f;
    }

    public static function payload(array $f, string $mode = 'offline'): array
    {
        $body = $f['body']['body'];
        $at = mb_strpos($body, '第1处');
        $previous = mb_strrpos(mb_substr($body, 0, $at), "\n");
        $start = $previous === false ? 0 : $previous + 1;
        $end = mb_strpos($body, "\n", $start);
        $end = $end === false ? mb_strlen($body) : $end;
        $protectedAt = mb_strpos($body, '铜钥匙');

        return ['review_id' => $f['review']['id'], 'issue_ids' => [$f['review']['issues'][0]['id']], 'allowed_scope' => ['kind' => 'paragraph', 'ranges' => [['start' => $start, 'end' => $end, 'quote' => mb_substr($body, $start, $end - $start)]]], 'preserve_facts' => [], 'preserve_quotes' => [['start' => $protectedAt, 'end' => $protectedAt + 3, 'quote' => '铜钥匙']], 'mode' => $mode, 'expected_version' => DB::table('novels')->where('id', $f['novel'])->value('row_version'), 'expected_state_version' => 0, 'reason' => '仅修订首段表达，保留全部已提取事实与伏笔'];
    }

    public static function task(array $f, string $mode = 'offline'): array
    {
        return app(RevisionService::class)->create($f['actor'], $f['novel'], self::payload($f, $mode), (string) Str::uuid());
    }

    public static function output(array $f): array
    {
        $body = $f['body']['body'];
        $start = mb_strpos($body, '第1处');
        $second = mb_strpos($body, '核对纸上的日期');

        return ['patches' => [['key' => 'first', 'issue_ids' => [$f['review']['issues'][0]['id']], 'start' => $start, 'end' => $start + 3, 'quote' => '第1处', 'replacement' => '第一处', 'reason' => '保留地点和动作，仅调整序号表达'], ['key' => 'second', 'issue_ids' => [$f['review']['issues'][0]['id']], 'start' => $second, 'end' => $second + 7, 'quote' => '核对纸上的日期', 'replacement' => '逐一核对纸上的日期', 'reason' => '只调整动作节奏，不添加新事实']]];
    }

    public static function adopt(array $f, array $task, ?array $output = null): array
    {
        $s = app(RevisionService::class);
        $t = $s->propose($f['actor'], $f['novel'], $task['id'], ['offline_output' => $output ?? self::output($f), 'reason' => '明确离线补丁回放'], (string) Str::uuid());

        return $s->decide($f['actor'], $f['novel'], $task['id'], ['kind' => 'accept', 'proposal_id' => $t['proposals'][0]['id'], 'patch_keys' => ['first'], 'expected_version' => $f['body']['version'], 'reason' => '只采纳第一个补丁'], (string) Str::uuid());
    }

    public static function updated(array $f, array $t): array
    {
        $f['body'] = app(DraftingService::class)->read($f['actor'], $f['novel'], 'chapter', $t['decision']['target_version_id']);

        return $f;
    }

    public static function assessment(array $f, int $score = 4): array
    {
        return ['extraction_output' => ExtractionFixture::output([ExtractionFixture::event($f)]), 'review_output' => ReviewFixture::output($f, $score), 'reviewer_type' => 'offline', 'reason' => '按新正文重新定位证据并评审完整章节衔接'];
    }

    public static function route(array $f, array $output): string
    {
        $s = app(AuthoringConfigurationService::class);
        $release = function ($kind, $content) use ($s, $f) {
            $p = $s->execute($f['actor'], $kind, 'save', ['key' => 'revision.'.$kind.'.'.Str::lower(Str::random(8)), 'stage' => 'revision.propose', 'language' => 'zh-CN', 'expected_version' => 0, 'reason' => '受限离线补丁验收', 'content' => $content], (string) Str::uuid());
            $s->execute($f['actor'], $kind, 'test', ['version_id' => $p['id']], (string) Str::uuid());

            return $s->execute($f['actor'], $kind, 'publish', ['version_id' => $p['id']], (string) Str::uuid());
        };
        $p = $release('prompt', ['purpose' => '仅在冻结范围修订表达并保留事实', 'body' => '不能默认重写整章：{{json:context}}', 'variable_schema' => PlanningContract::object(['context' => ['type' => 'string', 'maxLength' => 100000]]), 'output_schema' => RevisionContract::schema(), 'capability_requirements' => ['structured_output' => true], 'examples' => [['variables' => ['context' => '作者明确允许的局部范围'], 'output' => $output, 'required_facts' => []]]]);
        $old = json_decode(DB::table('route_versions')->where('id', $f['route'])->value('content'), true);

        return $release('route', ['prompt_version_id' => $p['id'], 'style_version_id' => $f['style'], 'primary_model_version_id' => $old['primary_model_version_id'], 'fallbacks' => [], 'parameters' => ['max_output_tokens' => 1000], 'mode' => 'offline_fake'])['id'];
    }
}
