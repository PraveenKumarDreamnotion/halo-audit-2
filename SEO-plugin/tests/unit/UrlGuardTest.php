<?php
namespace HaloAudit\Tests;

use HaloAudit\UrlGuard;
use PHPUnit\Framework\TestCase;

final class UrlGuardTest extends TestCase
{
    private function guard(array $map = []): UrlGuard
    {
        return new UrlGuard(static fn(string $host): array => $map[$host] ?? []);
    }

    /** @dataProvider rejected */
    public function test_unsafe_urls_are_rejected(string $url, array $dns = []): void
    {
        $this->assertNotNull($this->guard($dns)->reject($url), $url);
    }

    public function rejected(): array
    {
        return [
            'loopback ip' => ['http://127.0.0.1/'],
            'private 10' => ['http://10.1.2.3/'],
            'private 192' => ['http://192.168.1.1/'],
            'private 172' => ['http://172.16.0.1/'],
            'metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'cgnat' => ['http://100.64.0.1/'],
            'ipv6 loopback' => ['http://[::1]/'],
            'localhost' => ['http://localhost/'],
            'dot local' => ['http://printer.local/'],
            'dot test' => ['http://site.test/'],
            'ftp scheme' => ['ftp://example.com/'],
            'file scheme' => ['file:///etc/passwd'],
            'odd port' => ['http://example.com:8080/', ['example.com' => ['93.184.216.34']]],
            'credentials' => ['http://user:pass@example.com/', ['example.com' => ['93.184.216.34']]],
            'unresolvable' => ['http://nope.invalid/'],
            'public name, private ip' => ['http://rebind.example/', ['rebind.example' => ['10.0.0.5']]],
            'one of two ips private' => ['http://mixed.example/', ['mixed.example' => ['93.184.216.34', '127.0.0.1']]],
            'no host' => ['http:///path'],
            // IPv4 special-purpose ranges.
            'this network' => ['http://0.0.0.0/'],
            'benchmarking' => ['http://198.18.0.1/'],
            'documentation' => ['http://192.0.2.10/'],
            'ietf protocol' => ['http://192.0.0.8/'],
            'multicast' => ['http://224.0.0.1/'],
            'reserved 240' => ['http://240.0.0.1/'],
            'broadcast' => ['http://255.255.255.255/'],
            // IPv6 special-purpose ranges.
            'ipv6 unspecified' => ['http://[::]/'],
            'ipv6 unique local' => ['http://[fd12:3456::1]/'],
            'ipv6 link local' => ['http://[fe80::1]/'],
            'ipv6 site local' => ['http://[fec0::1]/'],
            'ipv6 multicast' => ['http://[ff02::1]/'],
            'ipv6 documentation' => ['http://[2001:db8::1]/'],
            'teredo' => ['http://[2001:0:4136:e378:8000:63bf:3fff:fdd2]/'],
            'ipv4 compatible' => ['http://[::127.0.0.1]/'],
            'discard only' => ['http://[100::1]/'],
            // IPv6 forms that carry a private IPv4 address.
            'mapped loopback' => ['http://[::ffff:127.0.0.1]/'],
            'mapped loopback hex' => ['http://[::ffff:7f00:1]/'],
            'mapped metadata' => ['http://[::ffff:169.254.169.254]/'],
            'nat64 private' => ['http://[64:ff9b::a00:1]/'],
            'nat64 local-use prefix' => ['http://[64:ff9b:1::5db8:d822]/'],
            '6to4 private' => ['http://[2002:c0a8:0101::1]/'],
            '6to4 loopback' => ['http://[2002:7f00:0001::]/'],
            // Names whose DNS answer is unsafe, including a numeric name that the system resolver expands.
            'dns aaaa loopback' => ['http://v6.example/', ['v6.example' => ['::1']]],
            'dns mapped private' => ['http://mapped.example/', ['mapped.example' => ['::ffff:10.0.0.1']]],
            'decimal ip name' => ['http://2130706433/', ['2130706433' => ['127.0.0.1']]],
            'junk dns answer' => ['http://junk.example/', ['junk.example' => ['not-an-ip']]],
            'arpa name' => ['http://1.0.0.127.in-addr.arpa/'],
        ];
    }

    public function test_public_urls_pass(): void
    {
        $guard = $this->guard(['example.com' => ['93.184.216.34'], 'v6.example' => ['2606:4700:4700::1111', '93.184.216.34']]);
        $this->assertNull($guard->reject('https://example.com/a?b=1'));
        $this->assertNull($guard->reject('http://example.com:80/'));
        $this->assertNull($guard->reject('https://93.184.216.34/'));
        $this->assertNull($guard->reject('https://v6.example/'));
        $this->assertNull($guard->reject('https://[2606:4700:4700::1111]/'));
        // Transition forms that carry a public IPv4 address are allowed.
        $this->assertNull($guard->reject('http://[::ffff:93.184.216.34]/'));
        $this->assertNull($guard->reject('http://[64:ff9b::5db8:d822]/'));
        $this->assertNull($guard->reject('http://[2002:5db8:d822::1]/'));
    }

    public function test_inspect_returns_the_checked_addresses(): void
    {
        $guard = $this->guard(['example.com' => ['93.184.216.34', '2606:4700:4700::1111']]);
        $this->assertSame(['reason' => null, 'ips' => ['93.184.216.34', '2606:4700:4700::1111']], $guard->inspect('https://example.com/'));
        $this->assertSame(['93.184.216.34'], $guard->inspect('https://93.184.216.34/')['ips']);
        $blocked = $guard->inspect('http://10.0.0.1/');
        $this->assertNotNull($blocked['reason']);
        $this->assertSame([], $blocked['ips']);
    }

    /** @dataProvider ranges */
    public function test_range_edges(string $ip, bool $public): void
    {
        $this->assertSame($public, UrlGuard::isPublic($ip), $ip);
    }

    public function ranges(): array
    {
        return [
            ['100.63.255.255', true], ['100.64.0.0', false], ['100.127.255.255', false], ['100.128.0.0', true],
            ['172.15.255.255', true], ['172.16.0.0', false], ['172.31.255.255', false], ['172.32.0.0', true],
            ['198.17.255.255', true], ['198.18.0.0', false], ['198.19.255.255', false], ['198.20.0.0', true],
            ['223.255.255.255', true], ['224.0.0.0', false],
            ['8.8.8.8', true], ['1.1.1.1', true],
            ['fbff:ffff::1', true], ['fc00::', false], ['fdff:ffff::1', false],
            ['febf:ffff::1', false], ['fe80::', false], ['2001:200::1', true], ['2001:1ff::1', false],
            ['not-an-ip', false], ['', false],
        ];
    }
}
