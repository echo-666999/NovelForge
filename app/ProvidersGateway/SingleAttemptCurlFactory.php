<?php

namespace App\ProvidersGateway;

use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\Handler\EasyHandle;
use Psr\Http\Message\RequestInterface;

/** 阻断Guzzle底层的失败回卷重试；每次适配器调用最多创建一个传输句柄。 */
final class SingleAttemptCurlFactory implements CurlFactoryInterface
{
    private bool $created = false;

    private CurlFactory $factory;

    public function __construct()
    {
        $this->factory = new CurlFactory(0);
    }

    public function create(RequestInterface $request, array $options): EasyHandle
    {
        if ($this->created) {
            throw new \RuntimeException('未知结果禁止底层自动重发。');
        }
        $this->created = true;

        return $this->factory->create($request, $options);
    }

    public function release(EasyHandle $easy): void
    {
        $this->factory->release($easy);
    }
}
