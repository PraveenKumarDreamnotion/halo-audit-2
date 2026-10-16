<?php
namespace HaloAudit\Tests;

use HaloAudit\Fetcher;
use HaloAudit\Response;
use HaloAudit\Tests\Support\FakeHttp;
use HaloAudit\UrlGuard;
use PHPUnit\Framework\TestCase;

final class FetcherTest extends TestCase
{
    private FakeHttp $http;
    private Fetcher $fetcher;

    protected function setUp(): void
    {
        $this->http = new FakeHttp();
        $guard = new UrlGuard(static fn(string $host): array => $host === 'evil.example' ? ['10.0.0.1'] : ['93.184.216.34']);
        $this->fetcher = new Fetcher($this->http, $guard);
    }

    public function test_returns_body_and_final_url(): void
    {
        $this->http->routes['https://example.com/'] = Response::make(200, 'hi', ['Content-Type' => 'text/html']);
        $r = $this->fetcher->get('https://example.com/');
        $this->assertTrue($r->ok());
        $this->assertSame('hi', $r->body);
        $this->assertSame('https://example.com/', $r->finalUrl);
        $this->assertSame('text/html', $r->header('content-type'));
        $this->assertSame([], $r->chain);
    }

    public function test_each_url_is_fetched_once(): void
    {
        $this->http->routes['https://example.com/'] = Response::make(200, 'hi');
        $this->fetcher->get('https://example.com/');
        $this->fetcher->get('https://example.com/');
        $this->assertCount(1, $this->http->calls);
    }

    public function test_redirects_are_followed_and_recorded(): void
    {
        $this->http->routes['http://example.com/'] = Response::make(301, '', ['Location' => 'https://example.com/']);
        $this->http->routes['https://example.com/'] = Response::make(302, '', ['Location' => '/home']);
        $this->http->routes['https://example.com/home'] = Response::make(200, 'home');
        $r = $this->fetcher->get('http://example.com/');
        $this->assertSame('home', $r->body);
        $this->assertSame('https://example.com/home', $r->finalUrl);
        $this->assertSame([301, 302], array_column($r->chain, 'status'));
    }

    public function test_more_than_three_redirects_is_an_error(): void
    {
        foreach ([1, 2, 3, 4] as $i) {
            $this->http->routes["https://example.com/$i"] = Response::make(301, '', ['Location' => '/' . ($i + 1)]);
        }
        $r = $this->fetcher->get('https://example.com/1');
        $this->assertSame('too many redirects', $r->error);
    }

    public function test_redirect_to_private_host_is_blocked(): void
    {
        $this->http->routes['https://example.com/'] = Response::make(302, '', ['Location' => 'http://evil.example/admin']);
        $r = $this->fetcher->get('https://example.com/');
        $this->assertStringStartsWith('blocked', (string) $r->error);
        $this->assertNotContains('http://evil.example/admin', $this->http->calls);
    }

    public function test_blocked_start_url_is_never_requested(): void
    {
        $r = $this->fetcher->get('http://127.0.0.1/');
        $this->assertStringStartsWith('blocked', (string) $r->error);
        $this->assertSame([], $this->http->calls);
    }

    public function test_transport_error_is_passed_through(): void
    {
        $r = $this->fetcher->get('https://example.com/missing-route');
        $this->assertFalse($r->ok());
        $this->assertNotNull($r->error);
    }

    public function test_each_hop_is_pinned_to_the_address_the_guard_checked(): void
    {
        $guard = new UrlGuard(static fn(string $host): array => ['example.com' => ['93.184.216.34'], 'cdn.example' => ['203.0.114.7']][$host] ?? []);
        $fetcher = new Fetcher($this->http, $guard);
        $this->http->routes['https://example.com/'] = Response::make(302, '', ['Location' => 'https://cdn.example/page']);
        $this->http->routes['https://cdn.example/page'] = Response::make(200, 'ok');

        $this->assertSame('ok', $fetcher->get('https://example.com/')->body);
        $this->assertSame(['93.184.216.34'], $this->http->pins['https://example.com/']);
        $this->assertSame(['203.0.114.7'], $this->http->pins['https://cdn.example/page']);
    }

    public function test_redirect_to_a_rebound_name_is_blocked_before_any_request(): void
    {
        // The second name answers with a private address: the guard runs again on the redirect target.
        $guard = new UrlGuard(static fn(string $host): array => ['example.com' => ['93.184.216.34'], 'rebind.example' => ['192.168.0.10']][$host] ?? []);
        $fetcher = new Fetcher($this->http, $guard);
        $this->http->routes['https://example.com/'] = Response::make(301, '', ['Location' => 'https://rebind.example/']);
        $this->assertStringStartsWith('blocked', (string) $fetcher->get('https://example.com/')->error);
        $this->assertSame(['https://example.com/'], $this->http->calls);
    }

    public function test_redirect_to_an_odd_port_or_scheme_is_blocked(): void
    {
        $this->http->routes['https://example.com/a'] = Response::make(302, '', ['Location' => 'https://example.com:8443/']);
        $this->http->routes['https://example.com/b'] = Response::make(302, '', ['Location' => 'gopher://example.com/']);
        $this->assertStringStartsWith('blocked', (string) $this->fetcher->get('https://example.com/a')->error);
        $this->assertStringStartsWith('blocked', (string) $this->fetcher->get('https://example.com/b')->error);
    }

    public function test_deadline_stops_further_fetches(): void
    {
        $this->http->routes['https://example.com/slow'] = Response::make(200, 'x');
        $this->fetcher->setDeadline(microtime(true) - 1);
        $r = $this->fetcher->get('https://example.com/slow');
        $this->assertSame('time budget used up', $r->error);
        $this->assertSame([], $this->http->calls);
    }
}
