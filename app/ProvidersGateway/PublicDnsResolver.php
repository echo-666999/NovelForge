<?php

namespace App\ProvidersGateway;

/** 只返回解析结果；所有地址仍必须经过公网策略，解析失败默认拒绝。 */
class PublicDnsResolver
{
    public function resolve(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);

        return array_values(array_unique(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records ?: []))));
    }
}
