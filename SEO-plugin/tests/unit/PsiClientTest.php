<?php
namespace HaloAudit\Tests;

use HaloAudit\PsiClient;
use HaloAudit\Response;
use HaloAudit\Tests\Support\FakeHttp;
use PHPUnit\Framework\TestCase;

final class PsiClientTest extends TestCase
{
    private const GOOD = '{"lighthouseResult":{"categories":{"performance":{"score":0.87}},"audits":{"largest-contentful-paint":{"numericValue":2310.5},"cumulative-layout-shift":{"numericValue":0.04}}}}';

    public function test_parse_reads_score_lcp_and_cls(): void
    {
        $this->assertSame(['score' => 87, 'lcp_ms' => 2310.5, 'cls' => 0.04], PsiClient::parse(self::GOOD));
    }

    /** @dataProvider bad */
    public function test_parse_returns_null_for_unusable_payloads(string $json): void
    {
        $this->assertNull(PsiClient::parse($json));
    }

    public function bad(): array
    {
        return [
            'not json' => ['<html>'],
            'error payload' => ['{"error":{"code":429}}'],
            'null score' => ['{"lighthouseResult":{"categories":{"performance":{"score":null}},"audits":{}}}'],
        ];
    }

    public function test_endpoint_encodes_url_and_adds_key(): void
    {
        $endpoint = PsiClient::endpoint('https://example.com/a b?x=1', 'KEY');
        $this->assertStringStartsWith('https://www.googleapis.com/pagespeedonline/v5/runPagespeed?', $endpoint);
        $this->assertStringContainsString('url=https%3A%2F%2Fexample.com%2Fa%20b%3Fx%3D1', $endpoint);
        $this->assertStringContainsString('strategy=mobile', $endpoint);
        $this->assertStringContainsString('category=performance', $endpoint);
        $this->assertStringContainsString('key=KEY', $endpoint);
    }

    public function test_endpoint_without_key_has_no_key_param(): void
    {
        $this->assertStringNotContainsString('key=', PsiClient::endpoint('https://example.com/', ''));
    }

    public function test_run_returns_parsed_result(): void
    {
        $http = new FakeHttp();
        $http->routes[PsiClient::endpoint('https://example.com/', 'KEY')] = Response::make(200, self::GOOD);
        $this->assertSame(87, (new PsiClient($http, 'KEY'))->run('https://example.com/')['score']);
    }

    public function test_run_returns_null_on_http_failure(): void
    {
        $http = new FakeHttp();
        $http->routes[PsiClient::endpoint('https://example.com/', 'KEY')] = Response::make(429, '{"error":{}}');
        $this->assertNull((new PsiClient($http, 'KEY'))->run('https://example.com/'));
        $this->assertNull((new PsiClient(new FakeHttp(), 'KEY'))->run('https://example.com/'));
    }
}
