<?php

namespace App\ProvidersGateway;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/** 接收时限制响应大小；保留上限加一字节的原始前缀供故障审计。 */
final class BoundedResponseStream implements StreamInterface
{
    use StreamDecoratorTrait;

    public const LIMIT = 1048576;

    public bool $overflow = false;

    // 显式声明底层流，兼容禁止动态属性的PHP版本。
    private StreamInterface $stream;

    public function __construct()
    {
        $this->stream = Utils::streamFor(fopen('php://temp/maxmemory:262144', 'w+'));
    }

    public function write(string $string): int
    {
        $remaining = self::LIMIT + 1 - $this->stream->getSize();
        $written = $this->stream->write(substr($string, 0, max(0, $remaining)));
        if ($this->stream->getSize() > self::LIMIT || $written < strlen($string)) {
            $this->overflow = true;
            throw new \OverflowException('响应超过允许大小。');
        }

        return $written;
    }
}
