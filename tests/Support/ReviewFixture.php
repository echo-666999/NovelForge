<?php

namespace Tests\Support;

use App\Planning\PlanningContract;
use App\Review\ReviewContract;
use App\Services\AuthoringConfigurationService;
use App\Services\ChapterReviewService;
use App\Services\ReviewPolicyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** E04使用真实E02正文和E03候选，所有模型数据均为明确离线夹具。 */
final class ReviewFixture
{
    public static function create(): array
    {
        $f = ExtractionFixture::create();
        $f['extraction'] = ExtractionFixture::extract($f, ExtractionFixture::output([ExtractionFixture::event($f)]));
        $cfg = json_decode(DB::table('route_versions')->where('id', $f['route'])->value('content'), true);
        $f['style'] = (string) $cfg['style_version_id'];
        $f['policy'] = self::policy($f);

        return $f;
    }

    public static function policy(array $f, array $changes = [], string $key = 'review.default', int $version = 0): array
    {
        $s = app(ReviewPolicyService::class);
        $p = $s->execute($f['actor'], 'save', ['key' => $key, 'expected_version' => $version, 'style_version_id' => $f['style'], 'content' => array_replace(ReviewContract::defaults(), $changes), 'reason' => '明确策略版本与阈值变化原因'], (string) Str::uuid());

        return $s->execute($f['actor'], 'publish', ['policy_version_id' => $p['id'], 'reason' => '发布离线验收策略'], (string) Str::uuid());
    }

    public static function output(array $f, int $score = 4, array $issues = []): array
    {
        $quote = mb_substr($f['body']['body'], 0, 12);
        $e = ['start' => 0, 'end' => mb_strlen($quote), 'quote' => $quote];

        return ['scores' => array_fill_keys(ReviewContract::DIMENSIONS, ['score' => $score, 'reason' => '明确离线评分，仅测试流程，不能代替文学认可。', 'evidence' => $e]), 'issues' => $issues];
    }

    public static function payload(array $f, ?array $output = null): array
    {
        return ['chapter_version_id' => $f['body']['id'], 'body_hash' => $f['body']['body_hash'], 'extraction_id' => $f['extraction']['id'], 'policy_version_id' => $f['policy']['id'], 'expected_version' => $f['body']['report']['row_version'] ?? DB::table('novels')->where('id', $f['novel'])->value('row_version'), 'expected_state_version' => 0, 'offline_output' => $output ?? self::output($f), 'reviewer_type' => 'offline', 'reason' => '正文与完整硬门禁绑定的离线评分'];
    }

    public static function review(array $f, ?array $output = null, array $extra = []): array
    {
        return app(ChapterReviewService::class)->review($f['actor'], $f['novel'], array_replace(self::payload($f, $output), $extra), (string) Str::uuid());
    }

    public static function route(array $f, array $output): string
    {
        $s = app(AuthoringConfigurationService::class);
        $release = function ($kind, $content) use ($s, $f) {
            $p = $s->execute($f['actor'], $kind, 'save', ['key' => 'review.'.$kind.'.'.Str::lower(Str::random(8)), 'stage' => 'review.chapter', 'language' => 'zh-CN', 'expected_version' => 0, 'reason' => '离线七维评审协议', 'content' => $content], (string) Str::uuid());
            $s->execute($f['actor'], $kind, 'test', ['version_id' => $p['id']], (string) Str::uuid());

            return $s->execute($f['actor'], $kind, 'publish', ['version_id' => $p['id']], (string) Str::uuid());
        };
        $prompt = $release('prompt', ['purpose' => '依据全文和硬门禁评审七维文学问题', 'body' => '硬约束不被文学分抵消，保留同一文风：{{json:context}}', 'variable_schema' => PlanningContract::object(['context' => ['type' => 'string', 'maxLength' => 100000]]), 'output_schema' => ReviewContract::schema(), 'capability_requirements' => ['structured_output' => true], 'examples' => [['variables' => ['context' => '离线文学评分资料'], 'output' => $output, 'required_facts' => []]]]);
        $old = json_decode(DB::table('route_versions')->where('id', $f['route'])->value('content'), true);

        return $release('route', ['prompt_version_id' => $prompt['id'], 'style_version_id' => $f['style'], 'primary_model_version_id' => $old['primary_model_version_id'], 'fallbacks' => [], 'parameters' => ['max_output_tokens' => 1000], 'mode' => 'offline_fake'])['id'];
    }
}
