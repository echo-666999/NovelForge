<?php

namespace Tests\Unit;

use App\Support\TestIsolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TestIsolationTest extends TestCase
{
    private static function config(): array
    {
        return ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432, 'database' => 'novel_forge_test'];
    }

    public function test_approved_plain_and_url_connections_are_allowed(): void
    {
        $this->assertSame('novel_forge_test', TestIsolation::database(self::config())['database']);
        $resolved = TestIsolation::database(self::config() + ['url' => 'postgresql://probe:dummy@127.0.0.1:5432/novel_forge_test']);
        $this->assertSame('novel_forge_test', $resolved['database']);
        $this->assertArrayNotHasKey('url', $resolved);
    }

    public static function unsafe(): array
    {
        return [
            'plain database' => [['database' => 'not_allowed']],
            'URL overrides database' => [['url' => 'postgresql://probe:dummy@127.0.0.1:5432/not_allowed']],
            'URL query overrides path' => [['url' => 'postgresql://probe:dummy@127.0.0.1:5432/novel_forge_test?database=not_allowed']],
            'URL changes host' => [['url' => 'postgresql://probe:dummy@192.0.2.1:5432/novel_forge_test']],
            'URL changes port' => [['url' => 'postgresql://probe:dummy@127.0.0.1:1/novel_forge_test']],
            'wrong driver' => [['driver' => 'sqlite']],
            'read replica' => [['read' => ['database' => 'not_allowed']]],
            'write replica' => [['write' => ['database' => 'not_allowed']]],
            'URL write replica' => [['url' => 'postgresql://probe:dummy@127.0.0.1:5432/novel_forge_test?write[database]=not_allowed']],
            'PDO options' => [['options' => [1002 => 'unsafe']]],
        ];
    }

    #[DataProvider('unsafe')]
    public function test_unsafe_targets_fail_before_connecting(array $override): void
    {
        $this->expectException(RuntimeException::class);
        TestIsolation::database(array_replace(self::config(), $override));
    }
}
