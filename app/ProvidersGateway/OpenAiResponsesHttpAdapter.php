<?php

namespace App\ProvidersGateway;

use App\Exceptions\NovelProblem;
use App\Services\RealExecutionAdmission;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\MockHandler;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/** 受限Responses传输；真实网络必须绑定网关预留，测试仅用内存处理器。 */
final class OpenAiResponsesHttpAdapter implements ResponsesAdapter
{
    public function __construct(
        private array $config,
        private PublicDnsResolver $resolver,
        #[\SensitiveParameter] private string $key,
        private ?MockHandler $transport = null,
    ) {}

    private ?string $authorizedRequest = null;

    /** 网关持久预留完成后绑定请求，发送时仍重新核对，不允许调用者提供任意端点。 */
    public function forRequest(string $id): self
    {
        $copy = clone $this;
        $copy->authorizedRequest = $id;

        return $copy;
    }

    public function send(array $request): ProviderReply
    {
        // 双重限制：即使在testing环境，也不得因忘记模拟传输而外发。
        if ($this->transport !== null) {
            if (! app()->environment('testing')) {
                throw new NovelProblem('BUDGET_GATE_REQUIRED', '模拟传输只允许测试环境。', 403);
            }
        } else {
            // 测试环境永远禁止真实网络，即使错误地打开配置开关。
            if (app()->environment('testing') || $this->authorizedRequest === null) {
                throw new NovelProblem('BUDGET_GATE_REQUIRED', '真实传输缺少已预留请求资格。', 403);
            }
            app(RealExecutionAdmission::class)->request($this->authorizedRequest, $request, $this->config);
            // 资格只消费一次；未知结果必须回到网关处理，不能重发同一适配器。
            $this->authorizedRequest = null;
        }
        $timeout = $this->config['timeout_seconds'] ?? null;
        $connect = $this->config['connect_timeout_seconds'] ?? null;
        if (! is_int($timeout) || ! is_int($connect) || $connect < 1 || $timeout < $connect || $timeout > 600
            || $this->key === '' || preg_match('/[\x00-\x20\x7f]/', $this->key)) {
            throw new NovelProblem('INVALID_TRANSPORT_CONFIGURATION', '超时或认证配置无效。', 422);
        }
        $policy = new EndpointPolicy;
        $endpoint = $this->config['endpoint'];
        $hosts = $this->config['allowed_hosts'];
        $host = $policy->host($endpoint, $hosts);
        // 地址必须是完整Responses端点，不自动拼接路径或接受查询参数。
        if (parse_url($endpoint, PHP_URL_PATH) !== '/v1/responses') {
            throw new NovelProblem('UNSAFE_ENDPOINT', '需要完整的Responses端点。', 422);
        }
        $options = $policy->pinnedOptions($endpoint, $hosts, $this->resolver->resolve($host));
        // 禁止复用旧连接，确保本次请求使用刚校验并固定的公网地址。
        $options['curl'] += [CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true];
        $sink = new BoundedResponseStream;
        $status = 0;
        $delay = null;
        $options += [
            // 当前Guzzle通过受管选项限制协议，禁止使用冲突的CURLOPT_PROTOCOLS。
            'protocols' => ['https'], 'timeout' => $timeout, 'connect_timeout' => $connect,
            'http_errors' => false, 'decode_content' => false,
            // 使用单次HTTP/1.1交换，避免Expect协商或HTTP/2流回放。
            'version' => '1.1', 'expect' => false,
            'headers' => ['Authorization' => 'Bearer '.$this->key, 'Content-Type' => 'application/json', 'Accept' => 'application/json', 'Accept-Encoding' => 'identity'],
            'body' => json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'sink' => $sink,
            'on_headers' => function (ResponseInterface $response) use (&$status, &$delay): void {
                $status = $response->getStatusCode();
                $header = $response->getHeaderLine('Retry-After');
                // 只解析协议允许的整数秒或HTTP日期，拒绝相对时间文本。
                if (strlen($header) <= 100 && ctype_digit($header)) {
                    $delay = (int) min(2147483647, (float) $header);
                } elseif (preg_match('/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT$/D', $header) && ($date = strtotime($header)) !== false) {
                    $delay = min(2147483647, max(0, $date - time()));
                }
            },
        ];
        try {
            // 不使用重试或重定向中间件；同一次业务请求只执行一次传输。
            (new Client(['handler' => $this->transport ?? new CurlHandler(['handle_factory' => new SingleAttemptCurlFactory])]))->request('POST', $endpoint, $options);

            return new ProviderReply($status, (string) $sink, $delay);
        } catch (Throwable) {
            // 超时/断连可能已收费；保留收到的前缀，禁止记录可能含密钥的异常文本。
            return new ProviderReply($status, (string) $sink, $delay, $sink->overflow ? 'RESPONSE_TOO_LARGE' : 'PROVIDER_OUTCOME_UNKNOWN');
        } finally {
            $sink->close();
        }
    }
}
