<?php

namespace App\Review;

use App\Planning\PlanningContract as C;

/** 七维评分只提供候选流程信号，不代表人工成品认可。 */
final class ReviewContract
{
    public const DIMENSIONS = ['plot', 'character', 'causality', 'pace', 'dialogue', 'naturalness', 'appeal'];

    public const LABELS = ['plot' => '情节推进', 'character' => '人物一致性', 'causality' => '因果连贯', 'pace' => '节奏', 'dialogue' => '对白与声音', 'naturalness' => '自然度', 'appeal' => '章节吸引力'];

    public static function schema(): array
    {
        $text = ['type' => 'string', 'maxLength' => 2000];
        $evidence = C::object(['start' => ['type' => 'integer'], 'end' => ['type' => 'integer'], 'quote' => $text]);

        return C::object(['scores' => C::object(array_fill_keys(self::DIMENSIONS, C::object(['score' => ['type' => 'integer'], 'reason' => $text, 'evidence' => $evidence]))), 'issues' => C::list(C::object(['category' => ['type' => 'string', 'maxLength' => 40], 'severity' => ['type' => 'string', 'maxLength' => 20], 'message' => $text, 'suggested_fix' => $text, 'evidence' => $evidence]), 100)]);
    }

    /** 默认阈值独立于C04文风的旧0至100量表，不隐式换算。 */
    public static function defaults(): array
    {
        return ['minimums' => array_fill_keys(self::DIMENSIONS, 3), 'average' => '3.8', 'max_revision_rounds' => 3, 'template_phrases' => ['总而言之', '不由得', '与此同时', '命运的齿轮'], 'exceptions' => [], 'repeat_threshold' => 3];
    }
}
