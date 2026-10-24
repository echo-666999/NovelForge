<?php

namespace App\ProvidersGateway;

use App\Exceptions\NovelProblem;

/** 禁止用户信息、跳转、非常规端口、IP字面量及内部DNS解析结果。 */
final class EndpointPolicy
{
    public function host(string $url, array $allowlist): string
    {
        $u = parse_url($url);
        $host = strtolower($u['host'] ?? '');
        if (! $u || ($u['scheme'] ?? '') !== 'https' || ($u['port'] ?? 443) !== 443 || isset($u['user'], $u['pass']) || isset($u['user']) || isset($u['query']) || isset($u['fragment'])
            || preg_match('/[\x00-\x20\\\\%]/', $url) || ! preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/D', $host)
            || filter_var($host, FILTER_VALIDATE_IP) || ! preg_match('/[a-z]$/', $host) || ! in_array($host, $allowlist, true)
            || preg_match('/(?:^|\.)(localhost|local|internal|test|invalid)$/D', $host)) {
            throw new NovelProblem('UNSAFE_ENDPOINT', '供应商地址不符合 HTTPS 主机白名单要求。', 422);
        }

        return $host;
    }

    public function pinnedOptions(string $url, array $allowlist, array $addresses): array
    {
        $host = $this->host($url, $allowlist);
        if ($addresses === []) {
            throw new NovelProblem('DNS_UNVERIFIED', '无法确认供应商的公网地址。');
        }
        foreach ($addresses as $ip) {
            $binary = is_string($ip) ? @inet_pton($ip) : false;
            $nat64 = $binary !== false && strlen($binary) === 16 && str_starts_with(bin2hex($binary), '0064ff9b');
            // PHP公网过滤仍可能接受多播和旧站点地址，只允许公网单播候选。
            $unicast = $binary !== false && (strlen($binary) === 4 ? ord($binary[0]) < 224 : (ord($binary[0]) & 0xE0) === 0x20);
            if (! $unicast || $nat64 || ! is_string($ip) || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) || str_starts_with(strtolower($ip), '::ffff:')) {
                throw new NovelProblem('SSRF_BLOCKED', '拒绝内网、保留或混合地址解析结果。', 422);
            }
        }
        $ip = $addresses[0];

        return ['allow_redirects' => false, 'proxy' => '', 'verify' => true, 'curl' => [CURLOPT_RESOLVE => [$host.':443:'.(str_contains($ip, ':') ? '['.$ip.']' : $ip)]]];
    }
}
