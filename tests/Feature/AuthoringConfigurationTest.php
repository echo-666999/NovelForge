<?php

namespace Tests\Feature;

use App\Exceptions\NovelProblem;
use App\Filament\Pages\PromptTemplates;
use App\Generation\StageResult;
use App\Models\User;
use App\ProvidersGateway\FakeProvider;
use App\Services\AuthoringConfigurationService;
use App\Services\GenerationService;
use App\Services\ProviderConfigurationService;
use App\Services\ProviderGateway;
use App\Support\ConfigurationExamples;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\BillingFixture;
use Tests\Support\ProviderFixture;
use Tests\TestCase;

/** C04 全部采用离线样例，覆盖发布门禁、历史快照和显式主备尝试。 */
class AuthoringConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private function command(User $actor, string $kind, string $action, array $p, ?string $key = null): array
    {
        return app(AuthoringConfigurationService::class)->execute($actor, $kind, $action, $p, $key ?? (string) Str::uuid());
    }

    private function release(User $actor, string $kind, array $p): array
    {
        $v = $this->command($actor, $kind, 'save', $p);
        $tested = $this->command($actor, $kind, 'test', ['version_id' => $v['id']]);
        $this->assertTrue($tested['regression']['passed']);

        return $this->command($actor, $kind, 'publish', ['version_id' => $v['id']]);
    }

    private function fixture(): array
    {
        $f = ProviderFixture::create();
        $samples = ConfigurationExamples::all();
        $f['prompt'] = $this->release($f['actor'], 'prompt', $samples['prompt']);
        $f['style'] = $this->release($f['actor'], 'style', $samples['style']);
        $m = $f['model_payload'];
        $m['provider_model_id'] = 'backup-model';
        $backup = app(ProviderConfigurationService::class)->execute($f['actor'], 'model', $m, 'backup');
        $f['backup'] = $backup;
        $backupPrice = BillingFixture::price($f['actor'], $backup['id'], 'backup-price');
        $f['payload']['billing']['price_version_ids'][] = $backupPrice['id'];
        $route = $samples['route'];
        $route['content']['prompt_version_id'] = $f['prompt']['id'];
        $route['content']['style_version_id'] = $f['style']['id'];
        $route['content']['primary_model_version_id'] = $f['model']['id'];
        $route['content']['fallbacks'] = [['model_version_id' => $backup['id'], 'approved' => true, 'reason' => '明确批准的离线备用']];
        $f['route_payload'] = $route;
        $f['route'] = $this->release($f['actor'], 'route', $route);
        $f['configured_payload'] = $f['payload'] + ['configuration' => ['route_version_id' => $f['route']['id'], 'variables' => ['topic' => '故乡']]];
        $f['configured_run'] = app(GenerationService::class)->start($f['actor'], $f['novelId'], $f['configured_payload'], 'configured')['run'];

        return $f;
    }

    private function problem(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail('应拒绝：'.$code);
        } catch (NovelProblem $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    public function test_release_idempotency_and_immutable_content(): void
    {
        $f = ProviderFixture::create();
        $p = ConfigurationExamples::all()['prompt'];
        $a = $this->command($f['actor'], 'prompt', 'save', $p, 'same');
        $this->assertEquals($a, $this->command($f['actor'], 'prompt', 'save', $p, 'same'));
        $p['reason'] = '不同原因';
        $this->problem('IDEMPOTENCY_CONFLICT', fn () => $this->command($f['actor'], 'prompt', 'save', $p, 'same'));
        $this->problem('REGRESSION_REQUIRED', fn () => $this->command($f['actor'], 'prompt', 'publish', ['version_id' => $a['id']]));
        $this->expectException(QueryException::class);
        DB::table('prompt_versions')->where('id', $a['id'])->update(['content' => '{}']);
    }

    public function test_missing_variables_unsafe_template_bad_schema_and_fact_omission_fail_regression(): void
    {
        $f = ProviderFixture::create();
        $sample = ConfigurationExamples::all()['prompt'];
        foreach (['missing', 'unsafe', 'schema', 'fact'] as $case) {
            $p = $sample;
            $p['key'] = $case;
            if ($case === 'missing') {
                $p['content']['examples'][0]['variables'] = [];
            }
            if ($case === 'unsafe') {
                $p['content']['body'] = '{{topic}}';
            }
            if ($case === 'schema') {
                $p['content']['examples'][0]['output'] = ['text' => 123];
            }
            if ($case === 'fact') {
                $p['content']['examples'][0]['required_facts'] = ['不存在的事实'];
            }
            $v = $this->command($f['actor'], 'prompt', 'save', $p);
            $test = $this->command($f['actor'], 'prompt', 'test', ['version_id' => $v['id']]);
            $this->assertFalse($test['regression']['passed']);
            $this->assertSame('draft', $test['status']);
            $this->problem('REGRESSION_REQUIRED', fn () => $this->command($f['actor'], 'prompt', 'publish', ['version_id' => $v['id']]));
        }
    }

    public function test_json_escaping_and_stage_output_contract(): void
    {
        $f = ProviderFixture::create();
        $p = ConfigurationExamples::all()['prompt'];
        $svc = app(AuthoringConfigurationService::class);
        $render = $svc->render($p['content'], ['topic' => '"</script>{{json:secret}}']);
        $this->assertStringNotContainsString('</script>', $render);
        $this->assertStringContainsString('\\u0022', $render);
        $this->assertStringContainsString('{{json:secret}}', $render);
        $p['content']['output_schema'] = ['type' => 'integer'];
        $p['content']['examples'][0]['output'] = 1;
        $p['content']['examples'][0]['required_facts'] = [];
        $v = $this->command($f['actor'], 'prompt', 'save', $p);
        $this->command($f['actor'], 'prompt', 'test', ['version_id' => $v['id']]);
        $this->problem('CONTRACT_MISMATCH', fn () => $this->command($f['actor'], 'prompt', 'publish', ['version_id' => $v['id']]));
    }

    public function test_new_publications_and_retirement_do_not_change_frozen_runs_or_old_scores(): void
    {
        $f = $this->fixture();
        $old = $f['configured_run'];
        $samples = ConfigurationExamples::all();
        $p = $samples['prompt'];
        $p['expected_version'] = 1;
        $p['content']['body'] = '新版正文 {{json:topic}}';
        $p['reason'] = '新版本';
        $new = $this->release($f['actor'], 'prompt', $p);
        $style = $samples['style'];
        $style['expected_version'] = 1;
        $style['reason'] = '降低阈值用于新策略';
        $style['content']['rubric']['voice']['threshold'] = 60;
        $this->release($f['actor'], 'style', $style);
        $this->command($f['actor'], 'prompt', 'retire', ['version_id' => $f['prompt']['id']]);
        $actual = app(GenerationService::class)->read($f['actor'], $f['novelId'], $old['id'])['run'];
        $this->assertSame($old['frozen_config'], $actual['frozen_config']);
        $this->assertSame($old['input_hash'], $actual['input_hash']);
        $cfg = $actual['frozen_config']['configuration'];
        $this->assertSame(70, $cfg['style']['content']['rubric']['voice']['threshold']);
        $this->assertNotNull($cfg['price_version_id']);
        $this->assertFalse($cfg['real_model_eligible']);
        $this->assertSame($new['id'], DB::table('prompt_templates')->value('default_version_id').'');
        $this->problem('UNPUBLISHED_REFERENCE', fn () => app(GenerationService::class)->start($f['actor'], $f['novelId'], $f['configured_payload'], 'after-retirement'));
        $this->assertEquals($old, app(GenerationService::class)->start($f['actor'], $f['novelId'], $f['configured_payload'], 'configured')['run']);
    }

    public function test_approved_switch_creates_new_attempt_and_preserves_actual_request_identity(): void
    {
        $f = $this->fixture();
        $g = app(GenerationService::class);
        $gateway = app(ProviderGateway::class);
        $run = $f['configured_run'];
        $claim = $g->claim($f['actor'], $f['novelId'], $run['id'], 'first');
        $cfg = $run['frozen_config']['configuration'];
        $input = ['prompt' => $cfg['rendered_prompt'], 'output_schema' => $cfg['prompt']['content']['output_schema']];
        $first = $gateway->execute($f['actor'], $f['novelId'], $claim['id'], $f['model']['id'], $input, 'request');
        $this->assertSame('succeeded', $first['status']);
        $this->assertSame($f['route']['id'], $first['route_version_id']);
        $this->assertSame($f['prompt']['id'], $first['prompt_version_id']);
        $this->problem('INVALID_MODEL_SWITCH', fn () => $g->claim($f['actor'], $f['novelId'], $run['id'], 'first', 1, $f['backup']['id'], '主动切换'));
        $g->complete($f['actor'], $f['novelId'], $run['id'], $claim, new StageResult('failed', ['text' => '模拟阶段质量失败'], 'FIXTURE_FAILED'));
        $this->problem('UNAPPROVED_FALLBACK', fn () => $g->claim($f['actor'], $f['novelId'], $run['id'], 'first', 1, '999999', '未批准'));
        $next = $g->claim($f['actor'], $f['novelId'], $run['id'], 'first', 1, $f['backup']['id'], '已批准备用，首尝试质量不符');
        $this->assertNotSame($claim['id'], $next['id']);
        $second = $gateway->execute($f['actor'], $f['novelId'], $next['id'], $f['backup']['id'], $input, 'request');
        $this->assertSame('succeeded', $second['status']);
        $rows = DB::table('ai_requests')->where('run_id', $run['id'])->orderBy('id')->get();
        $this->assertSame([$f['model']['id'], $f['backup']['id']], $rows->map(fn ($r) => (string) $r->model_profile_version_id)->all());
        $this->assertSame($f['route']['id'], (string) $rows[1]->route_version_id);
        $this->assertSame(2, DB::table('generation_steps')->where('run_id', $run['id'])->count());
        $this->assertNull(DB::table('generation_steps')->where('id', $next['id'])->value('resume_artifact_id'));
        Http::assertNothingSent();
    }

    public function test_bound_gateway_rejects_input_and_model_bypass(): void
    {
        $f = $this->fixture();
        $claim = app(GenerationService::class)->claim($f['actor'], $f['novelId'], $f['configured_run']['id'], 'first');
        $gateway = app(ProviderGateway::class);
        $this->problem('CONFIGURATION_MISMATCH', fn () => $gateway->execute($f['actor'], $f['novelId'], $claim['id'], $f['model']['id'], $f['input'], 'bad'));
        $cfg = $f['configured_run']['frozen_config']['configuration'];
        $input = ['prompt' => $cfg['rendered_prompt'], 'output_schema' => $cfg['prompt']['content']['output_schema']];
        $this->problem('CONFIGURATION_MISMATCH', fn () => $gateway->execute($f['actor'], $f['novelId'], $claim['id'], $f['backup']['id'], $input, 'bad-model'));
        $this->assertSame(0, DB::table('ai_requests')->count());
    }

    public function test_production_route_and_capacity_mismatch_fail_offline_publication(): void
    {
        $f = $this->fixture();
        foreach (['production', 'capacity'] as $case) {
            $p = $f['route_payload'];
            $p['key'] = $case;
            if ($case === 'production') {
                $p['content']['mode'] = 'production';
            } else {
                $p['content']['parameters']['max_output_tokens'] = 5000;
            }
            $v = $this->command($f['actor'], 'route', 'save', $p);
            $test = $this->command($f['actor'], 'route', 'test', ['version_id' => $v['id']]);
            $this->assertFalse($test['regression']['passed']);
            $this->problem('REGRESSION_REQUIRED', fn () => $this->command($f['actor'], 'route', 'publish', ['version_id' => $v['id']]));
        }
    }

    public function test_admin_api_pages_filters_copy_compare_and_permission_boundaries(): void
    {
        $this->getJson('/api/v1/configurations/prompt')->assertUnauthorized();
        $u = User::factory()->create();
        $this->actingAs($u)->getJson('/api/v1/configurations/prompt')->assertForbidden();
        $f = $this->fixture();
        $this->actingAs($f['actor']);
        foreach (['prompt-templates', 'model-routes', 'style-profiles'] as $path) {
            $this->get('/admin/'.$path)->assertOk()->assertSee('离线');
        }
        $this->getJson('/api/v1/configurations/prompt?status=published')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/v1/configurations/prompt?language=en')->assertJsonPath('data.total', 0);
        $p = ConfigurationExamples::all()['prompt'];
        $p['key'] = 'api.prompt';
        $r = $this->postJson('/api/v1/configurations/prompt/save', $p, ['Idempotency-Key' => 'api'])->assertOk();
        $this->postJson('/api/v1/configurations/prompt/save', $p, ['Idempotency-Key' => 'api'])->assertJsonPath('data.id', $r->json('data.id'));
        $this->post('/api/v1/configurations/prompt/save', ['command_key' => 'html', 'payload_json' => 'bad'])->assertRedirect('/admin/prompt-templates')->assertSessionHas('configuration_error');
        Livewire::test(PromptTemplates::class)->set('status', 'published')->assertSee('echo.prompt')->set('compareLeft', $f['prompt']['id'])->set('compareRight', $f['prompt']['id'])->assertSee('left');
    }

    public function test_all_new_tables_and_columns_have_chinese_database_comments(): void
    {
        $tables = ['prompt_templates', 'prompt_versions', 'model_routes', 'route_versions', 'style_profiles', 'style_versions', 'configuration_regressions', 'configuration_commands'];
        foreach ($tables as $table) {
            $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', DB::selectOne('SELECT obj_description(?::regclass) AS comment', [$table])->comment);
            $cols = DB::select('SELECT a.attname,col_description(c.oid,a.attnum) AS comment FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace JOIN pg_attribute a ON a.attrelid=c.oid WHERE n.nspname=current_schema() AND c.relname=? AND a.attnum>0 AND NOT a.attisdropped', [$table]);
            foreach ($cols as $col) {
                $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $col->comment ?? '', $table.'.'.$col->attname);
            }
        }
        foreach (['generation_runs' => ['configuration_route_version_id'], 'ai_requests' => ['route_version_id', 'prompt_version_id', 'style_version_id', 'configuration_hash']] as $table => $cols) {
            foreach ($cols as $column) {
                $row = DB::selectOne('SELECT col_description(?::regclass,ordinal_position) AS comment FROM information_schema.columns WHERE table_name=? AND column_name=?', [$table, $table, $column]);
                $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $row->comment ?? '');
            }
        }
    }

    public function test_unknown_request_outcome_prevents_switch_and_ordinary_retry(): void
    {
        $f = $this->fixture();
        $g = app(GenerationService::class);
        $run = $f['configured_run'];
        $claim = $g->claim($f['actor'], $f['novelId'], $run['id'], 'first');
        $cfg = $run['frozen_config']['configuration'];
        $input = ['prompt' => $cfg['rendered_prompt'], 'output_schema' => $cfg['prompt']['content']['output_schema']];
        $reply = app(ProviderGateway::class)->execute($f['actor'], $f['novelId'], $claim['id'], $f['model']['id'], $input, 'unknown', new FakeProvider(timeout: true));
        $this->assertSame('unknown', $reply['status']);
        $g->complete($f['actor'], $f['novelId'], $run['id'], $claim, new StageResult('failed', ['text' => '超时证据'], 'FIXTURE_TIMEOUT'));
        $this->problem('UNKNOWN_REQUEST_OUTCOME', fn () => $g->claim($f['actor'], $f['novelId'], $run['id'], 'first', 1, $f['backup']['id'], '超时切换'));
        $this->problem('UNKNOWN_REQUEST_OUTCOME', fn () => $g->claim($f['actor'], $f['novelId'], $run['id'], 'first', 1));
        $this->assertSame(1, DB::table('generation_steps')->where('run_id', $run['id'])->count());
    }

    public function test_provider_rotation_does_not_replace_frozen_provider_and_disable_blocks(): void
    {
        $f = $this->fixture();
        $g = app(GenerationService::class);
        $run = $f['configured_run'];
        $p = $f['provider_payload'];
        $p['provider_id'] = $f['provider']['id'];
        $p['expected_version'] = 1;
        $p['api_key'] = 'rotated-fixture-key';
        app(ProviderConfigurationService::class)->execute($f['actor'], 'provider', $p, 'rotate');
        $claim = $g->claim($f['actor'], $f['novelId'], $run['id'], 'first');
        $cfg = $run['frozen_config']['configuration'];
        $input = ['prompt' => $cfg['rendered_prompt'], 'output_schema' => $cfg['prompt']['content']['output_schema']];
        $gateway = app(ProviderGateway::class);
        $result = $gateway->execute($f['actor'], $f['novelId'], $claim['id'], $f['model']['id'], $input, 'frozen');
        $this->assertSame($cfg['models'][0]['provider_version_id'], (string) DB::table('ai_requests')->where('id', $result['id'])->value('provider_version_id'));
        $p['expected_version'] = 2;
        $p['enabled'] = false;
        app(ProviderConfigurationService::class)->execute($f['actor'], 'provider', $p, 'disable');
        $this->problem('PROVIDER_DISABLED', fn () => $gateway->execute($f['actor'], $f['novelId'], $claim['id'], $f['model']['id'], $input, 'disabled'));
    }

    public function test_route_rechecks_retired_sources_before_publication(): void
    {
        $f = $this->fixture();
        $p = $f['route_payload'];
        $p['key'] = 'another.route';
        $v = $this->command($f['actor'], 'route', 'save', $p);
        $this->command($f['actor'], 'route', 'test', ['version_id' => $v['id']]);
        $this->command($f['actor'], 'style', 'retire', ['version_id' => $f['style']['id']]);
        $this->problem('UNPUBLISHED_REFERENCE', fn () => $this->command($f['actor'], 'route', 'publish', ['version_id' => $v['id']]));
        $this->assertSame('tested', DB::table('route_versions')->where('id', $v['id'])->value('status'));
    }

    public function test_database_blocks_direct_publish_and_default_to_draft(): void
    {
        $f = ProviderFixture::create();
        $v = $this->command($f['actor'], 'prompt', 'save', ConfigurationExamples::all()['prompt']);
        foreach (['publish', 'default'] as $case) {
            try {
                DB::transaction(function () use ($case, $v) {
                    if ($case === 'publish') {
                        DB::table('prompt_versions')->where('id', $v['id'])->update(['status' => 'published']);
                    } else {
                        DB::table('prompt_templates')->where('id', $v['parent_id'])->update(['default_version_id' => $v['id']]);
                    }
                });
                $this->fail('数据库必须拒绝绕过发布门禁');
            } catch (QueryException $e) {
                $this->assertStringContainsString('C04', $e->getMessage());
            }
        }
    }

    public function test_style_regression_failure_and_stale_copy_are_rejected(): void
    {
        $f = ProviderFixture::create();
        $p = ConfigurationExamples::all()['style'];
        $p['content']['examples'][0]['scores']['voice'] = 10;
        $v = $this->command($f['actor'], 'style', 'save', $p);
        $tested = $this->command($f['actor'], 'style', 'test', ['version_id' => $v['id']]);
        $this->assertFalse($tested['regression']['passed']);
        $this->problem('STALE_VERSION', fn () => $this->command($f['actor'], 'style', 'save', $p));
        $this->problem('REGRESSION_REQUIRED', fn () => $this->command($f['actor'], 'style', 'publish', ['version_id' => $v['id']]));
    }

    public function test_default_route_is_resolved_only_for_new_runs(): void
    {
        $f = $this->fixture();
        $g = app(GenerationService::class);
        $p = $f['configured_payload'];
        $p['configuration'] = ['route_key' => $f['route']['key'], 'variables' => ['topic' => '故乡']];
        $before = $g->start($f['actor'], $f['novelId'], $p, 'default-before')['run'];
        $route = $f['route_payload'];
        $route['expected_version'] = 1;
        $route['reason'] = '新运行切换默认主模型';
        $route['content']['primary_model_version_id'] = $f['backup']['id'];
        $route['content']['fallbacks'] = [];
        $new = $this->release($f['actor'], 'route', $route);
        $after = $g->start($f['actor'], $f['novelId'], $p, 'default-after')['run'];
        $this->assertSame($f['route']['id'], $before['frozen_config']['configuration']['route']['id']);
        $this->assertSame($new['id'], $after['frozen_config']['configuration']['route']['id']);
        $this->assertEquals($before, $g->start($f['actor'], $f['novelId'], $p, 'default-before')['run']);
        $this->command($f['actor'], 'route', 'retire', ['version_id' => $new['id']]);
        $this->problem('UNPUBLISHED_REFERENCE', fn () => $g->start($f['actor'], $f['novelId'], $p, 'default-retired'));
    }

    public function test_fact_regression_uses_text_values_instead_of_json_field_names_or_escapes(): void
    {
        $f = ProviderFixture::create();
        $p = ConfigurationExamples::all()['prompt'];
        $p['content']['examples'][0]['output'] = ['text' => '他说"山/海"'];
        $p['content']['examples'][0]['required_facts'] = ['"山/海"'];
        $v = $this->release($f['actor'], 'prompt', $p);
        $this->assertSame('published', $v['status']);
        $p['key'] = 'false.fact';
        $p['content']['examples'][0]['required_facts'] = ['text'];
        $v = $this->command($f['actor'], 'prompt', 'save', $p);
        $test = $this->command($f['actor'], 'prompt', 'test', ['version_id' => $v['id']]);
        $this->assertFalse($test['regression']['passed']);
    }
}
