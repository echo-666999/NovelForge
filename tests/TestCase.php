<?php

namespace Tests;

abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        if ($app['config']->get('database.connections.pgsql.database') !== 'novel_forge_test') {
            throw new \RuntimeException('Refusing to run tests outside novel_forge_test.');
        }

        return $app;
    }
}
