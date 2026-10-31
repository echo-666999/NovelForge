<?php

namespace Tests\Unit;

use App\ProvidersGateway\BoundedResponseStream;
use App\ProvidersGateway\SingleAttemptCurlFactory;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

/** 只创建并释放句柄，不调用curl_exec，不解析DNS或建立连接。 */
class SingleAttemptCurlFactoryTest extends TestCase
{
    public function test_second_handle_is_refused_even_after_first_is_released(): void
    {
        $factory = new SingleAttemptCurlFactory;
        $request = new Request('POST', 'https://api.example.com/v1/responses', [], '{}');
        $handle = $factory->create($request, []);
        $this->assertInstanceOf(\CurlHandle::class, $handle->handle);
        $factory->release($handle);
        $this->expectException(\RuntimeException::class);
        $factory->create($request, []);
    }

    public function test_chunked_response_limit_preserves_exact_prefix(): void
    {
        $sink = new BoundedResponseStream;
        $sink->write(str_repeat('a', BoundedResponseStream::LIMIT - 1));
        try {
            $sink->write('bcdef');
            $this->fail('超过上限必须终止接收');
        } catch (\OverflowException) {
            $this->assertTrue($sink->overflow);
            $this->assertSame(str_repeat('a', BoundedResponseStream::LIMIT - 1).'bc', (string) $sink);
        } finally {
            $sink->close();
        }
    }
}
