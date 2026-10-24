<?php

namespace App\Console;

/** 早期测试隔离拒绝尚无配置，不能执行依赖时区配置的命令生命周期收尾。 */
final class Kernel extends \Illuminate\Foundation\Console\Kernel
{
    /** 继承框架 Kernel 后显式保留原有自动发现，包含控制台路由与 Commands 目录。 */
    protected function shouldDiscoverCommands()
    {
        return true;
    }

    public function terminate($input, $status)
    {
        if (! $this->app->bound('config') && $this->app->bound('testing.isolation.requested') && $this->app->make('testing.isolation.requested') === true) {
            // 门禁已使命令失败；不载入任何兜底配置，也不执行尚未启动的应用收尾。
            return;
        }

        parent::terminate($input, $status);
    }
}
