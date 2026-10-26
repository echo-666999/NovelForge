<?php

namespace Tests\Feature;

use App\Filament\Pages\Billing;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\View\ViewException;
use Livewire\Livewire;
use Tests\TestCase;

/** UI10 只改变展示；筛选、页码和导出参数仍使用既有公共属性。 */
class BillingSelectTest extends TestCase
{
    use RefreshDatabase;

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
