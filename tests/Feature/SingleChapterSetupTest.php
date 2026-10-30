<?php

namespace Tests\Feature;

use App\Filament\Pages\SingleChapterSetup;
use App\Models\User;
use App\Services\ProviderConfigurationService;
use App\Services\SingleChapterSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\ProviderFixture;
use Tests\Support\QueuedRunFixture;
use Tests\TestCase;

/** 使用隔离 PostgreSQL 验证准备入口，不向供应商发请求。 */
class SingleChapterSetupTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_empty_configuration_is_explained_without_creating_runs(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(SingleChapterSetup::class)
            ->assertSee('尚无符合上述口径的记录')
            ->assertSee('真实协议适配与生产流水线尚未接通')
            ->assertSee('单章字数上下限')
            ->assertSee('原始创意、规划、正文、提取、评审和修订上下文范围');
        $this->assertDatabaseCount('generation_runs', 0);
        $this->assertDatabaseCount('ai_requests', 0);
        $this->assertDatabaseCount('novels', 0);
    }

    public function test_guest_and_non_admin_cannot_view_setup(): void
    {
        $this->get('/admin/single-chapter-setup')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get('/admin/single-chapter-setup')->assertForbidden();
    }

    public function test_fake_configuration_and_other_authors_books_are_not_counted_as_ready(): void
    {
        $f = ProviderFixture::create();
        $service = app(SingleChapterSetupService::class);
        $data = $service->inspect($f['actor']);
        $this->assertSame(1, $data['novels']);
        $this->assertSame(0, $data['providers']);
        $this->assertSame(0, $data['models']);
        $this->assertSame(1, $data['prices']);
        $this->assertSame(1, $data['budgets']);
        // 项目只允许一个管理员；交接管理身份后，旧作者的小说仍不能混入当前盘点。
        $f['actor']->forceFill(['is_admin' => false])->save();
        $this->assertSame(0, $service->inspect($this->admin())['novels']);
        $this->assertStringNotContainsString('fixture-secret', json_encode($data));
    }

    public function test_disabled_configuration_stays_recorded_and_latest_fake_version_is_excluded(): void
    {
        $f = ProviderFixture::create();
        $configuration = app(ProviderConfigurationService::class);
        // 只保存协议声明；本例不能被记录为供应商连通性或模型实测证据。
        $payload = array_replace($f['provider_payload'], [
            'provider_id' => $f['provider']['id'], 'expected_version' => 1,
            'interface_type' => 'json_http',
        ]);
        $configuration->execute($f['actor'], 'provider', $payload, 'configured-http');
        $service = app(SingleChapterSetupService::class);
        $this->assertSame(1, $service->inspect($f['actor'])['providers']);
        $this->assertSame(1, $service->inspect($f['actor'])['models']);
        $this->actingAs($f['actor']);
        Livewire::test(SingleChapterSetup::class)
            ->assertSee('真实协议适配与生产流水线尚未接通')
            ->assertDontSee('fixture-secret-key-123');
        $configuration->execute($f['actor'], 'provider', array_replace($payload, [
            'expected_version' => 2, 'enabled' => false,
        ]), 'disable-http');
        $this->assertSame(1, $service->inspect($f['actor'])['providers']);
        $this->assertSame(1, $service->inspect($f['actor'])['models']);
        $this->assertSame(1, $service->inspect($f['actor'])['disabled_providers']);
        // 最新版本改为离线协议时，不允许历史真实协议配置继续充当准备记录。
        $configuration->execute($f['actor'], 'provider', array_replace($payload, [
            'expected_version' => 3, 'enabled' => false, 'interface_type' => 'fake',
        ]), 'back-to-fake');
        $this->assertSame(0, $service->inspect($f['actor'])['providers']);
        $this->assertSame(0, $service->inspect($f['actor'])['models']);
        $this->assertDatabaseCount('ai_requests', 0);
    }

    /** 停用配置补齐密钥后直接引导价格预算，读取页面不得修改配置或触发请求。 */
    public function test_disabled_provider_and_model_can_continue_to_billing_without_side_effects(): void
    {
        Http::fake();
        $f = QueuedRunFixture::create();
        $service = app(ProviderConfigurationService::class);
        $payload = [
            'expected_version' => 0, 'name' => '待启用供应商', 'interface_type' => 'json_http',
            'endpoint' => 'https://api.example.com/v1/responses', 'allowed_hosts' => ['api.example.com'],
            'timeout_seconds' => 20, 'connect_timeout_seconds' => 5,
            'requests_per_minute' => 10, 'max_concurrency' => 1,
            'enabled' => false, 'reason' => '仅本地配置引导测试',
        ];
        $provider = $service->execute($f['actor'], 'provider', $payload, 'missing-key');
        $service->execute($f['actor'], 'model', [
            'provider_id' => $provider['id'], 'provider_model_id' => 'setup-test-model', 'expected_version' => 0,
            'declared_capabilities' => ['context_tokens' => null, 'max_output_tokens' => null,
                'structured_output' => 'unknown', 'streaming' => 'unknown', 'reasoning_metering' => 'unknown',
                'source_note' => '离线声明，不代表能力实测'], 'reason' => '配置准备测试',
        ], 'setup-model');
        $this->actingAs($f['actor']);
        Livewire::test(SingleChapterSetup::class)->assertSee('下一步：配置供应商');
        $provider = $service->execute($f['actor'], 'provider', array_replace($payload, [
            'provider_id' => $provider['id'], 'expected_version' => 1, 'api_key' => 'setup-test-secret',
        ]), 'add-key');
        $before = DB::table('provider_versions')->orderBy('id')->get()->toJson();
        $runs = DB::table('generation_runs')->count();
        $page = Livewire::test(SingleChapterSetup::class)
            ->assertSee('下一步：配置价格与预算')
            ->assertDontSee('下一步：配置供应商')
            ->assertDontSee('下一步：配置模型')
            ->assertSee('可真实运行：尚未具备')
            ->assertDontSee('setup-test-secret');
        $page->call('$refresh')->assertSee('下一步：配置价格与预算');
        $data = app(SingleChapterSetupService::class)->inspect($f['actor']);
        $this->assertSame(1, $data['providers']);
        $this->assertSame(1, $data['models']);
        $this->assertSame(1, $data['disabled_providers']);
        $this->assertSame($before, DB::table('provider_versions')->orderBy('id')->get()->toJson());
        $this->assertSame($runs, DB::table('generation_runs')->count());
        $this->assertDatabaseCount('ai_requests', 0);
        Http::assertNothingSent();
    }

    public function test_inventory_failure_is_not_zero_and_does_not_expose_exception(): void
    {
        $this->actingAs($this->admin());
        // 通过数据库连接故障覆盖真实查询失败路径，异常细节不进入页面。
        DB::shouldReceive('table')->once()
            ->with('provider_versions as v')->andThrow(new \RuntimeException('private-connection-detail'));
        $data = app(SingleChapterSetup::class)->inventory();
        $this->assertSame(['error' => '暂时无法读取准备状态，请重试。原有配置未修改。'], $data);
        $this->assertArrayNotHasKey('novels', $data);
        $this->assertStringNotContainsString('private-connection-detail', json_encode($data));
    }
}
