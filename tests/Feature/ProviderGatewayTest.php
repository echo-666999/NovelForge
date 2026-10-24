<?php

namespace Tests\Feature;

use App\Exceptions\NovelProblem;
use App\Filament\Pages\Providers;
use App\Models\User;
use App\ProvidersGateway\EndpointPolicy;
use App\ProvidersGateway\FakeProvider;
use App\ProvidersGateway\GuardedHttpAdapter;
use App\ProvidersGateway\ProviderAdapter;
use App\ProvidersGateway\ProviderReply;
use App\ProvidersGateway\PublicDnsResolver;
use App\ProvidersGateway\StructuredOutput;
use App\Services\ProviderConfigurationService;
use App\Services\ProviderGateway;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\Support\ProviderFixture;
use Tests\TestCase;

class ProviderGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function send(array $f, string $key = 'request', ?ProviderAdapter $adapter = null): array
    {
        return app(ProviderGateway::class)->execute($f['actor'], $f['novelId'], $f['claim']['id'], $f['model']['version_id'], $f['input'], $key, $adapter);
    }

    private function problem(string $code, callable $action): void
    {
        try {
            $action();
            $this->fail('操作应被拒绝');
        } catch (NovelProblem $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    public function test_configuration_secret_versions_and_claims_are_not_verified(): void
    {
        $f = ProviderFixture::create();
        $s = app(ProviderConfigurationService::class);
        $rows = $s->listing($f['actor']);
        $json = json_encode($rows);
        $this->assertStringNotContainsString('fixture-secret-key-123', $json);
        $this->assertStringNotContainsString('secret_id', $json);
        $this->assertStringNotContainsString('ciphertext', $json);
        $this->assertTrue($rows['providers'][0]['has_key']);
        $this->assertSame('unverified', $rows['models'][0]['verification_status']);
        $this->assertNull($rows['models'][0]['effective_capabilities']);
        $cipher = DB::table('provider_secrets')->value('ciphertext');
        $this->assertStringNotContainsString('fixture-secret', $cipher);
        $this->assertSame('fixture-secret-key-123', Crypt::decryptString($cipher));
        $this->assertEquals($f['provider'], $s->execute($f['actor'], 'provider', $f['provider_payload'], 'provider'));
        $p = array_replace($f['provider_payload'], ['provider_id' => $f['provider']['id'], 'expected_version' => 1, 'api_key' => 'rotated-fixture-secret']);
        $new = $s->execute($f['actor'], 'provider', $p, 'rotate');
        $this->assertSame(2, $new['version']);
        $this->assertDatabaseCount('provider_secrets', 2);
        $this->problem('IDEMPOTENCY_CONFLICT', fn () => $s->execute($f['actor'], 'provider', $p, 'provider'));
        $this->problem('STALE_STATE', fn () => $s->execute($f['actor'], 'provider', $p, 'stale'));
        $this->expectException(QueryException::class);
        DB::table('model_profile_versions')->update(['verification_status' => 'available']);
    }

    public function test_request_is_idempotent_with_private_evidence_and_frozen_configuration(): void
    {
        $f = ProviderFixture::create();
        $fake = new FakeProvider;
        $r = $this->send($f, adapter: $fake);
        $this->assertSame('succeeded', $r['status']);
        $this->assertSame('fake', $r['evidence_kind']);
        $this->assertSame('unverified', $r['capability_status']);
        $this->assertSame('not_billable_fake', $r['cost_status']);
        $this->assertEquals($r, $this->send($f, adapter: $fake));
        $this->assertSame(1, $fake->calls);
        $this->assertDatabaseCount('ai_requests', 1);
        $this->assertDatabaseCount('ai_request_evidence', 2);
        $this->assertSame($f['provider']['version_id'], $r['provider_version_id']);
        $text = app(ProviderGateway::class)->evidenceText($f['actor'], $f['novelId'], $r['id'], 'response');
        $this->assertStringContainsString('离线生成结果', $text);
        $f['input']['prompt'] = '不同请求';
        $this->problem('IDEMPOTENCY_CONFLICT', fn () => $this->send($f));
        Http::assertNothingSent();
    }

    public function test_malformed_json_and_structural_error_preserve_locatable_raw_evidence(): void
    {
        $f = ProviderFixture::create();
        foreach (['{broken' => 'INVALID_JSON', '{"output":{"text":1}}' => 'INVALID_STRUCTURED_OUTPUT', '{"output":[]}' => 'INVALID_STRUCTURED_OUTPUT'] as $body => $code) {
            $r = $this->send($f, $code.md5($body), new FakeProvider(new ProviderReply(200, $body)));
            $this->assertSame($code, $r['error_code']);
            $this->assertSame('failed', $r['status']);
            $this->assertNotEmpty($r['issues'][0]['path']);
            $this->assertSame($body, app(ProviderGateway::class)->evidenceText($f['actor'], $f['novelId'], $r['id'], 'response'));
        }
        $this->expectException(QueryException::class);
        DB::table('ai_request_evidence')->update(['content_hash' => str_repeat('0', 64)]);
    }

    public function test_echoed_secret_is_removed_from_metadata_and_encrypted_evidence(): void
    {
        $f = ProviderFixture::create();
        $secret = $f['provider_payload']['api_key'];
        $body = json_encode(['output' => ['text' => $secret], 'request_id' => $secret, 'usage' => ['note' => $secret]]);
        $body = str_replace('fixture', '\\u0066ixture', $body);
        $r = $this->send($f, adapter: new FakeProvider(new ProviderReply(200, $body)));
        $this->assertStringNotContainsString($secret, json_encode($r));
        $text = app(ProviderGateway::class)->evidenceText($f['actor'], $f['novelId'], $r['id'], 'response');
        $this->assertStringNotContainsString($secret, $text);
        $this->assertStringContainsString('[REDACTED]', $text);
        $this->assertTrue($r['evidence'][1]['redacted']);
    }

    public function test_unknown_outcome_is_not_retried_or_reported_as_zero_usage(): void
    {
        $f = ProviderFixture::create();
        $fake = new FakeProvider(timeout: true);
        $r = $this->send($f, adapter: $fake);
        $this->assertSame('unknown', $r['status']);
        $this->assertNull($r['usage']);
        $this->assertCount(1, $r['evidence']);
        $this->assertEquals($r, $this->send($f, adapter: $fake));
        $this->assertSame(1, $fake->calls);
    }

    public function test_transport_exception_does_not_log_or_persist_secret_text(): void
    {
        $f = ProviderFixture::create();
        Log::spy();
        $adapter = new class implements ProviderAdapter
        {
            public function send(array $request): ProviderReply
            {
                throw new \RuntimeException('Authorization: Bearer fixture-secret-key-123');
            }
        };
        $r = $this->send($f, adapter: $adapter);
        $this->assertSame('PROVIDER_OUTCOME_UNKNOWN', $r['error_code']);
        $this->assertStringNotContainsString('fixture-secret-key-123', json_encode($r));
        foreach (['error', 'warning', 'info', 'debug', 'log'] as $method) {
            Log::shouldNotHaveReceived($method);
        }
        $this->assertNull(app(ProviderGateway::class)->evidenceText($f['actor'], $f['novelId'], $r['id'], 'response'));
    }

    public function test_http_failures_are_recorded_without_automatic_retries(): void
    {
        $f = ProviderFixture::create();
        foreach ([302 => 'REDIRECT_BLOCKED', 401 => 'PROVIDER_AUTH_FAILED', 429 => 'PROVIDER_RATE_LIMITED', 500 => 'PROVIDER_HTTP_500'] as $status => $code) {
            $fake = new FakeProvider(new ProviderReply($status, '离线错误原文'));
            $r = $this->send($f, 'status'.$status, $fake);
            $this->assertSame($code, $r['error_code']);
            $this->assertSame(1, $fake->calls);
        }
    }

    public function test_real_provider_and_disabled_configuration_are_blocked_before_transport(): void
    {
        $f = ProviderFixture::create();
        $p = array_replace($f['provider_payload'], ['provider_id' => $f['provider']['id'], 'expected_version' => 1, 'interface_type' => 'json_http']);
        app(ProviderConfigurationService::class)->execute($f['actor'], 'provider', $p, 'real-config');
        $this->problem('BUDGET_GATE_REQUIRED', fn () => $this->send($f));
        $p['expected_version'] = 2;
        $p['enabled'] = false;
        app(ProviderConfigurationService::class)->execute($f['actor'], 'provider', $p, 'disable');
        $this->problem('PROVIDER_DISABLED', fn () => $this->send($f));
        $this->assertDatabaseCount('ai_requests', 0);
        Http::assertNothingSent();
    }

    public function test_rate_and_concurrency_limits_are_enforced_before_send(): void
    {
        $f = ProviderFixture::create();
        $p = array_replace($f['provider_payload'], ['provider_id' => $f['provider']['id'], 'expected_version' => 1, 'max_concurrency' => 1, 'requests_per_minute' => 1]);
        app(ProviderConfigurationService::class)->execute($f['actor'], 'provider', $p, 'limit');
        $this->send($f, 'one', new FakeProvider(timeout: true));
        $this->problem('RATE_LIMITED', fn () => $this->send($f, 'two'));
        $this->assertDatabaseCount('ai_requests', 1);
    }

    public function test_endpoint_policy_rejects_ssrf_and_pins_only_public_answers(): void
    {
        $policy = new EndpointPolicy;
        foreach (['http://api.example.com/a', 'https://127.0.0.1/a', 'https://[::1]/', 'https://2130706433/', 'https://localhost/', 'https://api.example.com@localhost/', 'https://api.example.com:444/a', 'https://api.example.com/a?x=1', 'https://api.example.com./a', 'https://api.example.com.evil.org/a'] as $url) {
            $this->problem('UNSAFE_ENDPOINT', fn () => $policy->host($url, ['api.example.com']));
        }
        foreach ([['127.0.0.1'], ['169.254.169.254'], ['10.0.0.1'], ['::1'], ['fc00::1'], ['fec0::1'], ['224.0.0.1'], ['ff02::1'], ['64:ff9b::7f00:1'], ['::ffff:127.0.0.1'], ['8.8.8.8', '192.168.1.1']] as $ips) {
            $this->problem('SSRF_BLOCKED', fn () => $policy->pinnedOptions('https://api.example.com/a', ['api.example.com'], $ips));
        }
        $options = $policy->pinnedOptions('https://api.example.com/a', ['api.example.com'], ['8.8.8.8']);
        $this->assertSame(['api.example.com:443:8.8.8.8'], $options['curl'][CURLOPT_RESOLVE]);
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame('', $options['proxy']);
        $this->assertTrue($options['verify']);
    }

    public function test_http_adapter_uses_fake_transport_and_does_not_follow_redirects(): void
    {
        $resolver = new class extends PublicDnsResolver
        {
            public function resolve(string $host): array
            {
                return ['8.8.8.8'];
            }
        };
        $adapter = new GuardedHttpAdapter(['endpoint' => 'https://api.example.com/a', 'allowed_hosts' => ['api.example.com'], 'timeout_seconds' => 20, 'connect_timeout_seconds' => 5], $resolver, 'only-fixture-key');
        Http::fake(['api.example.com/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/'])]);
        $reply = $adapter->send(['text' => 'fake']);
        $this->assertSame(302, $reply->status);
        $this->assertSame('', $reply->body);
        Http::assertSentCount(1);
    }

    public function test_schema_unsupported_keywords_fail_closed(): void
    {
        $this->problem('INVALID_SCHEMA', fn () => app(StructuredOutput::class)->schema(['type' => 'string', 'pattern' => '.*']));
        $this->problem('INVALID_SCHEMA', fn () => app(StructuredOutput::class)->schema(['type' => 'array', 'items' => 'invalid']));
        $issues = app(StructuredOutput::class)->issues((object) ['name' => 1], ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name'], 'additionalProperties' => false]);
        $this->assertSame('$.output.name', $issues[0]['path']);
    }

    public function test_inflight_lease_change_quarantines_result_and_expired_lease_prevents_send(): void
    {
        $f = ProviderFixture::create();
        $adapter = new class($f['claim']['id']) implements ProviderAdapter
        {
            public function __construct(private string $step) {}

            public function send(array $request): ProviderReply
            {
                // 模拟执行期间租约被回收，不触发真实模型或正式提交。
                DB::table('generation_steps')->where('id', $this->step)->update(['lease_until' => DB::raw("clock_timestamp() - interval '1 second'")]);

                return new ProviderReply(200, '{"output":{"text":"迟到结果"}}');
            }
        };
        $r = $this->send($f, adapter: $adapter);
        $this->assertSame('quarantined', $r['status']);
        $this->assertSame('STALE_REQUEST_RESULT', $r['error_code']);
        $this->assertCount(2, $r['evidence']);
        $this->problem('LEASE_LOST', fn () => $this->send($f, 'new'));
        $this->assertDatabaseCount('ai_requests', 1);
    }

    public function test_configuration_cannot_forge_verification_and_production_fake_is_blocked(): void
    {
        $f = ProviderFixture::create();
        $p = $f['model_payload'] + ['verification_status' => 'available'];
        $this->problem('INVALID_CONFIG', fn () => app(ProviderConfigurationService::class)->execute($f['actor'], 'model', $p, 'forged'));
        $this->app->instance('env', 'production');
        try {
            $this->problem('BUDGET_GATE_REQUIRED', fn () => $this->send($f));
        } finally {
            $this->app->instance('env', 'testing');
        }
        Http::assertNothingSent();
    }

    public function test_filters_and_native_form_rotate_without_secret_in_livewire_state(): void
    {
        $f = ProviderFixture::create();
        $this->actingAs($f['actor']);
        Livewire::test(Providers::class)->set('q', '不存在')->assertSee('暂无符合条件的供应商');
        $p = array_replace($f['provider_payload'], ['provider_id' => $f['provider']['id'], 'expected_version' => 1, 'command_key' => 'form-rotate', 'api_key' => 'native-form-new-secret', 'allowed_hosts_text' => 'api.example.com', 'enabled' => '1']);
        unset($p['allowed_hosts']);
        $this->post('/api/v1/providers/provider', $p)->assertRedirect('/admin/providers');
        $this->get('/admin/providers')->assertOk()->assertDontSee('native-form-new-secret');
        $this->assertDatabaseCount('provider_secrets', 2);
        $this->assertNull(session()->get('_old_input.api_key'));
    }

    public function test_all_new_tables_and_columns_have_database_chinese_comments(): void
    {
        $tables = ['providers', 'provider_secrets', 'provider_versions', 'model_profiles', 'model_profile_versions', 'ai_requests', 'ai_request_evidence', 'provider_commands'];
        foreach ($tables as $table) {
            $comment = DB::selectOne("SELECT obj_description(?::regclass,'pg_class') AS comment", [$table])->comment;
            $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $comment);
            foreach (DB::select('SELECT a.attname,col_description(a.attrelid,a.attnum) AS comment FROM pg_attribute a WHERE a.attrelid=?::regclass AND a.attnum>0 AND NOT a.attisdropped', [$table]) as $column) {
                $this->assertMatchesRegularExpression('/[\x{4e00}-\x{9fff}]/u', $column->comment ?? '', $table.'.'.$column->attname);
            }
        }
    }

    public function test_api_page_and_form_never_echo_or_flash_secret(): void
    {
        $f = ProviderFixture::create();
        $this->getJson('/api/v1/providers')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/providers')->assertForbidden();
        $this->actingAs($f['actor']);
        $this->getJson('/api/v1/providers')->assertOk()->assertDontSee($f['provider_payload']['api_key']);
        $this->get('/admin/providers')->assertOk()->assertSee('供应商与模型')->assertDontSee($f['provider_payload']['api_key']);
        $p = $f['provider_payload'];
        $p['name'] = '';
        $p['command_key'] = 'invalid-form';
        $this->post('/api/v1/providers/provider', $p)->assertRedirect('/admin/providers');
        $this->assertNull(session()->get('_old_input.api_key'));
        $this->problem('FORBIDDEN', fn () => app(ProviderGateway::class)->read(User::factory()->create(), $f['novelId'], '1'));
    }
}
