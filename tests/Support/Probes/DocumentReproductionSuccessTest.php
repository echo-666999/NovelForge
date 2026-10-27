<?php

namespace Tests\Support\Probes;

use Symfony\Component\Process\Process;
use Tests\Support\RecoveryEnvironment;
use Tests\TestCase;

/** 专项正例只在私有Redis缺失时单独运行，不列入默认Unit/Feature套件。 */
class DocumentReproductionSuccessTest extends TestCase
{
    public function test_legal_reproduction_matches_frozen_result_and_cleans_the_test_database(): void
    {
        RecoveryEnvironment::assertDatabase();
        // 不停止已有实例或改动socket来制造“缺失”；前置不满足就直接失败。
        $this->assertFalse(file_exists(base_path('.local/redis-testing.sock')));
        $this->assertDatabaseCount('users', 0);
        $process = new Process([PHP_BINARY, base_path('docs/ui/NF-UI16/scripts/before-repro.php')], base_path(), ['APP_ENV' => 'testing']);
        $process->setTimeout(20)->run();
        $this->assertSame(0, $process->getExitCode());
        $this->assertSame('', $process->getErrorOutput());
        $expected = json_decode(file_get_contents(base_path('docs/ui/NF-UI18/AFTER_REPRO.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($expected, json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('users', 0);
    }
}
