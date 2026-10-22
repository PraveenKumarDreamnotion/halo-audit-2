<?php
namespace HaloAudit\Tests;

use HaloAudit\Analyzer;
use HaloAudit\Fetcher;
use HaloAudit\PsiClient;
use HaloAudit\ReportView;
use HaloAudit\Response;
use HaloAudit\Tests\Support\FakeHttp;
use HaloAudit\Tests\Support\FixedCert;
use HaloAudit\Tests\Support\Make;
use HaloAudit\UrlGuard;
use PHPUnit\Framework\TestCase;

final class AnalyzerTest extends TestCase
{
    private FakeHttp $http;
    private Analyzer $analyzer;

    protected function setUp(): void
    {
        $this->http = new FakeHttp();
        $guard = new UrlGuard(static fn(string $host): array => ['intranet.example' => ['10.0.0.9'], 'nowhere.example' => []][$host] ?? ['93.184.216.34']);
        $this->analyzer = new Analyzer(new Fetcher($this->http, $guard), new FixedCert(90), static fn(): int => Make::NOW);
    }

    private function html(string $extraHead = ''): string
    {
        return '<!doctype html><html lang="en"><head><title>MBA Programme at Example Business School India</title>' . $extraHead
            . '</head><body><main><h1>MBA Programme</h1>' . Make::words(320) . '<a href="/a">a</a><a href="/b">b</a></main></body></html>';
    }

    private function servePage(string $url = 'https://example.com/courses/mba/', string $extraHead = ''): void
    {
        $this->http->routes[$url] = Response::make(200, $this->html($extraHead), ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function test_successful_report_shape(): void
    {
        $this->servePage();
        $out = $this->analyzer->analyze('example.com/courses/mba/', 'MBA');
        $this->assertTrue($out['ok']);
        $report = $out['report'];
        $this->assertSame('2.0.1', $report['engine_version']);
        $this->assertSame('https://example.com/courses/mba/', $report['url']);
        $this->assertSame('MBA', $report['keyword']);
        $this->assertSame(Make::NOW, $report['created_at']);
        $this->assertFalse($report['speed_done']);
        $this->assertFalse($report['is_education']);
        $this->assertCount(33, $report['checks']); // 37 minus 4 education extras
        $this->assertSame(['id', 'group', 'severity', 'title', 'why', 'status', 'evidence', 'message'], array_keys($report['checks'][0]));
        $this->assertIsInt($report['summary']['overall']);
        $this->assertNull($report['summary']['groups']['speed']['score']);
    }

    public function test_side_file_failures_are_not_measured_not_failed(): void
    {
        $this->servePage(); // robots, sitemap, llms all have no route: transport error
        $rows = array_column($this->analyzer->analyze('https://example.com/courses/mba/', 'mba')['report']['checks'], 'status', 'id');
        $this->assertSame('not_measured', $rows['ai_crawlers']);
        $this->assertSame('not_measured', $rows['robots_reachable']);
        $this->assertSame('not_measured', $rows['sitemap']);
        $this->assertSame('not_measured', $rows['llms_txt']);
    }

    public function test_education_site_gets_extras_and_same_score(): void
    {
        $this->servePage();
        $plain = $this->analyzer->analyze('https://example.com/courses/mba/', 'mba')['report'];

        $this->servePage('https://example.edu.in/courses/mba/');
        $edu = $this->analyzer->analyze('https://example.edu.in/courses/mba/', 'mba')['report'];

        $this->assertTrue($edu['is_education']);
        $this->assertCount(37, $edu['checks']);
        $this->assertSame($plain['summary']['overall'], $edu['summary']['overall']);
    }

    /** @dataProvider failures */
    public function test_unreadable_pages_give_no_score(string $url, ?Response $response, string $code): void
    {
        if ($response !== null) {
            $this->http->routes[$url] = $response;
        }
        $out = $this->analyzer->analyze($url, 'mba');
        $this->assertFalse($out['ok']);
        $this->assertSame($code, $out['code']);
        $this->assertNotSame('', $out['message']);
        $this->assertArrayNotHasKey('report', $out);
    }

    public function failures(): array
    {
        return [
            'empty' => ['', null, 'invalid'],
            'private host' => ['https://intranet.example/', null, 'blocked'],
            'timeout' => ['https://example.com/down', null, 'unreachable'],
            '404' => ['https://example.com/missing', Response::make(404, 'nope', ['Content-Type' => 'text/html']), 'http_error'],
            'bot wall' => ['https://example.com/wall', Response::make(403, 'denied', ['Content-Type' => 'text/html']), 'http_error'],
            'pdf' => ['https://example.com/file.pdf', Response::make(200, '%PDF-1.7', ['Content-Type' => 'application/pdf']), 'not_html'],
            // Only a 2xx answer carries a page to score.
            'no content' => ['https://example.com/n', Response::make(204, '', ['Content-Type' => 'text/html']), 'empty'],
            'not modified' => ['https://example.com/n', Response::make(304, '', ['Content-Type' => 'text/html']), 'http_error'],
            'redirect without address' => ['https://example.com/n', Response::make(302, '<html><body>moved</body></html>', ['Content-Type' => 'text/html']), 'http_error'],
            'multiple choices' => ['https://example.com/n', Response::make(300, '<html><body>pick</body></html>', ['Content-Type' => 'text/html']), 'http_error'],
            'informational' => ['https://example.com/n', Response::make(101, '<html></html>', ['Content-Type' => 'text/html']), 'http_error'],
            'empty 200' => ['https://example.com/n', Response::make(200, " \n ", ['Content-Type' => 'text/html']), 'empty'],
            // No Content-Type: the body must look like HTML.
            'untyped text' => ['https://example.com/n', Response::make(200, 'plain words, not a page'), 'not_html'],
            'untyped json' => ['https://example.com/n', Response::make(200, '{"html":"<p>no</p>"}'), 'not_html'],
            // Each transport problem gets its own code.
            'dns' => ['https://example.com/n', Response::error('cURL error 6: Could not resolve host: example.com'), 'dns'],
            'unresolvable name' => ['https://nowhere.example/', null, 'dns'],
            'tls' => ['https://example.com/n', Response::error('cURL error 60: SSL certificate problem: certificate has expired'), 'tls'],
            'refused' => ['https://example.com/n', Response::error('cURL error 7: Failed to connect to example.com port 443: Connection refused'), 'connect'],
            'timed out' => ['https://example.com/n', Response::error('cURL error 28: Operation timed out after 15001 milliseconds'), 'unreachable'],
            'wp refused url' => ['https://example.com/n', Response::error('A valid URL was not provided.'), 'blocked'],
        ];
    }

    public function test_redirect_loop_is_named(): void
    {
        foreach ([1, 2, 3, 4] as $i) {
            $this->http->routes["https://example.com/$i"] = Response::make(301, '', ['Location' => '/' . ($i + 1)]);
        }
        $out = $this->analyzer->analyze('https://example.com/1', 'mba');
        $this->assertSame('redirects', $out['code']);
        $this->assertStringContainsString('redirects more than 3 times', $out['message']);
    }

    /** @dataProvider messages */
    public function test_error_messages_say_what_went_wrong(string $error, string $code, string $phrase): void
    {
        [$gotCode, $message] = Analyzer::fetchError($error);
        $this->assertSame($code, $gotCode);
        $this->assertStringContainsString($phrase, $message);
    }

    public function messages(): array
    {
        return [
            'timeout' => ['cURL error 28: Operation timed out', 'unreachable', 'did not answer in time'],
            'budget' => ['time budget used up', 'unreachable', 'did not answer in time'],
            'dns' => ['cURL error 6: Could not resolve host: x', 'dns', 'does not resolve'],
            'guard dns' => ['blocked: the host name does not resolve', 'dns', 'does not resolve'],
            'guard private' => ['blocked: private or reserved addresses are not allowed', 'blocked', 'cannot be checked'],
            'tls' => ['cURL error 35: OpenSSL SSL_connect: SSL_ERROR_SYSCALL', 'tls', 'certificate'],
            'refused' => ['cURL error 7: Failed to connect to x port 443', 'connect', 'refused or dropped'],
            'empty reply' => ['cURL error 52: Empty reply from server', 'connect', 'refused or dropped'],
            'redirects' => ['too many redirects', 'redirects', 'redirects more than'],
            'unknown' => ['something odd', 'unreachable', 'connection to the site failed'],
            'no curl' => ['unsafe transport: the PHP cURL extension (with SSL) is needed to read pages safely', 'transport', 'cURL extension'],
            'unpinned' => ['unsafe transport: the request did not go through the pinned cURL transport', 'transport', 'cannot run audits safely'],
        ];
    }

    public function test_untyped_html_is_accepted(): void
    {
        $this->http->routes['https://example.com/courses/mba/'] = Response::make(200, "\xEF\xBB\xBF\n<!-- cache -->\n" . $this->html());
        $this->assertTrue($this->analyzer->analyze('https://example.com/courses/mba/', 'mba')['ok']);
    }

    public function test_untyped_html_fragment_without_document_start_is_rejected(): void
    {
        $this->http->routes['https://example.com/x'] = Response::make(200, 'Error: <b>database</b> unavailable <html>');
        $this->assertSame('not_html', $this->analyzer->analyze('https://example.com/x', 'mba')['code']);
    }

    public function test_unmeasured_side_files_do_not_change_the_score(): void
    {
        // Same page twice: once with every side file refused or broken, once with no answer at all.
        $this->servePage();
        $baseline = $this->analyzer->analyze('https://example.com/courses/mba/', 'mba')['report'];

        $this->setUp();
        $this->servePage();
        foreach (['/robots.txt', '/llms.txt', '/sitemap.xml', '/sitemap_index.xml', '/wp-sitemap.xml', '/feed/', '/rss.xml', '/atom.xml', '/feed.xml'] as $i => $path) {
            // robots.txt gets the 503: a 4xx robots.txt is "no rules" under RFC 9309, which is a real answer.
            $this->http->routes['https://example.com' . $path] = Response::make([503, 429, 403, 500, 502][$i % 5], 'nope');
        }
        $refused = $this->analyzer->analyze('https://example.com/courses/mba/', 'mba')['report'];

        $rows = array_column($refused['checks'], 'status', 'id');
        foreach (['ai_crawlers', 'robots_reachable', 'llms_txt', 'sitemap', 'feed'] as $id) {
            $this->assertSame('not_measured', $rows[$id], $id);
        }
        $this->assertSame($baseline['summary'], $refused['summary']);
    }

    public function test_http_error_message_names_the_status(): void
    {
        $this->http->routes['https://example.com/wall'] = Response::make(403, 'denied', ['Content-Type' => 'text/html']);
        $this->assertStringContainsString('403', $this->analyzer->analyze('https://example.com/wall', 'mba')['message']);
    }

    public function test_apply_speed_fills_the_group_and_rescales(): void
    {
        $this->servePage();
        $report = $this->analyzer->analyze('https://example.com/courses/mba/', 'mba')['report'];

        $done = Analyzer::applySpeed($report, ['score' => 95, 'lcp_ms' => 1800.0, 'cls' => 0.01]);
        $this->assertTrue($done['speed_done']);
        $this->assertSame(100, $done['summary']['groups']['speed']['score']);
        $this->assertCount(33, $done['checks']);

        $failed = Analyzer::applySpeed($report, null);
        $this->assertTrue($failed['speed_done']);
        $this->assertNull($failed['summary']['groups']['speed']['score']);
        $this->assertSame($report['summary']['overall'], $failed['summary']['overall']);
    }

    /**
     * Google's Performance score is kept as Google gave it. The Speed group score is Halo Audit's own: pass 100,
     * improve 50, fix 0, weighted by severity (performance 3, LCP 2, CLS 2). 82, 4.4 s and 0.01 give
     * (50 x 3 + 0 x 2 + 100 x 2) / 7 = 50.
     */
    public function test_speed_group_is_halo_audits_own_score_not_googles(): void
    {
        $this->servePage();
        $report = $this->analyzer->analyze('https://example.com/courses/mba/', 'mba')['report'];
        $psi = PsiClient::parse('{"lighthouseResult":{"categories":{"performance":{"score":0.82}},"audits":{"largest-contentful-paint":{"numericValue":4400},"cumulative-layout-shift":{"numericValue":0.01}}}}');

        $done = Analyzer::applySpeed($report, $psi);
        $speed = array_column(array_filter($done['checks'], static fn(array $r): bool => $r['group'] === 'speed'), null, 'id');
        $this->assertSame(['warn', '82 out of 100 (Google Lighthouse, mobile lab test)'], [$speed['psi_score']['status'], $speed['psi_score']['evidence']]);
        $this->assertSame(['fail', '4.4 seconds'], [$speed['lcp']['status'], $speed['lcp']['evidence']]);
        $this->assertSame(['pass', '0.01'], [$speed['cls']['status'], $speed['cls']['evidence']]);
        $this->assertSame(50, $done['summary']['groups']['speed']['score']);

        $zero = Analyzer::applySpeed($report, ['score' => 0, 'lcp_ms' => 2000.0, 'cls' => 0.0]);
        $zeroRows = array_column($zero['checks'], null, 'id');
        $this->assertSame(['fail', '0 out of 100 (Google Lighthouse, mobile lab test)'], [$zeroRows['psi_score']['status'], $zeroRows['psi_score']['evidence']]);
        $this->assertSame(57, $zero['summary']['groups']['speed']['score']); // (0 x 3 + 100 x 2 + 100 x 2) / 7
    }

    public function test_free_view_hides_findings_and_full_view_shows_them(): void
    {
        $this->servePage();
        $report = $this->analyzer->analyze('https://example.com/courses/mba/', 'mba')['report'];
        $free = ReportView::free($report);
        $this->assertArrayNotHasKey('checks', $free);
        $this->assertSame($report['summary'], $free['summary']);
        $this->assertSame('AI readiness', $free['labels']['ai']);
        $this->assertStringNotContainsString('evidence', json_encode($free));
        $this->assertCount(33, ReportView::full($report)['checks']);
    }
}
