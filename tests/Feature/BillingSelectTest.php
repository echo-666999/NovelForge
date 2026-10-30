<?php

namespace Tests\Feature;

use App\Filament\Pages\Billing;
use App\Models\User;
use App\Services\ProviderConfigurationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\View\ViewException;
use Livewire\Livewire;
use Tests\Support\ProviderFixture;
use Tests\TestCase;

/** UI10 只改变展示；筛选、页码和导出参数仍使用既有公共属性。 */
class BillingSelectTest extends TestCase
{
    use RefreshDatabase;

    /** 同供应商的不同模型与同模型的历史能力版本均可辨识，选项值仍为能力版本ID。 */
    public function test_model_options_identify_provider_model_and_capability_version(): void
    {
        $f = ProviderFixture::create();
        $service = app(ProviderConfigurationService::class);
        $second = $service->execute($f['actor'], 'model', array_replace($f['model_payload'], [
            'provider_model_id' => 'review-model',
        ]), 'review-model');
        $newVersion = $service->execute($f['actor'], 'model', array_replace($f['model_payload'], [
            'expected_version' => 1, 'reason' => '更新能力来源声明',
        ]), 'generation-v2');
        $this->actingAs($f['actor']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $options = app(Billing::class)->productionChoices('model');
        $this->assertSame('离线供应商 / fixture-model · 能力 v1 · #'.$f['model']['version_id'], $options[$f['model']['version_id']]);
        $this->assertSame('离线供应商 / fixture-model · 能力 v2 · #'.$newVersion['version_id'], $options[$newVersion['version_id']]);
        $this->assertSame('离线供应商 / review-model · 能力 v1 · #'.$second['version_id'], $options[$second['version_id']]);
        $this->assertCount(3, $options);
        // 价格目录展示模型名称，详情仍使用原始能力版本标识。
        $catalog = app(Billing::class)->catalog();
        $this->assertSame('fixture-model', $catalog['model_names'][$f['model']['version_id']]);
        $page = Livewire::test(Billing::class)->assertSee('版本ID / 模型名称')
            ->assertSee('#'.$f['payload']['billing']['price_version_ids'][0].' / fixture-model')
            ->call('beginBillingEditor', 'price');
        foreach ($options as $label) {
            $page->assertSee($label);
        }
        $page->assertDontSee('fixture-secret-key-123');
        $this->assertDatabaseCount('ai_requests', 0);
    }

    public function test_billing_filters_preserve_values_pagination_and_export_scope(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $before = [DB::table('ai_requests')->count(), DB::table('generation_runs')->count()];
        Livewire::test(Billing::class)->set('page', 3)->set('status', 'reserved')
            ->assertSet('page', 1)->assertSee('费用状态：已预留')
            ->set('category', 'review')->assertSee('用途：评审')
            ->set('requestStatus', 'failed')->assertSee('请求状态：失败')
            ->assertSee('status=reserved', false)->assertSee('category=review', false)
            ->assertSee('request_status=failed', false)
            ->set('status', '')->assertSee('费用状态：全部');
        $this->assertSame($before, [DB::table('ai_requests')->count(), DB::table('generation_runs')->count()]);
    }

    public function test_menu_has_explicit_selection_and_safe_update_command(): void
    {
        $html = Blade::render('<x-nf.select id="state" label="状态" model="status" value="reserved" :options="$options" />', ['options' => ['' => '全部', 'reserved' => '已预留']]);
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame('状态：已预留', $dom->getElementById('state')->getAttribute('aria-label'));
        $this->assertSame(2, $xpath->query('//*[@role="menuitemradio"]')->length);
        $this->assertSame(1, $xpath->query('//*[@aria-checked="true"]')->length);
        $selected = $xpath->query('//*[@aria-checked="true"]')->item(0);
        $this->assertStringContainsString('reserved', $selected->getAttribute('wire:click'));
        $this->assertSame('close()', $selected->getAttribute('x-on:click'));
        $this->assertSame(0, $xpath->query('//select')->length);
    }

    public function test_labels_are_escaped_and_disabled_control_remains_disabled(): void
    {
        $html = Blade::render('<x-nf.select id="state" label="状态" model="status" :disabled="true" :options="$options" />', ['options' => ['' => '<script>禁止执行</script>']]);
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $this->assertTrue($dom->getElementById('state')->hasAttribute('disabled'));
        $this->assertSame(0, (new \DOMXPath($dom))->query('//script')->length);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_invalid_property_path_is_rejected(): void
    {
        $this->expectException(ViewException::class);
        Blade::render('<x-nf.select id="bad" label="状态" model="status);unsafe()" />');
    }
}
