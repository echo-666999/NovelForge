<?php

namespace Tests\Support;

use RuntimeException;

/** 本体成功但收尾失败时必须报错；保留各步骤异常，不伪装为通过。 */
final class RecoveryCleanupFailure extends RuntimeException
{
    public function __construct(public readonly array $failures)
    {
        parent::__construct('Recovery cleanup failed at: '.implode(', ', array_keys($failures)).'.', 0, reset($failures));
    }
}
