<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'novelforge:admin';

    protected $description = '交互创建唯一管理员；不会生成默认密码';

    public function handle(): int
    {
        if (User::where('is_admin', true)->exists()) {
            $this->error('管理员已存在，禁止创建第二位管理员。');

            return self::FAILURE;
        }
        $email = $this->ask('管理员邮箱');
        $name = $this->ask('显示名称', '作者');
        $password = $this->secret('密码（至少12位）');
        $v = Validator::make(compact('email', 'name', 'password'), ['email' => 'required|email|unique:users,email', 'name' => 'required|string|max:255', 'password' => 'required|string|min:12']);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $error) {
                $this->error($error);
            }

return self::FAILURE;
        }
        $user = new User(compact('email', 'name', 'password'));
        $user->is_admin = true;
        $user->save();
        $this->info('管理员已创建，请访问 /admin 登录。');

        return self::SUCCESS;
    }
}
