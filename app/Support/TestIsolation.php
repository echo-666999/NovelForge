<?php

namespace App\Support;

use Illuminate\Foundation\Application;
use Illuminate\Support\ConfigurationUrlParser;
use RuntimeException;

final class TestIsolation
{
    public static function database(array $config): array
    {
        try {
            $resolved = (new ConfigurationUrlParser)->parseConfiguration($config);
        } catch (\Throwable) {
            throw new RuntimeException('Unsafe testing database URL.');
        }

        if (($resolved['driver'] ?? null) !== 'pgsql'
            || ($resolved['database'] ?? null) !== 'novel_forge_test'
            || ($resolved['host'] ?? null) !== '127.0.0.1'
            || (string) ($resolved['port'] ?? '') !== '5432'
            || isset($resolved['read']) || isset($resolved['write'])
            || ! empty($resolved['options']) || ! empty($resolved['service'])) {
            throw new RuntimeException('Refusing unsafe testing database configuration.');
        }

        // Keep only approved connector inputs; URL query options cannot add a second route.
        return array_intersect_key($resolved, array_flip([
            'driver', 'database', 'host', 'port', 'username', 'password',
            'charset', 'prefix', 'prefix_indexes', 'search_path', 'sslmode',
        ]));
    }

    public static function configure(Application $app): void
    {
        $config = $app['config'];
        if ($config->get('database.default') !== 'pgsql') {
            throw new RuntimeException('Testing requires the approved PostgreSQL connection.');
        }
        $database = self::database($config->get('database.connections.pgsql'));
        $config->set('database.connections', ['pgsql' => $database]);
        $redis = ['scheme' => 'unix', 'path' => $app->basePath('.local/redis-testing.sock'), 'database' => 0];
        $config->set('database.redis', [
            'client' => 'predis',
            'options' => ['prefix' => 'novelforge_testing_'],
            'default' => $redis,
            'cache' => array_merge($redis, ['database' => 1]),
        ]);
        $config->set('queue.default', 'sync');
        $config->set('queue.connections', [
            'sync' => ['driver' => 'sync'],
            'redis' => ['driver' => 'redis', 'connection' => 'default', 'queue' => 'testing', 'retry_after' => 120, 'block_for' => null, 'after_commit' => false],
            'database' => ['driver' => 'database', 'connection' => 'pgsql', 'table' => 'jobs', 'queue' => 'testing', 'retry_after' => 120, 'after_commit' => false],
        ]);
        $config->set('queue.batching.database', 'pgsql');
        $config->set('queue.failed', ['driver' => 'database-uuids', 'database' => 'pgsql', 'table' => 'failed_jobs']);
        $config->set('cache.default', 'array');
        $config->set('cache.prefix', 'novelforge_testing_cache_');
        $config->set('cache.stores', [
            'array' => ['driver' => 'array', 'serialize' => false],
            'file' => ['driver' => 'file', 'path' => $app->storagePath('framework/cache/data'), 'lock_path' => $app->storagePath('framework/cache/locks')],
            'redis' => ['driver' => 'redis', 'connection' => 'cache', 'lock_connection' => 'default'],
            'database' => ['driver' => 'database', 'connection' => 'pgsql', 'table' => 'cache', 'lock_connection' => 'pgsql', 'lock_table' => 'cache_locks'],
        ]);
        $config->set('filesystems.default', 'local');
        $config->set('filesystems.disks', [
            'local' => ['driver' => 'local', 'root' => $app->storagePath('app/private'), 'throw' => true],
            'public' => ['driver' => 'local', 'root' => $app->storagePath('app/public'), 'throw' => true],
        ]);
        $config->set('filesystems.links', []);
        $config->set('session.driver', 'array');
        $config->set('session.files', $app->storagePath('framework/sessions'));
        $config->set('view.compiled', $app->storagePath('framework/views'));
        $config->set('logging.default', 'single');
        $config->set('logging.channels', ['single' => ['driver' => 'single', 'path' => $app->storagePath('logs/laravel.log')]]);
        $config->set('mail.default', 'array');
    }
}
