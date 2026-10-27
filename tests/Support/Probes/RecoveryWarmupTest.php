<?php

namespace Tests\Support\Probes;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RecoveryEnvironment;
use Tests\TestCase;

/** 有序回归预热RefreshDatabase静态状态，重现原全量运行的前置条件。 */
class RecoveryWarmupTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = parent::createApplication();
        // 必须早于RefreshDatabase trait的迁移，缺Redis不能产生预热副作用。
        RecoveryEnvironment::assertReady();

        return $app;
    }

    public function test_warm_refresh_database_state_without_leaving_an_admin(): void
    {
        $this->assertDatabaseCount('users', 0);
    }
}
