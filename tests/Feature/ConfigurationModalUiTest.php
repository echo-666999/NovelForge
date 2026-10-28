<?php

namespace Tests\Feature;

use App\Filament\Pages\ModelRoutes;
use App\Filament\Pages\PromptTemplates;
use App\Filament\Pages\Providers;
use App\Filament\Pages\StyleProfiles;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** UI23只验证展示状态与原保存契约，全部请求限制在离线测试环境。 */
class ConfigurationModalUiTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): void
    {
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_catalog_pages_do_not_render_create_forms_until_requested(): void
    {
        $this->actor();
        foreach ([PromptTemplates::class, ModelRoutes::class, StyleProfiles::class] as $class) {
            Livewire::test($class)->assertSet('editorOpen', false)
                ->assertDontSeeHtml('id="nf-configuration-editor"')
                ->call('beginConfiguration')->assertSet('editorOpen', true)
                ->assertSeeHtml('id="nf-configuration-editor"')
                ->assertDispatched('configuration-editor-opened')
                ->call('cancelConfiguration')->assertSet('editorOpen', false)
                ->assertDontSeeHtml('id="nf-configuration-editor"')
                ->assertDispatched('close-modal', id: 'nf-configuration-editor-modal');
        }
        $this->assertDatabaseCount('prompt_versions', 0);
        $this->assertDatabaseCount('route_versions', 0);
        $this->assertDatabaseCount('style_versions', 0);
    }

    public function test_failed_save_keeps_editor_values_and_operation_key_then_success_closes(): void
    {
        $this->actor();
        $page = Livewire::test(PromptTemplates::class)->call('beginConfiguration');
        $key = $page->get('saveKey');
        $page->set('data.reason', '弹窗失败保值')->call('saveConfiguration')
            ->assertHasErrors('data.key')->assertSet('editorOpen', true)->assertSet('saveKey', $key)
            ->assertSet('data.reason', '弹窗失败保值')->assertDispatched('nf-validation-failed');
        $this->assertDatabaseCount('prompt_versions', 0);
        $page->set('data.key', 'ui23.modal')->call('saveConfiguration')->assertHasNoErrors()
            ->assertSet('editorOpen', false)->assertDispatched('close-modal', id: 'nf-configuration-editor-modal');
        $this->assertDatabaseCount('prompt_versions', 1);
        Http::assertNothingSent();
    }

    public function test_provider_and_model_creation_are_lazy_and_cancel_has_no_writes(): void
    {
        $this->actor();
        $page = Livewire::test(Providers::class)->assertDontSeeHtml('id="provider-new"')->assertDontSeeHtml('id="model-new"');
        foreach (['provider', 'model'] as $kind) {
            $page->call('beginProviderCreation', $kind)->assertSet($kind.'Editor', 'new')
                ->assertSeeHtml('id="'.$kind.'-new"')->assertDispatched('provider-editor-opened', kind: $kind)
                ->call('closeProviderEditor', $kind)->assertSet($kind.'Editor', '')
                ->assertDontSeeHtml('id="'.$kind.'-new"');
        }
        $this->assertDatabaseCount('providers', 0);
        $this->assertDatabaseCount('model_profiles', 0);
        Http::assertNothingSent();
    }

    public function test_native_post_failure_reopens_only_matching_new_form_without_secret(): void
    {
        $this->actor();
        $payload = ['ui_form' => 'provider-new', 'command_key' => 'ui23-invalid-provider', 'name' => '弹窗校验供应商', 'expected_version' => 0,
            'interface_type' => 'fake', 'endpoint' => 'https://ui.example.test/v1', 'allowed_hosts' => ['ui.example.test'], 'api_key' => 'ui23-never-flash-secret',
            'timeout_seconds' => 0, 'connect_timeout_seconds' => 5, 'requests_per_minute' => 10, 'max_concurrency' => 1, 'enabled' => false, 'reason' => '仅验证错误回填'];
        $this->post('/api/v1/providers/provider', $payload)->assertRedirect('/admin/providers#provider-new')->assertSessionHasErrors('timeout_seconds');
        $this->assertNull(session('_old_input.api_key'));
        Livewire::test(Providers::class)->assertSet('providerEditor', 'new')->assertSet('modelEditor', '')
            ->assertSeeHtml('id="provider-new"')->assertDontSeeHtml('id="model-new"')->assertDontSee('ui23-never-flash-secret');
        $this->assertDatabaseCount('providers', 0);
        Http::assertNothingSent();
    }
}
