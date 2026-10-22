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

    private static function lighthouse(string $score, string $lcp, string $cls): string
    {
        return '{"lighthouseResult":{"categories":{"performance":{"score":' . $score . '}},"audits":{"largest-contentful-paint":{"numericValue":'
            . $lcp . '},"cumulative-layout-shift":{"numericValue":' . $cls . '}}}}';
    }

    /** The score is converted from 0 to 1 once; LCP stays in milliseconds and CLS has no unit. */
    public function test_parse_converts_the_score_once_and_keeps_metric_units(): void
    {
        $this->assertSame(['score' => 82, 'lcp_ms' => 4400.0, 'cls' => 0.01], PsiClient::parse(self::lighthouse('0.82', '4400', '0.01')));
        $this->assertSame(['score' => 37, 'lcp_ms' => 15600.0, 'cls' => 0.31], PsiClient::parse(self::lighthouse('0.37', '15600', '0.31')));
    }

    public function test_parse_keeps_a_zero_score_as_a_measurement(): void
    {
        $this->assertSame(['score' => 0, 'lcp_ms' => 0.0, 'cls' => 0.0], PsiClient::parse(self::lighthouse('0', '0', '0')));
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
            'empty body' => [''],
            'json that is not an object' => ['"lighthouseResult"'],
            'error payload' => ['{"error":{"code":429}}'],
            'null score' => ['{"lighthouseResult":{"categories":{"performance":{"score":null}},"audits":{}}}'],
            'runtime error' => ['{"lighthouseResult":{"runtimeError":{"code":"NO_FCP"},"categories":{"performance":{"score":null}},"audits":{"largest-contentful-paint":{},"cumulative-layout-shift":{}}}}'],
            'missing LCP audit' => ['{"lighthouseResult":{"categories":{"performance":{"score":0.82}},"audits":{"cumulative-layout-shift":{"numericValue":0.01}}}}'],
            'missing CLS value' => ['{"lighthouseResult":{"categories":{"performance":{"score":0.82}},"audits":{"largest-contentful-paint":{"numericValue":4400},"cumulative-layout-shift":{"displayValue":"0.01"}}}}'],
            'score already out of 100' => [self::lighthouse('82', '4400', '0.01')],
            'score as text out of range' => [self::lighthouse('"82"', '4400', '0.01')],
            'negative score' => [self::lighthouse('-0.1', '4400', '0.01')],
            'negative LCP' => [self::lighthouse('0.82', '-5', '0.01')],
            'negative CLS' => [self::lighthouse('0.82', '4400', '-0.3')],
            'infinite LCP' => [self::lighthouse('0.82', '1e400', '0.01')],
            'score not a number' => [self::lighthouse('true', '4400', '0.01')],
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
