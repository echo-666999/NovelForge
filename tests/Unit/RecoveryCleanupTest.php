<?php

namespace Tests\Unit;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\RecoveryCleanup;
use Tests\Support\RecoveryCleanupFailure;

/** 不依赖数据库验证异常优先级、完整收尾与子进程逐项停止。 */
class RecoveryCleanupTest extends TestCase
{
    public function test_success_returns_body_value_after_all_steps(): void
    {
        $steps = [];
        $result = (new RecoveryCleanup)->run(fn () => 42, [
            'first' => function () use (&$steps) {
                $steps[] = 'first';
            },
            'last' => function () use (&$steps) {
                $steps[] = 'last';
            },
        ]);
        $this->assertSame(42, $result);
        $this->assertSame(['first', 'last'], $steps);
    }

    public function test_original_assertion_and_trace_survive_multiple_cleanup_failures(): void
    {
        $original = new AssertionFailedError('原始断言');
        $trace = $original->getTraceAsString();
        $reported = [];
        $steps = [];
        $cleanup = new RecoveryCleanup(function ($errors) use (&$reported) {
            $reported = $errors;
        });
        try {
            $cleanup->run(fn () => throw $original, [
                'workers' => fn () => throw new RuntimeException('进程故障'),
                'redis' => fn () => throw new RuntimeException('Redis故障'),
                'config' => function () use (&$steps) {
                    $steps[] = 'config';
                },
                'database' => function () use (&$steps) {
                    $steps[] = 'database';
                },
            ]);
            $this->fail('应保留原始异常');
        } catch (AssertionFailedError $caught) {
            $this->assertSame($original, $caught);
            $this->assertSame($trace, $caught->getTraceAsString());
        }
        $this->assertSame(['config', 'database'], $steps);
        $this->assertSame(['workers', 'redis'], array_keys($cleanup->failures()));
        $this->assertSame($cleanup->failures(), $reported);
    }

    public function test_cleanup_only_failure_is_reported_and_keeps_all_causes(): void
    {
        $first = new RuntimeException('第一个收尾失败');
        $lastRan = false;
        try {
            (new RecoveryCleanup)->run(fn () => null, [
                'redis' => fn () => throw $first,
                'database' => fn () => throw new RuntimeException('第二个收尾失败'),
                'last' => function () use (&$lastRan) {
                    $lastRan = true;
                },
            ]);
            $this->fail('不能吞掉收尾失败');
        } catch (RecoveryCleanupFailure $caught) {
            $this->assertSame($first, $caught->getPrevious());
            $this->assertSame(['redis', 'database'], array_keys($caught->failures));
        }
        $this->assertTrue($lastRan);
    }

    public function test_body_failure_without_cleanup_errors_is_unchanged(): void
    {
        $original = new RuntimeException('本体失败');
        $cleanup = new RecoveryCleanup;
        try {
            $cleanup->run(fn () => throw $original, ['ok' => fn () => null]);
            $this->fail('应抛出原始错误');
        } catch (RuntimeException $caught) {
            $this->assertSame($original, $caught);
        }
        $this->assertSame([], $cleanup->failures());
    }

    public function test_diagnostic_failure_cannot_mask_original_failure(): void
    {
        $original = new RuntimeException('原始失败');
        $cleanup = new RecoveryCleanup(fn () => throw new RuntimeException('诊断失败'));
        try {
            $cleanup->run(fn () => throw $original, ['redis' => fn () => throw new RuntimeException('Redis失败')]);
            $this->fail('应抛出原始错误');
        } catch (RuntimeException $caught) {
            $this->assertSame($original, $caught);
        }
        $this->assertSame(['redis', 'diagnostic'], array_keys($cleanup->failures()));
    }

    public function test_every_live_process_is_stopped_even_when_one_stop_fails(): void
    {
        $first = $this->createMock(Process::class);
        $second = $this->createMock(Process::class);
        $finished = $this->createMock(Process::class);
        $first->method('isRunning')->willReturn(true);
        $first->expects($this->once())->method('stop')->willThrowException(new RuntimeException('停止失败'));
        $second->method('isRunning')->willReturn(true);
        $second->expects($this->once())->method('stop');
        $finished->method('isRunning')->willReturn(false);
        $finished->expects($this->never())->method('stop');
        $this->expectException(RecoveryCleanupFailure::class);
        RecoveryCleanup::stopProcesses([$first, $second, $finished]);
    }
}
