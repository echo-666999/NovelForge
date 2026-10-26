<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\View\ViewException;
use Tests\TestCase;

/** UI02 只验证主题链路、公共外壳与现有页面读路径，不创建业务功能。 */
class UiFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 本测试验证构建产物，不读取或删除开发者正在使用的 public/hot。
        app(Vite::class)->useHotFile(storage_path('ui02-no-hot-server'));
    }

    public function test_panel_registers_independent_compiled_theme(): void
    {
        $panel = Filament::getPanel('admin');
        $this->assertSame('resources/css/filament/admin/theme.css', $panel->getViteTheme());
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
        $theme = $manifest[$panel->getViteTheme()];
        $this->assertTrue($theme['isEntry']);
        $this->assertFileExists(public_path('build/'.$theme['file']));
        $this->get('/admin/login')->assertOk()->assertSee('/build/'.$theme['file'], false);
    }

    public function test_read_only_pages_keep_authentication_and_have_no_business_side_effects(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $before = $this->counts();
        foreach (['/admin', '/admin/prompt-templates', '/admin/extraction-ledger', '/admin/novels/create'] as $url) {
            $this->get($url)->assertOk()->assertSee('/build/assets/theme-', false);
        }
        // 主题不能顺带移动导航或让离线页面触发模型调用。
        $this->get('/admin')->assertSee('正文提取与硬约束')->assertSee('系统配置')->assertSee('尚未创建小说');
        $this->assertSame($before, $this->counts());
    }

    public function test_input_connects_native_label_help_error_and_preserves_attributes(): void
    {
        $html = Blade::render('<x-nf.input id="title" label="书名" help="填写候选标题" error="标题不能为空" :required="true" wire:model="title" name="title" value="&lt;script&gt;" />');
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $input = $dom->getElementById('title');
        $this->assertSame('title', $xpath->query('//label')->item(0)->getAttribute('for'));
        $this->assertSame('title-help title-error', $input->getAttribute('aria-describedby'));
        $this->assertSame('true', $input->getAttribute('aria-invalid'));
        $this->assertTrue($input->hasAttribute('required'));
        $this->assertSame('title', $input->getAttribute('wire:model'));
        $this->assertSame('alert', $dom->getElementById('title-error')->getAttribute('role'));
        $this->assertSame(0, $xpath->query('//script')->length);
    }

    public function test_readonly_and_disabled_are_native_control_states(): void
    {
        $readonly = Blade::render('<x-nf.input id="version" label="冻结版本" value="v1" :readonly="true" />');
        $disabled = Blade::render('<x-nf.input id="future" label="未接入" :disabled="true" />');
        $this->assertStringContainsString('readonly', $readonly);
        $this->assertStringContainsString('disabled', $disabled);
        $this->assertStringContainsString('fi-disabled', $disabled);
        $this->assertStringNotContainsString('aria-describedby=', $readonly);
    }

    public function test_section_form_and_table_use_native_semantics_and_escaped_labels(): void
    {
        $html = Blade::render('<x-nf.section heading="配置"><x-nf.form-grid :filters="true"><x-nf.field id="count" label="数量">内容</x-nf.field></x-nf.form-grid><x-nf.table label="候选列表"><table><tr><td>示例</td></tr></table></x-nf.table></x-nf.section>');
        $this->assertStringContainsString('fi-section', $html);
        $this->assertStringContainsString('nf-filter-grid', $html);
        $this->assertStringContainsString('fi-fo-field', $html);
        $this->assertStringContainsString('aria-label="候选列表"', $html);
        $this->assertStringContainsString('tabindex="0"', $html);
        $this->assertStringNotContainsString('<form', $html);
    }

    public function test_common_states_have_explicit_accessible_feedback(): void
    {
        foreach (['notice', 'loading', 'empty', 'ready', 'error', 'disabled', 'success'] as $state) {
            $html = Blade::render('<x-nf.state :state="$state" :title="$title">实际说明</x-nf.state>', ['state' => $state, 'title' => '<script>不可执行</script>']);
            $this->assertStringContainsString($state === 'error' ? 'role="alert"' : 'role="status"', $html);
            $this->assertStringContainsString('&lt;script&gt;', $html);
            $this->assertStringNotContainsString('<script>', $html);
            if ($state === 'loading') {
                $this->assertStringContainsString('aria-busy="true"', $html);
            }
        }
    }

    public function test_unknown_visual_state_is_not_silently_treated_as_success(): void
    {
        $this->expectException(ViewException::class);
        Blade::render('<x-nf.state state="unknown" title="未知">不应渲染</x-nf.state>');
    }

    private function counts(): array
    {
        return array_map(fn (string $table): int => DB::table($table)->count(), ['novels', 'novel_commands', 'generation_runs', 'ai_requests', 'canonical_commits']);
    }
}
