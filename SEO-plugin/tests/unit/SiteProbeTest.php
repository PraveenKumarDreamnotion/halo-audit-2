<?php
namespace HaloAudit\Tests;

use HaloAudit\Fetcher;
use HaloAudit\Response;
use HaloAudit\SiteProbe;
use HaloAudit\Tests\Support\FakeHttp;
use HaloAudit\Tests\Support\FixedCert;
use HaloAudit\Tests\Support\Make;
use HaloAudit\UrlGuard;
use PHPUnit\Framework\TestCase;

final class SiteProbeTest extends TestCase
{
    private FakeHttp $http;
    private Fetcher $fetcher;

    protected function setUp(): void
    {
        $this->http = new FakeHttp();
        $this->fetcher = new Fetcher($this->http, new UrlGuard(static fn(): array => ['93.184.216.34']));
    }

    private function collect(string $head = '', ?FixedCert $cert = null)
    {
        $pageResponse = Response::make(200, '', ['content-type' => 'text/html']);
        $pageResponse->finalUrl = Make::URL;
        return SiteProbe::collect($this->fetcher, $cert ?? new FixedCert(90), Make::page($head), $pageResponse);
    }

    public function test_robots_is_parsed_and_its_sitemap_is_used(): void
    {
        $this->http->routes['https://example.com/robots.txt'] = Response::make(200, "User-agent: GPTBot\nDisallow: /\nSitemap: https://example.com/map.xml", ['content-type' => 'text/plain']);
        $this->http->routes['https://example.com/map.xml'] = Response::make(200, '<?xml version="1.0"?><urlset><url><loc>https://example.com/</loc><lastmod>2025-10-01</lastmod></url><url><lastmod>2025-09-01T10:00:00+00:00</lastmod></url></urlset>');
        $site = $this->collect();
        $this->assertSame(200, $site->robotsStatus);
        $this->assertFalse($site->robots->isAllowed('GPTBot', '/'));
        $this->assertTrue($site->sitemapValid);
        $this->assertSame(strtotime('2025-10-01'), $site->sitemapNewest);
    }

    public function test_html_robots_is_not_parsed_as_rules(): void
    {
        $this->http->routes['https://example.com/robots.txt'] = Response::make(200, '<!DOCTYPE html><html><body>User-agent: * Disallow: /</body></html>', ['content-type' => 'text/html']);
        $site = $this->collect();
        $this->assertSame(404, $site->robotsStatus);
        $this->assertNull($site->robots);
    }

    public function test_missing_robots_is_404_with_no_rules(): void
    {
        $this->http->routes['https://example.com/robots.txt'] = Response::make(404, 'nope');
        $site = $this->collect();
        $this->assertSame(404, $site->robotsStatus);
        $this->assertNull($site->robots);
    }

    public function test_failed_robots_fetch_is_null(): void
    {
        $site = $this->collect();
        $this->assertNull($site->robotsStatus);
    }

    public function test_sitemap_falls_back_to_standard_paths(): void
    {
        $this->http->routes['https://example.com/sitemap.xml'] = Response::make(404, '');
        $this->http->routes['https://example.com/sitemap_index.xml'] = Response::make(200, '<sitemapindex><sitemap><loc>x</loc></sitemap></sitemapindex>');
        $site = $this->collect();
        $this->assertTrue($site->sitemapValid);
        $this->assertSame(200, $site->sitemapStatus);
        $this->assertNull($site->sitemapNewest);
    }

    public function test_html_sitemap_is_not_valid(): void
    {
        $this->http->routes['https://example.com/sitemap.xml'] = Response::make(200, '<html><body>Not found</body></html>');
        $this->http->routes['https://example.com/sitemap_index.xml'] = Response::make(404, '');
        $this->http->routes['https://example.com/wp-sitemap.xml'] = Response::make(404, '');
        $site = $this->collect();
        $this->assertFalse($site->sitemapValid);
        $this->assertSame(404, $site->sitemapStatus);
    }

    public function test_llms_txt_body_is_kept(): void
    {
        $this->http->routes['https://example.com/llms.txt'] = Response::make(200, "# Example\n> About us");
        $site = $this->collect();
        $this->assertSame(200, $site->llmsStatus);
        $this->assertStringStartsWith('# Example', $site->llmsBody);
    }

    public function test_certificate_is_asked_for_the_final_host(): void
    {
        $cert = new FixedCert(12);
        $site = $this->collect('', $cert);
        $this->assertSame(12, $site->certDaysLeft);
        $this->assertSame(['example.com'], $cert->asked);
    }

    public function test_og_image_size_is_read(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $this->http->routes['https://example.com/og.png'] = Response::make(200, $png);
        $site = $this->collect('<meta property="og:image" content="/og.png">');
        $this->assertSame(['status' => 200, 'width' => 1, 'height' => 1], $site->ogImage);
    }

    public function test_og_image_missing_from_page_leaves_null(): void
    {
        $this->assertNull($this->collect()->ogImage);
    }

    public function test_feed_from_head_link_needs_no_fetch(): void
    {
        $site = $this->collect('<link rel="alternate" type="application/rss+xml" href="/feed/">');
        $this->assertTrue($site->feedFound);
        $this->assertNotContains('https://example.com/feed/', $this->http->calls);
    }

    public function test_feed_is_sniffed_from_known_paths(): void
    {
        $this->http->routes['https://example.com/rss.xml'] = Response::make(200, '<?xml version="1.0"?><rss version="2.0"></rss>');
        $this->assertTrue($this->collect()->feedFound);
    }

    private function feedPaths(Response $answer): void
    {
        foreach (['/feed/', '/rss.xml', '/atom.xml', '/feed.xml'] as $path) {
            $this->http->routes['https://example.com' . $path] = $answer;
        }
    }

    public function test_no_feed_when_every_path_answers_definitely(): void
    {
        $this->feedPaths(Response::make(404, ''));
        $this->http->routes['https://example.com/feed/'] = Response::make(200, '<html><body>Blog</body></html>', ['content-type' => 'text/html']);
        $this->assertFalse($this->collect()->feedFound);
    }

    public function test_feed_is_unknown_when_a_path_fails(): void
    {
        $this->feedPaths(Response::make(404, ''));
        $this->http->routes['https://example.com/atom.xml'] = Response::make(503, '');
        $this->assertNull($this->collect()->feedFound);
    }

    public function test_feed_is_unknown_when_a_path_is_refused(): void
    {
        $this->feedPaths(Response::make(404, ''));
        $this->http->routes['https://example.com/feed.xml'] = Response::make(403, 'denied');
        $this->assertNull($this->collect()->feedFound);
    }

    public function test_feed_is_unknown_when_the_time_budget_runs_out(): void
    {
        $this->feedPaths(Response::make(404, ''));
        $this->fetcher->setDeadline(microtime(true) - 1); // every side fetch now reports "time budget used up"
        $this->assertNull($this->collect()->feedFound);
    }

    private function sitemapPaths(Response $answer): void
    {
        foreach (['/sitemap.xml', '/sitemap_index.xml', '/wp-sitemap.xml'] as $path) {
            $this->http->routes['https://example.com' . $path] = $answer;
        }
    }

    public function test_sitemap_server_errors_are_not_measured(): void
    {
        $this->sitemapPaths(Response::make(503, ''));
        $site = $this->collect();
        $this->assertNull($site->sitemapStatus);
        $this->assertFalse($site->sitemapValid);
    }

    public function test_one_failing_sitemap_address_makes_the_answer_unknown(): void
    {
        $this->sitemapPaths(Response::make(404, ''));
        $this->http->routes['https://example.com/wp-sitemap.xml'] = Response::make(500, '');
        $this->assertNull($this->collect()->sitemapStatus);
    }

    public function test_sitemap_declared_in_robots_that_is_refused_is_unknown(): void
    {
        $this->http->routes['https://example.com/robots.txt'] = Response::make(200, "Sitemap: https://example.com/map.xml", ['content-type' => 'text/plain']);
        $this->http->routes['https://example.com/map.xml'] = Response::make(429, '');
        $this->sitemapPaths(Response::make(404, ''));
        $this->assertNull($this->collect()->sitemapStatus);
    }

    public function test_sitemap_missing_everywhere_is_404(): void
    {
        $this->sitemapPaths(Response::make(404, ''));
        $site = $this->collect();
        $this->assertSame(404, $site->sitemapStatus);
        $this->assertFalse($site->sitemapValid);
    }

    public function test_refused_llms_txt_keeps_its_status(): void
    {
        $this->http->routes['https://example.com/llms.txt'] = Response::make(403, 'denied');
        $site = $this->collect();
        $this->assertSame(403, $site->llmsStatus);
        $this->assertSame('', $site->llmsBody);
    }
}
