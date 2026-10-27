<?php

namespace Tests\Support;

use Closure;
use Throwable;

/** 仅用于故障测试：逐项尝试收尾，Redis/进程失败不能跳过数据库重置。 */
final class RecoveryCleanup
{
    private array $failures = [];

    public function __construct(private readonly ?Closure $report = null) {}

    public function failures(): array
    {
        return $this->failures;
    }

    public function run(callable $body, array $steps): mixed
    {
        $this->failures = [];
        $original = null;
        $result = null;
        try {
            $result = $body();
        } catch (Throwable $error) {
            $original = $error;
        }
        foreach ($steps as $name => $step) {
            try {
                $step();
            } catch (Throwable $error) {
                $this->failures[$name] = $error;
            }
        }
        if ($original !== null) {
            if ($this->failures) {
                try {
                    if ($this->report !== null) {
                        ($this->report)($this->failures);
                    } else {
                        // 不输出连接参数或异常消息；原始异常由PHPUnit照常报告。
                        fwrite(STDERR, '[NF-UI16 cleanup] '.implode(', ', array_map(fn ($name, $error) => $name.':'.$error::class, array_keys($this->failures), $this->failures)).PHP_EOL);
                    }
                } catch (Throwable $error) {
                    $this->failures['diagnostic'] = $error;
                }
            }
            // 重抛同一异常对象，保留断言失败类型和原始堆栈。
            throw $original;
        }
        if ($this->failures) {
            throw new RecoveryCleanupFailure($this->failures);
        }

        return $result;
    }

    /** 各子进程分别停止，一个stop失败也不能跳过其余进程。 */
    public static function stopProcesses(array $processes): void
    {
        $steps = [];
        foreach ($processes as $i => $process) {
            $steps['process_'.$i] = static function () use ($process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            };
        }
        (new self)->run(static fn () => null, $steps);
    }
}
