<?php
namespace HaloAudit\Tests\Checks;

use HaloAudit\Checks\Technical;
use HaloAudit\Response;
use HaloAudit\Tests\Support\Make;
use PHPUnit\Framework\TestCase;

final class TechnicalTest extends TestCase
{
    private function status(string $class, array $site = [], string $body = '', ?string $url = null): string
    {
        $page = Make::page('', $body, $url ?? Make::URL);
        return (new $class())->run(Make::ctx($page, Make::site($site)))->status;
    }

    private function pageResponse(array $headers = [], array $chain = [], string $finalUrl = Make::URL): Response
    {
        $r = Response::make(200, '', $headers);
        $r->chain = $chain;
        $r->finalUrl = $finalUrl;
        return $r;
    }

    public function test_https(): void
    {
        $this->assertSame('pass', $this->status(Technical\Https::class));
        $this->assertSame('fail', $this->status(Technical\Https::class, ['page' => $this->pageResponse([], [], 'http://example.com/')], '', 'http://example.com/'));
    }

    /** @dataProvider certs */
    public function test_certificate(?int $days, string $expected): void
    {
        $this->assertSame($expected, $this->status(Technical\Certificate::class, ['certDaysLeft' => $days]));
    }

    public function certs(): array
    {
        return [[90, 'pass'], [30, 'pass'], [29, 'warn'], [14, 'warn'], [13, 'fail'], [-2, 'fail'], [null, 'not_measured']];
    }

    public function test_certificate_is_not_measured_on_http(): void
    {
        $site = ['certDaysLeft' => null, 'page' => $this->pageResponse([], [], 'http://example.com/')];
        $this->assertSame('not_measured', $this->status(Technical\Certificate::class, $site, '', 'http://example.com/'));
    }

    /** @dataProvider chains */
    public function test_redirect_chain(array $statuses, string $expected): void
    {
        $chain = array_map(static fn(int $s): array => ['status' => $s, 'url' => 'https://example.com/x'], $statuses);
        $this->assertSame($expected, $this->status(Technical\RedirectChain::class, ['page' => $this->pageResponse([], $chain)]));
    }

    public function chains(): array
    {
        return [
            'none' => [[], 'pass'],
            'one permanent' => [[301], 'pass'],
            'one temporary' => [[302], 'warn'],
            'one 307' => [[307], 'warn'],
            'two' => [[301, 301], 'warn'],
            'three' => [[301, 301, 308], 'fail'],
        ];
    }

    /** @dataProvider robots */
    public function test_robots_reachable(?int $status, string $expected): void
    {
        $this->assertSame($expected, $this->status(Technical\RobotsReachable::class, ['robotsStatus' => $status]));
    }

    public function robots(): array
    {
        return [
            [200, 'pass'], [404, 'warn'], [410, 'warn'], [400, 'warn'],
            // A refusal or rate limit says nothing about whether the file exists.
            [401, 'not_measured'], [403, 'not_measured'], [429, 'not_measured'], [302, 'not_measured'],
            [500, 'not_measured'], [503, 'not_measured'], [null, 'not_measured'],
        ];
    }

    public function test_sitemap(): void
    {
        $this->assertSame('pass', $this->status(Technical\Sitemap::class, ['sitemapStatus' => 200, 'sitemapValid' => true]));
        $this->assertSame('warn', $this->status(Technical\Sitemap::class, ['sitemapStatus' => 404, 'sitemapValid' => false]));
        $this->assertSame('not_measured', $this->status(Technical\Sitemap::class, ['sitemapStatus' => null]));
    }

    /** @dataProvider headers */
    public function test_security_headers(int $count, string $expected): void
    {
        $all = [
            'Strict-Transport-Security' => 'max-age=1', 'Content-Security-Policy' => "default-src 'self'",
            'X-Frame-Options' => 'DENY', 'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin', 'Permissions-Policy' => 'camera=()',
        ];
        $site = ['page' => $this->pageResponse(array_slice($all, 0, $count, true))];
        $this->assertSame($expected, $this->status(Technical\SecurityHeaders::class, $site));
    }

    public function headers(): array
    {
        return [[6, 'pass'], [5, 'pass'], [4, 'warn'], [2, 'warn'], [1, 'fail'], [0, 'fail']];
    }

    /** @dataProvider leaks */
    public function test_staging_leaks(string $body, string $expected): void
    {
        $this->assertSame($expected, $this->status(Technical\StagingLeaks::class, [], $body));
    }

    public function leaks(): array
    {
        return [
            'clean' => ['<a href="https://example.com/a">a</a><p>We love local food and kinsta.</p>', 'pass'],
            'kinsta' => ['<img src="https://stg-site.kinsta.cloud/a.png">', 'fail'],
            'localhost' => ['<script src="http://localhost:3000/app.js"></script>', 'fail'],
            'dot local' => ['<link href="//mysite.local/style.css">', 'fail'],
            'wpengine' => ['<a href="https://mysite.wpengine.com/x">x</a>', 'fail'],
        ];
    }

    public function test_js_rendering(): void
    {
        $this->assertSame('pass', $this->status(Technical\JsRendering::class, [], Make::words(200)));
        $this->assertSame('warn', $this->status(Technical\JsRendering::class, [], Make::words(199)));
        $this->assertSame('warn', $this->status(Technical\JsRendering::class, [], Make::words(50)));
        $this->assertSame('fail', $this->status(Technical\JsRendering::class, [], '<div id="root"></div>'));
    }

    public function test_every_check_has_copy(): void
    {
        foreach (glob(dirname(__DIR__, 3) . '/includes/engine/Checks/Technical/*.php') as $file) {
            $class = 'HaloAudit\\Checks\\Technical\\' . basename($file, '.php');
            $check = new $class();
            $this->assertSame('technical', $check->group(), $class);
            $this->assertNotSame('', $check->id() . '', $class);
            $this->assertNotSame('', $check->title(), $class);
            $this->assertNotSame('', $check->why(), $class);
            $this->assertNotSame('', $check->fix('fail'), $class);
            $this->assertStringNotContainsString('—', $check->why() . $check->fix('fail') . $check->fix('warn'), $class);
        }
    }
}
