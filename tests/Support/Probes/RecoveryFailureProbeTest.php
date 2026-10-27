<?php

namespace Tests\Support\Probes;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\RecoveryCleanup;

/** 仅由父用例以独立PHPUnit运行，预期失败；不属于默认Unit/Feature套件。 */
class RecoveryFailureProbeTest extends TestCase
{
    public function test_original_assertion_remains_a_failure(): void
    {
        $this->addToAssertionCount(1);
        (new RecoveryCleanup)->run(fn () => throw new AssertionFailedError('NF_UI16_PRIMARY_ASSERTION'), [
            'redis' => fn () => throw new RuntimeException('NF_UI16_REDIS_DIAGNOSTIC_SECRET'),
        ]);
    }

    public function test_cleanup_only_failure_is_an_error(): void
    {
        $this->addToAssertionCount(1);
        (new RecoveryCleanup)->run(fn () => null, [
            'redis' => fn () => throw new RuntimeException('NF_UI16_CLEANUP_ONLY'),
        ]);
    }
}
