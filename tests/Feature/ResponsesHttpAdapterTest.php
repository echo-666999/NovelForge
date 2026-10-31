<?php

namespace Tests\Feature;

use App\Exceptions\NovelProblem;
use App\ProvidersGateway\BoundedResponseStream;
use App\ProvidersGateway\OpenAiResponsesHttpAdapter;
use App\ProvidersGateway\PublicDnsResolver;
use App\ProvidersGateway\SingleAttemptCurlFactory;
use App\Services\BillingConfigurationService;
use App\Services\GenerationService;
use App\Services\ProviderGateway;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProviderFixture;
use Tests\TestCase;

/** 所有传输均由Guzzle内存处理器完成，不连接供应商或本地HTTP服务。 */
class ResponsesHttpAdapterTest extends TestCase
{
    use RefreshDatabase;

    private function adapter(?MockHandler $mock, array $ips = ['8.8.8.8'], array $override = []): OpenAiResponsesHttpAdapter
    {
        $resolver = new class($ips) extends PublicDnsResolver
        {
            public function __construct(private array $ips) {}

            public function resolve(string $host): array
            {
                return $this->ips;
            }
        };

        return new OpenAiResponsesHttpAdapter($override + ['endpoint' => 'https://api.example.com/v1/responses', 'allowed_hosts' => ['api.example.com'], 'timeout_seconds' => 20, 'connect_timeout_seconds' => 5], $resolver, 'fixture-secret-key-123', $mock);
    }

    private function send(array $f, OpenAiResponsesHttpAdapter $adapter, string $key = 'http'): array
    {
        return app(ProviderGateway::class)->execute($f['actor'], $f['novelId'], $f['claim']['id'], $f['model']['version_id'], $f['input'], $key, $adapter);
    }

    private function raw(): string
    {
        return '{"id":"resp_offline","status":"completed","output":[{"type":"message","role":"assistant","status":"completed","content":[{"type":"output_text","text":"{\"text\":\"离线结果\"}"}]}],"usage":{"input_tokens":10,"output_tokens":5,"input_tokens_details":{"cached_tokens":0,"cache_write_tokens":0},"output_tokens_details":{"reasoning_tokens":0}}}';
    }

    private function problem(string $code, callable $action): void
    {
        try {
            $action();
            $this->fail('应拒绝不安全传输');
        } catch (NovelProblem $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    public function test_pinned_request_keeps_evidence_budget_and_idempotency(): void
    {
        $f = ProviderFixture::create();
        $mock = new MockHandler([new Response(200, [], $this->raw())]);
        $adapter = $this->adapter($mock);
        $r = $this->send($f, $adapter);
        $this->assertSame('succeeded', $r['status']);
        $this->assertSame('settled', $r['billing']['status']);
        $this->assertSame($r, $this->send($f, $adapter));
        $this->assertSame(0, $mock->count());
        $this->assertDatabaseCount('budget_reservations', 1);
        $this->assertSame($this->raw(), app(ProviderGateway::class)->evidenceText($f['actor'], $f['novelId'], $r['id'], 'response'));
        $this->assertSame(['text' => '离线结果'], app(ProviderGateway::class)->businessOutput($f['actor'], $f['novelId'], $r['id'], $f['input']));
        $options = $mock->getLastOptions();
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame('', $options['proxy']);
        $this->assertTrue($options['verify']);
        $this->assertSame(['api.example.com:443:8.8.8.8'], $options['curl'][CURLOPT_RESOLVE]);
        $this->assertSame(20, $options['timeout']);
        $this->assertSame(5, $options['connect_timeout']);
        $this->assertSame('POST', $mock->getLastRequest()->getMethod());
        $this->assertSame('Bearer fixture-secret-key-123', $mock->getLastRequest()->getHeaderLine('Authorization'));
        $wire = json_decode((string) $mock->getLastRequest()->getBody(), true);
        $this->assertFalse($wire['stream']);
        $this->assertSame(['https'], $options['protocols']);
        // 用真实CurlFactory校验完整生产选项，但不执行句柄；内存Mock不会自行验证这些选项。
        $options['sink'] = new BoundedResponseStream;
        $factory = new SingleAttemptCurlFactory;
        $handle = $factory->create($mock->getLastRequest(), $options);
        $this->assertInstanceOf(\CurlHandle::class, $handle->handle);
        $factory->release($handle);
        $options['sink']->close();
    }

    public function test_http_errors_redirect_and_invalid_json_are_retained_without_retries(): void
    {
        $f = ProviderFixture::create();
        foreach ([[302, 'REDIRECT_BLOCKED'], [401, 'PROVIDER_HTTP_401'], [429, 'PROVIDER_HTTP_429'], [503, 'PROVIDER_HTTP_503'], [200, 'INVALID_JSON']] as [$status, $error]) {
            $raw = '原始错误 fixture-secret-key-123';
            $mock = new MockHandler([new Response($status, ['Retry-After' => '7', 'Location' => 'http://127.0.0.1'], $raw)]);
            $adapter = $this->adapter($mock);
            $r = $this->send($f, $adapter, 'error-'.$status);
            $this->assertSame($error, $r['error_code']);
            $this->assertSame('failed', $r['status']);
            $this->assertSame($r, $this->send($f, $adapter, 'error-'.$status));
            $this->assertSame(7, DB::table('ai_requests')->where('id', $r['id'])->value('retry_after_seconds'));
            $saved = app(ProviderGateway::class)->evidenceText($f['actor'], $f['novelId'], $r['id'], 'response');
            $this->assertSame('原始错误 [REDACTED]', $saved);
            $this->assertGreaterThan(0, (float) $r['billing']['risk_reserved']);
            $this->assertSame(0, $mock->count());
        }
    }

    public function test_partial_timeout_retains_prefix_and_reservation_without_resending(): void
    {
        $f = ProviderFixture::create();
        $mock = new MockHandler([function ($request, $options) {
            // 模拟收到部分正文后断连；异常文本中的密钥不能进入证据或错误消息。
            $options['on_headers'](new Response(200));
            $options['sink']->write('{"partial":');
            throw new \RuntimeException('timeout fixture-secret-key-123');
        }]);
        $adapter = $this->adapter($mock);
        $r = $this->send($f, $adapter);
        $this->assertSame('unknown', $r['status']);
        $this->assertSame('PROVIDER_OUTCOME_UNKNOWN', $r['error_code']);
        $this->assertNull($r['usage']);
        $this->assertGreaterThan(0, (float) $r['billing']['risk_reserved']);
        $this->assertSame('{"partial":', app(ProviderGateway::class)->evidenceText($f['actor'], $f['novelId'], $r['id'], 'response'));
        $this->assertSame($r, $this->send($f, $adapter));
        $this->assertSame(0, $mock->count());
    }

    public function test_timeout_before_headers_keeps_unknown_cost_and_no_fabricated_http_status(): void
    {
        $f = ProviderFixture::create();
        $mock = new MockHandler([new \RuntimeException('connect timeout fixture-secret-key-123')]);
        $adapter = $this->adapter($mock);
        $r = $this->send($f, $adapter);
        $this->assertSame('unknown', $r['status']);
        $this->assertNull(DB::table('ai_requests')->where('id', $r['id'])->value('http_status'));
        $this->assertNull($r['usage']);
        $this->assertGreaterThan(0, (float) $r['billing']['risk_reserved']);
        $this->assertSame('', app(ProviderGateway::class)->evidenceText($f['actor'], $f['novelId'], $r['id'], 'response'));
        $this->assertSame($r, $this->send($f, $adapter));
        $this->assertSame(0, $mock->count());
    }

    public function test_oversize_response_is_bounded_and_late_response_is_quarantined(): void
    {
        $f = ProviderFixture::create();
        $mock = new MockHandler([new Response(200, [], str_repeat('x', BoundedResponseStream::LIMIT + 100))]);
        $r = $this->send($f, $this->adapter($mock));
        $this->assertSame('RESPONSE_TOO_LARGE', $r['error_code']);
        $saved = app(ProviderGateway::class)->evidenceText($f['actor'], $f['novelId'], $r['id'], 'response');
        $this->assertSame(BoundedResponseStream::LIMIT + 1, strlen($saved));
        $this->assertGreaterThan(0, (float) $r['billing']['risk_reserved']);
        $late = new MockHandler([function () use ($f) {
            DB::table('generation_steps')->where('id', $f['claim']['id'])->update(['lease_until' => DB::raw("clock_timestamp() - interval '1 second'")]);

            return new Response(200, [], $this->raw());
        }]);
        $r = $this->send($f, $this->adapter($late), 'late');
        $this->assertSame('quarantined', $r['status']);
        $this->assertSame('STALE_REQUEST_RESULT', $r['error_code']);
        $this->assertSame('settled', $r['billing']['status']);
        $this->assertSame($this->raw(), app(ProviderGateway::class)->evidenceText($f['actor'], $f['novelId'], $r['id'], 'response'));
    }

    public function test_zero_budget_rolls_back_request_before_http_transport(): void
    {
        $f = ProviderFixture::create();
        $policy = app(BillingConfigurationService::class)->execute($f['actor'], 'policy', [
            'key' => 'zero-http', 'expected_version' => 0, 'currency' => 'USD',
            'limits' => array_fill_keys(['request', 'chapter', 'run', 'book', 'day'], '0'),
            'warning_percent' => 80, 'stop_percent' => 100, 'timezone' => 'UTC', 'reason' => '离线零预算门禁',
        ], 'zero-http');
        $f['payload']['billing']['policy_id'] = $policy['id'];
        $f['runId'] = app(GenerationService::class)->start($f['actor'], $f['novelId'], $f['payload'], 'zero-http')['run']['id'];
        $f['claim'] = app(GenerationService::class)->claim($f['actor'], $f['novelId'], $f['runId'], 'first');
        $mock = new MockHandler([new Response(200, [], $this->raw())]);
        $this->problem('DEFERRED_BUDGET', fn () => $this->send($f, $this->adapter($mock)));
        $this->assertSame(1, $mock->count());
        $this->assertDatabaseCount('ai_requests', 0);
        $this->assertDatabaseCount('budget_reservations', 0);
    }

    public function test_endpoint_dns_timeout_and_live_transport_guards_fail_before_send(): void
    {
        foreach ([
            [['127.0.0.1'], [], 'SSRF_BLOCKED'],
            [['8.8.8.8', '10.0.0.1'], [], 'SSRF_BLOCKED'],
            [[], [], 'DNS_UNVERIFIED'],
            [['8.8.8.8'], ['endpoint' => 'https://evil.example.com/v1/responses'], 'UNSAFE_ENDPOINT'],
            [['8.8.8.8'], ['endpoint' => 'https://api.example.com/v1/chat/completions'], 'UNSAFE_ENDPOINT'],
            [['8.8.8.8'], ['timeout_seconds' => 0], 'INVALID_TRANSPORT_CONFIGURATION'],
        ] as [$ips, $config, $code]) {
            $mock = new MockHandler([new Response(200)]);
            $this->problem($code, fn () => $this->adapter($mock, $ips, $config)->send([]));
            $this->assertSame(1, $mock->count());
        }
        $this->problem('BUDGET_GATE_REQUIRED', fn () => $this->adapter(null)->send([]));
        $this->app->instance('env', 'local');
        try {
            $mock = new MockHandler([new Response(200)]);
            $this->problem('BUDGET_GATE_REQUIRED', fn () => $this->adapter($mock)->send([]));
            $this->assertSame(1, $mock->count());
        } finally {
            $this->app->instance('env', 'testing');
        }
    }
}
