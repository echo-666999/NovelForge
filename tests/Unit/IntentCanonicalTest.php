<?php

namespace Tests\Unit;

use App\Exceptions\NovelProblem;
use App\Services\NovelIntentService;
use PHPUnit\Framework\TestCase;

class IntentCanonicalTest extends TestCase
{
    public function test_canonical_golden_vector_preserves_lists_and_unicode(): void
    {
        $this->assertSame('{"a":["甲😀乙","1.20"],"z":{"a":1,"b":2}}', NovelIntentService::canonical(['z' => ['b' => 2, 'a' => 1], 'a' => ['甲😀乙', '1.20']]));
    }

    public function test_float_is_rejected(): void
    {
        $this->expectException(NovelProblem::class);
        NovelIntentService::canonical(['cost' => 1.2]);
    }
}
