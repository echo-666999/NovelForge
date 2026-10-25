<?php

namespace App\Drafting;

use App\Exceptions\NovelProblem;
use App\Planning\PlanningContract;

/** 正文仅规范换行；可见码点不是词数、模型令牌或中文分词。 */
final class BodyContract
{
    public static function schema(): array
    {
        return PlanningContract::object(['body' => ['type' => 'string', 'maxLength' => 100000]]);
    }

    public static function normalize(string $body): string
    {
        if (! mb_check_encoding($body, 'UTF-8')) {
            throw new NovelProblem('INVALID_UTF8', '正文必须是有效 UTF-8。', 422);
        }

        // PostgreSQL text 不能保存零字节；显式拒绝而不静默删除原文。
        if (str_contains($body, chr(0))) {
            throw new NovelProblem('INVALID_BODY_CHARACTER', '正文不能包含零字节。', 422);
        }

        return str_replace(["\r\n", "\r"], "\n", $body);
    }

    public static function count(string $body): int
    {
        return mb_strlen(preg_replace('/[\p{Z}\p{Cc}\p{Cf}\s]/u', '', self::normalize($body)), 'UTF-8');
    }

    /** 规则报告只描述可检测的完整性问题，不替代语义评审或正式提交预检。 */
    public static function inspect(string $body, string $title, array $budget): array
    {
        $count = self::count($body);
        $issues = [];
        if ($count < $budget['min_words']) {
            $issues[] = 'BELOW_MINIMUM';
        }
        if ($count > $budget['max_words']) {
            $issues[] = 'ABOVE_MAXIMUM';
        }
        if (preg_match('/^\s*(?:第[零一二三四五六七八九十百千万\d]+章|未命名|待定|章节标题)\s*$/u', $title) || trim($title) === '') {
            $issues[] = 'PLACEHOLDER_TITLE';
        }
        if (preg_match('/(?:待补充|正文待生成|此处省略|TODO|\[大纲\]|【大纲】|作为(?:一个)?(?:AI|人工智能)|我是(?:一个)?(?:AI|人工智能)|以下是(?:您|你)(?:要求|所需)|本场景(?:描写|讲述|将会))/iu', $body)) {
            $issues[] = 'PLACEHOLDER_OR_MODEL_NARRATION';
        }
        if (! preg_match('/[。！？.!?][”’」』）)\x{0022}\x{0027}]*\s*$/u', $body)) {
            $issues[] = 'POSSIBLY_TRUNCATED_ENDING';
        }

        return ['algorithm' => 'visible-codepoint-v1', 'body_hash_algorithm' => 'body-lf-utf8-sha256-v1', 'character_count' => $count, 'target_delta' => $count - $budget['target_words'], 'budget' => $budget, 'issues' => $issues, 'complete' => $issues === [], 'semantic_review' => 'not_performed'];
    }
}
