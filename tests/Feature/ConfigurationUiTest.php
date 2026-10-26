<?php

namespace Tests\Feature;

use App\Filament\Pages\ModelRoutes;
use App\Filament\Pages\PromptTemplates;
use App\Filament\Pages\Providers;
use App\Filament\Pages\StyleProfiles;
use App\Filament\Support\JsonFields;
use App\Models\User;
use App\Services\AuthoringConfigurationService;
use App\Services\ProviderConfigurationService;
use App\Support\ConfigurationExamples;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\ProviderFixture;
use Tests\TestCase;

/** UI04所有写入都经过原服务，字段和交互测试仅使用隔离测试库与离线夹具。 */
class ConfigurationUiTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        Http::preventStrayRequests();
        $actor = User::factory()->create(['is_admin' => true]);
        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $actor;
    }

    private function command(User $actor, string $kind, string $action, array $p): array
    {
        return app(AuthoringConfigurationService::class)->execute($actor, $kind, $action, $p, (string) Str::uuid());
    }

    public function test_native_prompt_create_regress_publish_copy_and_stale_are_independent(): void
    {
        $actor = $this->actor();
        $page = Livewire::test(PromptTemplates::class)->call('beginConfiguration')
            ->set('data.key', 'ui.prompt')->set('data.reason', '逐字段建立草稿')->call('saveConfiguration')->assertHasNoErrors()->assertSee('草稿 v1 已保存');
        $id = (string) DB::table('prompt_versions')->value('id');
        $this->assertEquals(ConfigurationExamples::all()['prompt']['content'], app(AuthoringConfigurationService::class)->version('prompt', $id)['content']);
        $page->call('saveConfiguration');
        $this->assertDatabaseCount('prompt_versions', 1);
        $page->call('configurationAction', 'publish', $id)->assertHasErrors('configuration');
        $page->call('configurationAction', 'test', $id)->assertHasNoErrors()->call('configurationAction', 'publish', $id)->assertHasNoErrors();
        $original = app(AuthoringConfigurationService::class)->version('prompt', $id);
        $page->call('showConfiguration', $id)->assertSee('只读版本')->call('beginConfiguration', $id)->set('data.reason', '新版本保留历史')->set('data.content.body', '新正文 {{json:topic}}');
        $other = Livewire::test(PromptTemplates::class)->call('beginConfiguration', $id)->set('data.reason', '并发新版本')->call('saveConfiguration')->assertHasNoErrors();
        $page->call('saveConfiguration')->assertHasErrors('configuration')->assertSet('data.content.body', '新正文 {{json:topic}}')->assertSet('editorOpen', true);
        $this->assertSame($original['content'], app(AuthoringConfigurationService::class)->version('prompt', $id)['content']);
        $this->assertDatabaseCount('prompt_versions', 2);
        Http::assertNothingSent();
    }

    public function test_types_nested_schema_unknown_keys_and_duplicate_import_are_lossless_or_blocked(): void
    {
        $this->actor();
        $json = '{"unknown":{"flag":false,"zero":0,"empty":"","nil":null,"list":[],"object":{}},"order":["乙","甲"],"number":0.25}';
        $v = JsonFields::parse($json);
        $tree = JsonFields::node($v);
        $this->assertEquals($v, JsonFields::value($tree));
        $this->assertSame(['乙', '甲'], JsonFields::value($tree)->order);
        foreach (['{"x":1,"x":2}', '{"a":{"x":1,"x":2}}'] as $bad) {
            try {
                JsonFields::parse($bad);
                $this->fail('重复键必须被拒绝');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('importJson', $e->errors());
            }
        }
        $page = Livewire::test(PromptTemplates::class)->call('beginConfiguration')->set('data.key', 'ui.schema')->set('data.reason', '验证不支持Schema不丢值');
        $content = ConfigurationExamples::all()['prompt']['content'];
        $content['output_schema']['oneOf'] = [['type' => 'string']];
        $page->set('importJson', json_encode($content))->call('importConfigurationContent')->call('saveConfiguration')->assertHasErrors('trees.output_schema')->assertSet('editorOpen', true);
        $this->assertSame($content['output_schema'], JsonFields::serviceValue(JsonFields::value($page->get('trees')['output_schema']), 'schema'));
        $before = $page->get('trees');
        $page->set('importJson', '{bad')->call('importConfigurationContent')->assertHasErrors('importJson')->assertSet('trees', $before);
        $this->assertDatabaseCount('prompt_versions', 0);
        Http::assertNothingSent();
    }

    public function test_field_errors_are_mapped_and_failed_values_and_unsafe_text_remain_escaped(): void
    {
        $this->actor();
        $page = Livewire::test(StyleProfiles::class)->call('beginConfiguration')->set('data.key', 'ui.style')->set('data.reason', '字段错误定位');
        $tree = $page->get('trees');
        // rubric的第一维第二个属性为threshold；路径由真实树查找，不依赖对象键排序。
        $dimension = $tree['rubric']['children'][0]['node'];
        $i = array_search('threshold', array_column($dimension['children'], 'key'));
        $path = 'trees.rubric.children.0.node.children.'.$i.'.node.value';
        $page->set(JsonFields::binding($path), '101')->call('saveConfiguration')->assertHasErrors($path)->assertSet($path, '101');
        $page->set(JsonFields::binding($path), '80')->set('data.content.voice.description', '<script>不可执行</script>')->call('saveConfiguration')->assertHasNoErrors();
        $id = (string) DB::table('style_versions')->value('id');
        $page->call('showConfiguration', $id)->assertSee('&lt;script&gt;不可执行&lt;/script&gt;', false)->assertDontSeeHtml('<script>不可执行</script>');
    }

    public function test_ordered_rows_can_move_and_duplicate_property_names_or_implicit_loss_are_rejected(): void
    {
        $this->actor();
        $p = Livewire::test(PromptTemplates::class)->call('beginConfiguration');
        $p->call('addJsonField', 'trees.examples')->assertCount('trees.examples.children', 2)
            ->call('changeJsonRow', 'trees.examples', 1, 'up')->assertSet('trees.examples.children.0.node.type', 'string')
            ->call('changeJsonRow', 'trees.examples', 0, 'remove')->assertCount('trees.examples.children', 1);
        $p->call('addJsonField', 'trees.variable_schema')->set(JsonFields::binding('trees.variable_schema.children.4.key'), 'type')
            ->set('data.key', 'ui.duplicate')->set('data.reason', '重复属性阻止覆盖')->call('saveConfiguration')->assertHasErrors('trees.variable_schema.children.4.key');
        $p->set(JsonFields::binding('trees.variable_schema.type'), 'string')->call('saveConfiguration')->assertHasErrors('trees.variable_schema.type');
        $p->call('addJsonField', 'data.key')->assertHasErrors('trees');
        $this->assertDatabaseCount('prompt_versions', 0);
    }

    public function test_route_uses_real_versions_and_ordered_approved_fallbacks_without_model_calls(): void
    {
        $f = ProviderFixture::create();
        $this->actingAs($f['actor']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $refs = [];
        foreach (['prompt', 'style'] as $kind) {
            $v = $this->command($f['actor'], $kind, 'save', ConfigurationExamples::all()[$kind]);
            $this->command($f['actor'], $kind, 'test', ['version_id' => $v['id']]);
            $refs[$kind] = $this->command($f['actor'], $kind, 'publish', ['version_id' => $v['id']])['id'];
        }
        $page = Livewire::test(ModelRoutes::class)->call('beginConfiguration')->assertSee('主模型能力版本')->assertSee($f['model_payload']['provider_model_id'])
            ->set('data.key', 'ui.route')->set('data.reason', '选择实际发布引用')->set('data.content.prompt_version_id', $refs['prompt'])->set('data.content.style_version_id', $refs['style'])->set('data.content.primary_model_version_id', $f['model']['version_id'])->call('saveConfiguration')->assertHasNoErrors();
        $id = (string) DB::table('route_versions')->value('id');
        $page->call('configurationAction', 'test', $id)->assertHasNoErrors()->call('configurationAction', 'publish', $id)->assertHasNoErrors();
        $this->assertSame('published', app(AuthoringConfigurationService::class)->version('route', $id)['status']);
        $this->assertDatabaseCount('ai_requests', 0);
        Http::assertNothingSent();
    }

    public function test_native_model_fields_preserve_null_and_errors_never_flash_secret(): void
    {
        $f = ProviderFixture::create();
        $this->actingAs($f['actor']);
        $payload = ['ui_form' => 'model-new', 'provider_id' => $f['provider']['id'], 'provider_model_id' => 'ui.model', 'expected_version' => '0', 'context_tokens' => '', 'max_output_tokens' => '', 'structured_output' => 'unknown', 'streaming' => 'unknown', 'reasoning_metering' => 'unknown', 'source_note' => '无实测保持未知', 'reason' => '逐字段模型声明', 'command_key' => (string) Str::uuid()];
        $this->post('/api/v1/providers/model', $payload)->assertRedirect('/admin/providers')->assertSessionHas('provider_notice');
        $row = DB::table('model_profile_versions as v')->join('model_profiles as p', 'p.id', '=', 'v.model_profile_id')->where('p.provider_model_id', 'ui.model')->first();
        $cap = json_decode($row->declared_capabilities, true);
        $this->assertNull($cap['context_tokens']);
        $this->assertNull($cap['max_output_tokens']);
        $this->post('/api/v1/providers/model', array_replace($payload, ['context_tokens' => '100', 'max_output_tokens' => '101', 'command_key' => (string) Str::uuid()]))->assertSessionHas('provider_error')->assertSessionHas('_old_input.source_note', '无实测保持未知');
        $bad = array_replace($f['provider_payload'], ['ui_form' => 'provider-new', 'timeout_seconds' => '0', 'command_key' => (string) Str::uuid(), 'api_key' => 'must-not-appear-in-old-input']);
        $this->post('/api/v1/providers/provider', $bad)->assertSessionHasErrors('timeout_seconds');
        $this->assertNull(session('_old_input.api_key'));
        $this->get('/admin/providers')->assertOk()->assertDontSee('must-not-appear-in-old-input')->assertDontSee('declared_capabilities_json');
        Http::assertNothingSent();
    }

    public function test_structured_compare_distinguishes_types_and_preserves_list_order_and_auth(): void
    {
        $actor = $this->actor();
        $p = ConfigurationExamples::all()['prompt'];
        $a = $this->command($actor, 'prompt', 'save', $p);
        $p['expected_version'] = 1;
        $p['content']['body'] = '修改 {{json:topic}}';
        $b = $this->command($actor, 'prompt', 'save', $p);
        Livewire::test(PromptTemplates::class)->set('compareLeft', $a['id'])->set('compareRight', $b['id'])->assertSee('版本字段比较')->assertSee('已变化')->assertDontSee('payload_json');
        $rows = JsonFields::rows(['a' => false, 'b' => 0, 'c' => '', 'd' => null, 'e' => []]);
        $this->assertSame(['布尔', '整数', '文本', '空值', '有序列表'], array_column(array_slice($rows, 1), 'type'));
        $this->actingAs(User::factory()->create())->get('/admin/prompt-templates')->assertForbidden();
        $this->get('/admin/providers')->assertForbidden();
    }

    public function test_supported_nested_schema_round_trips_false_zero_null_empty_text_and_array(): void
    {
        $this->actor();
        $content = ConfigurationExamples::all()['prompt']['content'];
        $content['variable_schema'] = ['type' => 'object', 'properties' => ['payload' => ['type' => 'object', 'properties' => ['flag' => ['type' => 'boolean'], 'count' => ['type' => 'integer'], 'empty' => ['type' => 'string', 'maxLength' => 0], 'rows' => ['type' => 'array', 'items' => ['type' => 'null'], 'maxItems' => 2]], 'required' => ['flag', 'count', 'empty', 'rows'], 'additionalProperties' => false]], 'required' => ['payload'], 'additionalProperties' => false];
        $content['body'] = '按数据输出 {{json:payload}}';
        $content['examples'][0]['variables'] = ['payload' => ['flag' => false, 'count' => 0, 'empty' => '', 'rows' => [null]]];
        $page = Livewire::test(PromptTemplates::class)->call('beginConfiguration')->set('data.key', 'ui.nested')->set('data.reason', '复杂结构真实往返')->set('importJson', json_encode($content))->call('importConfigurationContent')->call('saveConfiguration')->assertHasNoErrors();
        $id = (string) DB::table('prompt_versions')->value('id');
        $actual = app(AuthoringConfigurationService::class)->version('prompt', $id)['content'];
        $this->assertEquals($content, $actual);
        $this->assertSame(false, $actual['examples'][0]['variables']['payload']['flag']);
        $this->assertSame(0, $actual['examples'][0]['variables']['payload']['count']);
        $this->assertSame('', $actual['examples'][0]['variables']['payload']['empty']);
        $this->assertSame([null], $actual['examples'][0]['variables']['payload']['rows']);
        $page->call('configurationAction', 'test', $id)->assertHasNoErrors();
        $this->assertTrue(app(AuthoringConfigurationService::class)->version('prompt', $id)['regression']['passed']);
    }

    public function test_schema_semantic_controls_edit_required_properties_and_preserve_incompatible_keywords(): void
    {
        $this->actor();
        $page = Livewire::test(PromptTemplates::class)->call('beginConfiguration')->call('addSchemaProperty', 'trees.output_schema');
        $tree = $page->get('trees.output_schema');
        $propsIndex = array_search('properties', array_column($tree['children'], 'key'));
        $propPath = 'trees.output_schema.children.'.$propsIndex.'.node.children.1.node';
        $page->call('toggleSchemaRequired', 'trees.output_schema', 1)->assertSee('必填 ✓')->set(JsonFields::binding($propPath.'.children.0.node.value'), 'array');
        $node = data_get($page->get('trees'), substr($propPath, 6));
        $this->assertContains('items', array_column($node['children'], 'key'));
        $page->call('addSchemaLimit', $propPath, 'maxItems');
        $page->call('removeSchemaProperty', 'trees.output_schema', 1);
        $schema = JsonFields::serviceValue(JsonFields::value($page->get('trees.output_schema')), 'output_schema');
        $this->assertSame(['text'], $schema['required']);
        $this->assertArrayNotHasKey('field_2', $schema['properties']);
        $page->set(JsonFields::binding('trees.output_schema.children.0.node.value'), 'string')->set('data.key', 'ui.typechange')->set('data.reason', '类型改变不得隐式清理')->call('saveConfiguration')->assertHasErrors('trees.output_schema');
        $this->assertContains('properties', array_column($page->get('trees.output_schema')['children'], 'key'));
        $this->assertDatabaseCount('prompt_versions', 0);
        $page->call('beginConfiguration')->call('removeSchemaProperty', 'trees.output_schema', 0);
        $empty = JsonFields::value($page->get('trees.output_schema'));
        $this->assertInstanceOf(\stdClass::class, $empty->properties);
        $page->set('data.key', 'ui.empty-schema')->set('data.reason', '不能把空对象变成列表')->call('saveConfiguration')->assertHasErrors();
        $this->assertDatabaseCount('prompt_versions', 0);
    }

    public function test_unknown_extensions_export_without_type_loss_and_empty_object_never_aliases_array_on_save(): void
    {
        $this->actor();
        $content = json_decode(json_encode(ConfigurationExamples::all()['prompt']['content']));
        $content->future = (object) ['obj' => (object) [], 'list' => [], 'flag' => false, 'num' => 0.25];
        $content->capability_requirements->future = (object) [];
        $page = Livewire::test(PromptTemplates::class)->call('beginConfiguration')->set('data.key', 'ui.future')->set('data.reason', '扩展类型保留')->set('importJson', json_encode($content))->call('importConfigurationContent')->assertSee('未映射扩展字段')->call('exportConfigurationContent')->assertFileDownloaded('configuration-content.json');
        $export = json_decode(base64_decode(data_get($page->effects, 'download.content')));
        $this->assertEquals($content->future, $export->future);
        $this->assertInstanceOf(\stdClass::class, $export->capability_requirements->future);
        $page->call('saveConfiguration')->assertHasErrors();
        $content = ConfigurationExamples::all()['prompt']['content'];
        $content['examples'][0]['output'] = (object) [];
        $page->set('importJson', json_encode($content))->call('importConfigurationContent')->call('saveConfiguration')->assertHasErrors();
        $this->assertDatabaseCount('prompt_versions', 0);
    }

    /** 确认弹窗的取消、确认、不可变复制和伪造字段分别验证。 */
    public function test_modal_cancel_confirm_and_flat_field_guard(): void
    {
        $actor = $this->actor();
        $v = $this->command($actor, 'prompt', 'save', ConfigurationExamples::all()['prompt']);
        $page = Livewire::test(PromptTemplates::class)
            ->call('mountAction', 'configurationLifecycle', ['operation' => 'test', 'version' => $v['id']])
            ->assertSet('mountedActions.0.name', 'configurationLifecycle');
        $this->assertSame('确认离线回归', $page->instance()->getMountedAction()->getModalHeading());
        $page->call('unmountAction');
        $this->assertNull(app(AuthoringConfigurationService::class)->version('prompt', $v['id'])['regression']);
        $page->call('mountAction', 'configurationLifecycle', ['operation' => 'test', 'version' => $v['id']])
            ->call('callMountedAction')->assertHasNoErrors();
        $this->assertTrue(app(AuthoringConfigurationService::class)->version('prompt', $v['id'])['regression']['passed']);
        $page->call('beginConfiguration', $v['id'])->set('data.key', 'forged.identity')->set('data.stage', 'forged.stage')
            ->set('data.reason', '身份由锁定来源决定')->call('saveConfiguration')->assertHasNoErrors();
        $this->assertDatabaseCount('prompt_templates', 1);
        $this->assertDatabaseCount('prompt_versions', 2);
        $page->call('beginConfiguration', $v['id'])->set('jsonInputs.forged', 'bad')->assertHasErrors('trees');
        $this->assertDatabaseCount('ai_requests', 0);
        Http::assertNothingSent();
    }

    /** 原生Repeater使用UUID键，导出必须恢复有序列表和纯文本样例。 */
    public function test_repeater_uuid_rows_export_as_ordered_values(): void
    {
        $this->actor();
        $page = Livewire::test(StyleProfiles::class)->call('beginConfiguration');
        $page->set('data.content.voice.licensed_examples', ['row-b' => ['text' => '乙样例'], 'row-a' => ['text' => '甲样例']])
            ->call('exportConfigurationContent');
        $export = json_decode(base64_decode(data_get($page->effects, 'download.content')), true);
        $this->assertSame(['乙样例', '甲样例'], $export['voice']['licensed_examples']);
        // 服务端按序号返回的错误必须落回原生Repeater的行键。
        $page->set('data.key', 'ui.row-errors')->set('data.reason', '原生行错误映射')
            ->set('data.content.voice.licensed_examples.row-b.text', str_repeat('长', 5001))
            ->call('saveConfiguration')->assertHasErrors('data.content.voice.licensed_examples.row-b.text')
            ->assertSet('editorOpen', true);
        $this->assertDatabaseCount('style_versions', 0);
        $page = Livewire::test(ModelRoutes::class)->call('beginConfiguration');
        $rows = ['row-b' => ['model_version_id' => '2', 'approved' => true, 'reason' => '乙'], 'row-a' => ['model_version_id' => '1', 'approved' => false, 'reason' => '甲']];
        $page->set('data.content.fallbacks', $rows)->call('exportConfigurationContent');
        $export = json_decode(base64_decode(data_get($page->effects, 'download.content')), true);
        $this->assertSame(array_values($rows), $export['fallbacks']);
    }

    /** 历史投影只允许安全列；折叠状态不渲染全部原生编辑表单。 */
    public function test_provider_history_is_safe_and_editors_are_lazy(): void
    {
        $f = ProviderFixture::create();
        $this->actingAs($f['actor']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $service = app(ProviderConfigurationService::class);
        $payload = array_replace($f['provider_payload'], ['provider_id' => $f['provider']['id'], 'expected_version' => 1, 'timeout_seconds' => 21, 'api_key' => '', 'reason' => '安全历史比较']);
        $service->execute($f['actor'], 'provider', $payload, 'ui-history-provider');
        $id = $f['provider']['id'];
        $mid = $f['model']['version_id'];
        $page = Livewire::test(Providers::class)
            ->assertDontSeeHtml('id="provider-'.$id.'"')->call('openProviderEditor', $id)
            ->assertSeeHtml('id="provider-'.$id.'"')->call('openModelEditor', $mid)
            ->assertSeeHtml('id="model-'.$mid.'"')->call('readConfigurationHistory', 'provider', $id);
        $history = $page->get('historyVersions');
        $this->assertCount(2, $history);
        $serialized = json_encode($history);
        foreach (['secret_id', 'ciphertext', 'fixture-secret-key-123'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
        $this->assertSame([21, 20], array_column(array_column(array_values($history), 'value'), 'timeout_seconds'));
        $page->call('readConfigurationHistory', 'model', $mid);
        $this->assertCount(1, $page->get('historyVersions'));
        Http::assertNothingSent();
    }

    /** 失败只返回同源白名单筛选与目标锚点，外部Referer不能影响跳转。 */
    public function test_native_failure_returns_to_same_origin_filter_and_never_redirects_external(): void
    {
        $f = ProviderFixture::create();
        $this->actingAs($f['actor']);
        $p = array_replace($f['provider_payload'], ['ui_form' => 'provider-'.$f['provider']['id'], 'timeout_seconds' => '0', 'command_key' => (string) Str::uuid()]);
        $this->withHeader('Referer', config('app.url').'/admin/providers?q=local&page=2&evil=discarded')
            ->post('/api/v1/providers/provider', $p)->assertRedirect('/admin/providers?q=local&page=2#'.$p['ui_form']);
        $this->withHeader('Referer', 'https://evil.example/admin/providers?q=external')
            ->post('/api/v1/providers/provider', $p)->assertRedirect('/admin/providers#'.$p['ui_form']);
        $this->assertNull(session('_old_input.api_key'));
        $this->get('/admin/providers')->assertSeeHtml('id="'.$p['ui_form'].'"')->assertDontSee('fixture-secret-key-123');
        Http::assertNothingSent();
    }
}
