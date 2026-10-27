<?php

namespace Tests\Feature;

use App\Filament\Concerns\EditsProductionPayloads;
use App\Filament\Concerns\HasProductionChoices;
use App\Filament\Pages\Billing;
use App\Filament\Support\JsonFields;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Livewire;
use Tests\TestCase;

/** UI15只验证展示投影和已有服务约束，所有HTTP保持禁止。 */
class FormAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): void
    {
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** 按语义键定位，不绑定JSONB或编辑器的属性顺序。 */
    private function path($page, string $root, array $keys): string
    {
        $node = $page->get('trees.'.$root);
        $path = 'trees.'.$root;
        foreach ($keys as $key) {
            $i = array_search($key, array_column($node['children'], 'key'), true);
            $this->assertNotFalse($i);
            $node = $node['children'][$i]['node'];
            $path .= '.children.'.$i.'.node';
        }

        return $path;
    }

    private function budget(): array
    {
        return ['key' => 'ui15.budget', 'expected_version' => 0, 'currency' => 'U', 'limits' => array_fill_keys(['request', 'chapter', 'run', 'book', 'day'], '123.4500000001'), 'warning_percent' => 80, 'stop_percent' => 100, 'timezone' => 'UTC', 'reason' => '仅验证离线表单错误'];
    }

    /** 无关筛选与价格字段不能收到预算错误；修正不丢精度或重复新增。 */
    public function test_budget_errors_are_scoped_localized_associated_and_clear_after_correction(): void
    {
        $this->actor();
        $page = Livewire::test(Billing::class)->set('policyPayload', json_encode($this->budget()));
        $policy = $this->path($page, 'policy_payload', ['currency']);
        $price = $this->path($page, 'price_payload', ['currency']);
        $page->call('saveBilling', 'policy')->assertHasErrors($policy.'.value')->assertHasNoErrors(['currency', $price.'.value'])->assertSee('币种格式无效。')->assertDontSee('The currency');
        $id = 'nf-prod-'.substr(hash('sha256', $policy), 0, 20);
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$page->html());
        $control = $dom->getElementById($id);
        $this->assertSame('true', $control->getAttribute('aria-invalid'));
        $this->assertContains($id.'-error', explode(' ', $control->getAttribute('aria-describedby')));
        $this->assertNotNull($dom->getElementById($id.'-error'));
        $page->assertDispatched('nf-validation-failed');
        $this->assertSame('U', data_get($page->get('trees'), substr($policy, 6).'.value'));
        $this->assertDatabaseCount('budget_policies', 0);
        $page->set(JsonFields::binding($policy.'.value'), 'USD')->call('saveBilling', 'policy')->assertHasNoErrors()->call('saveBilling', 'policy')->assertHasNoErrors();
        $this->assertDatabaseCount('budget_policies', 1);
        $this->assertSame('123.4500000001', json_decode(DB::table('budget_policies')->value('limits'), true)['request']);
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$page->html());
        $this->assertSame('false', $dom->getElementById($id)->getAttribute('aria-invalid'));
        $this->assertStringNotContainsString($id.'-error', $dom->getElementById($id)->getAttribute('aria-describedby'));
        $this->assertDatabaseCount('ai_requests', 0);
        Http::assertNothingSent();
    }

    /** 缺失字段只有容器可定位，不能降级为同名筛选错误。 */
    public function test_missing_budget_field_targets_its_container_and_next_action_clears_old_errors(): void
    {
        $this->actor();
        $data = $this->budget();
        unset($data['currency']);
        $page = Livewire::test(Billing::class)->set('policyPayload', json_encode($data))->call('saveBilling', 'policy')->assertHasErrors('trees.policy_payload')->assertHasNoErrors('currency');
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$page->html());
        $id = 'nf-prod-'.substr(hash('sha256', 'trees.policy_payload'), 0, 20);
        $this->assertSame('fieldset', $dom->getElementById($id)->tagName);
        $this->assertSame('true', $dom->getElementById($id)->getAttribute('aria-invalid'));
        $this->assertSame($id.'-error', $dom->getElementById($id)->getAttribute('aria-describedby'));
        $this->assertNotNull($dom->getElementById($id.'-error'));
        $page->call('saveBilling', 'price')->assertHasNoErrors('trees.policy_payload');
        $this->assertDatabaseCount('budget_policies', 0);
        Http::assertNothingSent();
    }

    public function test_filter_errors_never_map_into_payloads_and_clear_on_valid_read(): void
    {
        $this->actor();
        $page = Livewire::test(Billing::class)->set('currency', 'U')->assertSee('资料读取失败')->assertSet('currency', 'U');
        $this->assertSame(['currency'], $page->instance()->getErrorBag()->keys());
        $page->set('currency', 'USD')->assertHasNoErrors()->assertSee('暂无费用记录');
        Http::assertNothingSent();
    }

    /** 双载荷同名字段按前缀区分，裸字段歧义只进摘要。 */
    public function test_prefixed_and_ambiguous_errors_do_not_broadcast_between_trees(): void
    {
        Livewire::component('ui15-error-harness', Ui15ErrorHarness::class);
        $page = Livewire::test('ui15-error-harness')->call('fail', ['left.message' => '左错误', 'right.message' => '右错误']);
        $left = $this->path($page, 'left_payload', ['message']).'.value';
        $right = $this->path($page, 'right_payload', ['message']).'.value';
        $this->assertSame(['左错误'], $page->instance()->getErrorBag()->get($left));
        $this->assertSame(['右错误'], $page->instance()->getErrorBag()->get($right));
        $page->call('fail', ['message' => '无法唯一定位'])->assertHasErrors('production')->assertHasNoErrors([$left, $right, 'message']);
        $page->call('failDirect')->assertHasErrors('trees.left_payload')->assertHasNoErrors('currency');
    }

    /** 原生三种控件在SSR阶段就有完整ARIA，外部说明保留且不留悬空引用。 */
    public function test_native_controls_inherit_field_aria_without_extra_border_wrapper(): void
    {
        foreach (['input', 'textarea', 'select'] as $tag) {
            $html = Blade::render('<p id="external">外部说明</p><x-nf.field id="native" label="字段" help="填写说明" error="请修正"><x-nf.control :tag="$tag" aria-describedby="external native-error" /></x-nf.field>', ['tag' => $tag]);
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
            $e = $dom->getElementById('native');
            $this->assertSame($tag, $e->tagName);
            $this->assertSame('true', $e->getAttribute('aria-invalid'));
            $refs = explode(' ', $e->getAttribute('aria-describedby'));
            $this->assertEqualsCanonicalizing(['external', 'native-help', 'native-error'], $refs);
            foreach ($refs as $ref) {
                $this->assertNotNull($dom->getElementById($ref));
            }
            $this->assertSame(1, (new \DOMXPath($dom))->query('//*[@id="native"]')->length);
        }
        $html = Blade::render('<x-nf.field id="clear" label="字段"><x-nf.control aria-describedby="clear-error clear-help" /></x-nf.field>');
        $this->assertStringNotContainsString('aria-describedby=', $html);
        $this->assertStringContainsString('aria-invalid="false"', $html);
    }

    /** 高级导入的失败保留旧树，只在本次导入框出现错误。 */
    public function test_import_errors_are_scoped_and_preserve_existing_trees(): void
    {
        $this->actor();
        $page = Livewire::test(Billing::class)->set('billingNotice', '上一次错误');
        $before = $page->get('trees');
        $page->set('productionImports.policyPayload', '{"currency":1,"currency":2}')
            ->call('importProductionPayload', 'policyPayload')
            ->assertHasErrors('productionImports.policyPayload')
            ->assertHasNoErrors(['importJson', 'productionImports.pricePayload'])->assertSet('billingNotice', '');
        $this->assertSame($before, $page->get('trees'));
        Http::assertNothingSent();
    }

    /** 单选与复选组均可关联外部传入的字段错误，独立渲染无需全局错误袋。 */
    public function test_story_option_adapter_has_scoped_error_semantics(): void
    {
        foreach ([false, true] as $multiple) {
            $html = Blade::render('<x-nf.story-select id="choice" label="引用" model="selection" :multiple="$multiple" error="请修正引用"><option value="0">零值</option></x-nf.story-select>', ['multiple' => $multiple]);
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
            $e = $dom->getElementById('choice');
            $this->assertSame('true', $e->getAttribute('aria-invalid'));
            $this->assertSame('choice-error', $e->getAttribute('aria-describedby'));
            $this->assertNotNull($dom->getElementById('choice-error'));
        }
    }

    /** 类型、布尔值与没有输入框的空值错误均关联本组，避免留下悬空ARIA。 */
    public function test_generic_type_boolean_and_null_errors_have_associated_targets(): void
    {
        foreach (['boolean', 'null'] as $type) {
            $errors = (new ViewErrorBag)->put('default', new MessageBag([
                ...($type === 'boolean' ? ['trees.payload' => ['本组错误']] : []), 'trees.payload.type' => ['类型错误'], 'trees.payload.value' => ['值错误'],
            ]));
            $html = Blade::render('@include("filament.json-field")', ['node' => ['type' => $type, 'value' => false, 'children' => []], 'path' => 'trees.payload', 'label' => '测试字段', 'errors' => $errors]);
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
            foreach (['nf-json-trees-payload', 'nf-json-trees-payload-type', ...($type === 'boolean' ? ['nf-json-trees-payload-value'] : [])] as $id) {
                $target = $dom->getElementById($id);
                $this->assertSame('true', $target->getAttribute('aria-invalid'));
                $this->assertSame($id.'-error', $target->getAttribute('aria-describedby'));
                $this->assertNotNull($dom->getElementById($id.'-error'));
            }
        }
    }

    public function test_global_search_instructions_and_status_are_chinese(): void
    {
        $this->assertStringContainsString('向下键', __('filament-panels::global-search.field.instructions'));
        $this->assertSame('全局搜索结果', __('filament-panels::global-search.results_label'));
        $this->assertStringContainsString('2', trans_choice('filament-panels::global-search.results_count', 2, ['count' => 2]));
        $this->assertStringNotContainsString('Press ', __('filament-panels::global-search.field.instructions'));
    }

    /** 按相对亮度公式独立检查实际令牌的普通文字阈值，不靠截图肉眼判断。 */
    public function test_pencil_text_has_required_contrast_on_all_declared_surfaces(): void
    {
        $css = file_get_contents(resource_path('css/filament/admin/theme.css'));
        preg_match_all('/--nf-pencil: (#[0-9a-f]{6});/', $css, $fg);
        foreach (['control', 'paper', 'manuscript'] as $surface) {
            preg_match_all('/--nf-'.$surface.': (#[0-9a-f]{6});/', $css, $bg);
            foreach ([0, 1] as $theme) {
                $luminance = function (string $hex): float {
                    $rgb = array_map(fn ($v) => hexdec($v) / 255, str_split(substr($hex, 1), 2));
                    $rgb = array_map(fn ($v) => $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $rgb);

                    return $rgb[0] * 0.2126 + $rgb[1] * 0.7152 + $rgb[2] * 0.0722;
                };
                $a = $luminance($fg[1][$theme]);
                $b = $luminance($bg[1][$theme]);
                $this->assertGreaterThanOrEqual(4.5, (max($a, $b) + 0.05) / (min($a, $b) + 0.05));
            }
        }
    }
}

/** 仅供错误投影负例：两棵同构树不涉及数据库服务或新业务。 */
class Ui15ErrorHarness extends Component
{
    use EditsProductionPayloads;
    use HasProductionChoices;

    public string $leftPayload = '';

    public string $rightPayload = '';

    protected function productionPayloads(): array
    {
        return array_fill_keys(['leftPayload', 'rightPayload'], ['type' => 'object', 'properties' => ['message' => ['type' => 'string']]]);
    }

    public function fail(array $messages): void
    {
        $this->beginProductionAction();
        $this->productionValue('leftPayload', 'left');
        $this->productionValue('rightPayload', 'right');
        $this->productionErrors(ValidationException::withMessages($messages));
    }

    /** 未注册字段仍属于整体载荷，不能退回同名筛选。 */
    public function failDirect(): void
    {
        $this->beginProductionAction();
        $this->productionValue('leftPayload');
        $this->productionErrors(ValidationException::withMessages(['currency' => '未注册字段']));
    }

    public function render(): string
    {
        return '<div>只测试错误投影</div>';
    }
}
