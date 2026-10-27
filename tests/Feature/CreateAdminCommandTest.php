<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** UI17只补既有命令行为回归；输入全部为合成测试资料。 */
class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_input_creates_one_admin_with_a_hashed_password(): void
    {
        $password = 'UI17-test-only-password';
        $this->artisan('novelforge:admin')
            ->expectsQuestion('管理员邮箱', 'ui17@example.test')
            ->expectsQuestion('显示名称', '格式回归作者')
            ->expectsQuestion('密码（至少12位）', $password)
            ->expectsOutput('管理员已创建，请访问 /admin 登录。')
            ->doesntExpectOutputToContain($password)
            ->assertExitCode(Command::SUCCESS);
        $this->assertDatabaseCount('users', 1);
        $user = User::firstOrFail();
        $this->assertTrue($user->is_admin);
        $this->assertSame('格式回归作者', $user->name);
        $this->assertSame('ui17@example.test', $user->email);
        $this->assertNotSame($password, $user->password);
        $this->assertTrue(Hash::check($password, $user->password));
    }

    public function test_existing_admin_is_rejected_without_creating_a_second_user(): void
    {
        User::factory()->create(['is_admin' => true]);
        $this->artisan('novelforge:admin')
            ->expectsOutput('管理员已存在，禁止创建第二位管理员。')
            ->assertExitCode(Command::FAILURE);
        $this->assertDatabaseCount('users', 1);
    }

    public static function invalidInput(): array
    {
        return [
            'invalid email' => ['invalid-email', '格式回归作者', 'UI17-test-only-password'],
            'missing name' => ['ui17@example.test', '', 'UI17-test-only-password'],
            'short password' => ['ui17@example.test', '格式回归作者', 'short'],
        ];
    }

    /** 验证失败分支必须保持非零退出且不写入用户；不修改校验规则。 */
    #[DataProvider('invalidInput')]
    public function test_invalid_input_does_not_create_an_admin(string $email, string $name, string $password): void
    {
        $this->artisan('novelforge:admin')
            ->expectsQuestion('管理员邮箱', $email)
            ->expectsQuestion('显示名称', $name)
            ->expectsQuestion('密码（至少12位）', $password)
            ->doesntExpectOutput('管理员已创建，请访问 /admin 登录。')
            ->assertExitCode(Command::FAILURE);
        $this->assertDatabaseCount('users', 0);
    }
}
