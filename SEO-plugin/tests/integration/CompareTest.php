<?php
use HaloAudit\CertProbe;
use HaloAudit\Wp\Gate;
use HaloAudit\Wp\Installer;
use HaloAudit\Wp\Pdf;
use HaloAudit\Wp\Plugin;
use HaloAudit\Wp\RestController;

/** Competitor comparison: comparison="true" on the shortcode and competitor_url on /analyze. */
class Halo_Compare_Test extends WP_UnitTestCase
{
    private const MINE = 'https://example.com/page';
    private const THEIRS = 'https://rival.example.org/page';

    /** @var string[] Every address the fake network was asked for. */
    private array $requested = [];
    /** @var array<string,string[]> host => addresses the fake DNS gives. */
    private array $dns = [];
    private string $code = '';
    private bool $psiFailsForRival = false;

    public function set_up(): void
    {
        parent::set_up();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action('rest_api_init');

        $this->requested = [];
        $this->dns = [];
        add_filter('halo_audit_dns_resolver', fn() => fn(string $host): array => $this->dns[$host] ?? ['93.184.216.34']);
        add_filter('halo_audit_cert_probe', static fn() => new class implements CertProbe {
            public function daysLeft(string $host): ?int { return 90; }
        });
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
        // No mail leaves the test: the code is captured here.
        add_filter('halo_audit_send_code', function ($sent, $email, $code) {
            $this->code = $code;
            return true;
        }, 10, 3);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    }

    public function tear_down(): void
    {
        unset($_COOKIE[Gate::COOKIE]);
        parent::tear_down();
    }

    public function fake_http($pre, $args, $url)
    {
        $this->requested[] = $url;
        $ok = static fn(string $body, string $type = 'text/html') => ['headers' => ['content-type' => $type], 'body' => $body, 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
        if ($url === self::MINE) {
            return $ok('<html lang="en"><head><title>MBA Programme at Example Business School India</title><meta name="description" content="' . str_repeat('An MBA programme for working people. ', 4) . '"></head><body><h1>MBA programme</h1><p>' . str_repeat('word ', 320) . '</p></body></html>');
        }
        if ($url === 'https://rival.example.org/to-mine') {
            return ['headers' => ['location' => self::MINE], 'body' => '', 'response' => ['code' => 301, 'message' => 'Moved'], 'cookies' => []];
        }
        if ($url === self::THEIRS) {
            // A thin page: no title, no description, no H1. It must score differently from ours.
            return $ok('<html><head></head><body><p>Hello</p></body></html>');
        }
        if (strpos($url, 'googleapis.com/pagespeedonline') !== false && $this->psiFailsForRival && strpos($url, rawurlencode(self::THEIRS)) !== false) {
            return ['headers' => [], 'body' => '{"error":{"code":500}}', 'response' => ['code' => 500, 'message' => 'Error'], 'cookies' => []];
        }
        if (strpos($url, 'googleapis.com/pagespeedonline') !== false) {
            return $ok('{"lighthouseResult":{"categories":{"performance":{"score":0.95}},"audits":{"largest-contentful-paint":{"numericValue":1800},"cumulative-layout-shift":{"numericValue":0.01}}}}', 'application/json');
        }
        return ['headers' => [], 'body' => '', 'response' => ['code' => 404, 'message' => 'Not Found'], 'cookies' => []];
    }

    private function call(string $method, string $route, array $params = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/' . RestController::NS . $route);
        $request->set_header('X-Halo-Nonce', wp_create_nonce(RestController::NONCE_ACTION));
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        return rest_get_server()->dispatch($request);
    }

    private function compare(string $theirs = self::THEIRS, string $mine = self::MINE): WP_REST_Response
    {
        return $this->call('POST', '/analyze', ['url' => $mine, 'competitor_url' => $theirs, 'keyword' => 'mba']);
    }

    private function rows(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::resultsTable());
    }

    private function pageFetches(string $url): int
    {
        return count(array_filter($this->requested, static fn($u) => $u === $url));
    }

    /* Shortcode */

    public function test_comparison_shortcode_shows_the_competitor_field_between_the_page_and_the_keyword(): void
    {
        $html = do_shortcode('[seo_analyzer title="Halo Competitor Analysis" popup="false" comparison="true" nlp="false" competitor="true"]');

        $this->assertStringContainsString('data-compare="1"', $html);
        $this->assertStringContainsString('halo-audit__form--compare', $html);
        $this->assertSame(3, substr_count($html, '<label class="halo-audit__field">'));
        $this->assertMatchesRegularExpression('/name="url".*name="competitor_url".*name="keyword"/s', $html);
        $this->assertStringContainsString('Your page address', $html);
        $this->assertStringContainsString('Competitor page address', $html);
    }

    /** @dataProvider modes */
    public function test_shortcode_attributes_decide_comparison_mode(string $shortcode, bool $compare): void
    {
        $html = do_shortcode($shortcode);
        $this->assertSame($compare, strpos($html, 'name="competitor_url"') !== false, $shortcode);
        $this->assertSame($compare, strpos($html, 'data-compare="1"') !== false, $shortcode);
        if (!$compare) {
            $this->assertSame(2, substr_count($html, '<label class="halo-audit__field">'));
            $this->assertStringContainsString('<span class="halo-audit__label">Page address</span>', $html);
        }
    }

    public function modes(): array
    {
        return [
            'single page (no attributes)' => ['[seo_analyzer]', false],
            'single page as on page 917' => ['[seo_analyzer title="Halo Audit" popup="false" nlp="false"]', false],
            'comparison and competitor true' => ['[seo_analyzer comparison="true" competitor="true"]', true],
            'comparison true alone (1.x)' => ['[seo_analyzer comparison="true"]', true],
            'comparison false' => ['[seo_analyzer comparison="false" competitor="true"]', false],
            'competitor true alone (no-op in 1.x)' => ['[seo_analyzer competitor="true"]', false],
            'competitor false' => ['[seo_analyzer comparison="true" competitor="false"]', false],
            'competitor 0' => ['[seo_analyzer comparison="1" competitor="0"]', false],
            'popup and nlp ignored' => ['[seo_analyzer popup="true" nlp="true"]', false],
        ];
    }

    /* Analyze */

    public function test_comparison_reads_both_pages_and_returns_both_free_summaries_only(): void
    {
        $response = $this->compare();
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();

        $this->assertSame(1, $this->pageFetches(self::MINE));
        $this->assertSame(1, $this->pageFetches(self::THEIRS));
        $this->assertSame(self::MINE, $data['url']);
        $this->assertSame(self::THEIRS, $data['competitor']['url']);
        $this->assertIsInt($data['summary']['overall']);
        $this->assertIsInt($data['competitor']['summary']['overall']);
        $this->assertNotSame($data['summary'], $data['competitor']['summary'], 'Each side has its own result.');
        $this->assertGreaterThan($data['competitor']['summary']['groups']['onpage']['score'], $data['summary']['groups']['onpage']['score']);
        $this->assertArrayNotHasKey('checks', $data);
        $this->assertArrayNotHasKey('checks', $data['competitor']);
        $this->assertFalse($data['unlocked']);
    }

    public function test_comparison_is_stored_in_the_competitor_columns(): void
    {
        global $wpdb;
        $id = $this->compare()->get_data()['report_id'];
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Installer::resultsTable() . ' WHERE report_key = %s', $id), ARRAY_A);

        $this->assertSame(self::THEIRS, $row['competitor_url']);
        $this->assertNotNull($row['competitor_overall_score']);
        $this->assertArrayNotHasKey('competitor', json_decode($row['results'], true));
        $this->assertSame(self::THEIRS, json_decode($row['competitor_results'], true)['url']);
        $this->assertSame(self::THEIRS, Plugin::store()->get($id)['report']['competitor']['url']);
    }

    public function test_single_page_analysis_is_unchanged(): void
    {
        $data = $this->call('POST', '/analyze', ['url' => self::MINE, 'keyword' => 'mba'])->get_data();
        $this->assertArrayNotHasKey('competitor', $data);
        $this->assertSame(0, $this->pageFetches(self::THEIRS));
        $this->assertSame(['url', 'keyword', 'is_education', 'speed_done', 'summary', 'labels', 'report_id', 'unlocked'], array_keys($data));
    }

    public function test_full_comparison_needs_the_unlock_and_then_holds_both_sets_of_findings(): void
    {
        $id = $this->compare()->get_data()['report_id'];
        $this->assertSame(403, $this->call('GET', '/report/' . $id)->get_status());

        $_COOKIE[Gate::COOKIE] = Plugin::gate()->token('Asha Rao', 'asha@example.com', time() + 60);
        $full = $this->call('GET', '/report/' . $id)->get_data();
        $this->assertNotEmpty($full['checks']);
        $this->assertNotEmpty($full['competitor']['checks']);
        $this->assertSame(array_column($full['checks'], 'id'), array_column($full['competitor']['checks'], 'id'));
        $this->assertNotSame(array_column($full['checks'], 'status'), array_column($full['competitor']['checks'], 'status'));
    }

    public function test_unlocking_with_the_emailed_code_returns_both_sets_of_findings(): void
    {
        $id = $this->compare()->get_data()['report_id'];
        $this->assertSame(200, $this->call('POST', '/gate/send', ['email' => 'asha@example.com', 'report_id' => $id])->get_status());

        $verified = $this->call('POST', '/gate/verify', ['email' => 'asha@example.com', 'code' => $this->code, 'name' => 'Asha Rao', 'report_id' => $id]);
        $this->assertSame(200, $verified->get_status());
        $this->assertNotEmpty($verified->get_data()['checks']);
        $this->assertNotEmpty($verified->get_data()['competitor']['checks']);
        $this->assertSame('asha@example.com', Plugin::store()->get($id)['email']);
    }

    /* Failures: no report from one side only */

    public function test_unreadable_competitor_fails_the_request_and_stores_nothing(): void
    {
        $before = $this->rows();
        $response = $this->compare('https://rival.example.org/missing');

        $this->assertSame(422, $response->get_status());
        $this->assertSame('Competitor page: We could not read this page. The site answered with HTTP 404.', $response->get_data()['message']);
        $this->assertSame($before, $this->rows());
    }

    public function test_unreadable_own_page_fails_before_the_competitor_is_read(): void
    {
        $response = $this->compare(self::THEIRS, 'https://example.com/missing');

        $this->assertSame(422, $response->get_status());
        $this->assertStringStartsWith('Your page: ', $response->get_data()['message']);
        $this->assertSame(0, $this->pageFetches(self::THEIRS));
    }

    /** @dataProvider samePage */
    public function test_same_page_twice_is_refused_before_anything_is_fetched(string $theirs): void
    {
        $response = $this->compare($theirs);
        $this->assertSame(400, $response->get_status(), $theirs);
        $this->assertSame([], $this->requested);
    }

    public function samePage(): array
    {
        return [
            'no scheme' => ['example.com/page'],
            'trailing slash' => ['https://example.com/page/'],
            'upper-case host' => ['https://EXAMPLE.com/page'],
            'http instead of https' => ['http://example.com/page'],
        ];
    }

    public function test_competitor_that_redirects_to_your_page_is_refused_and_nothing_is_stored(): void
    {
        $before = $this->rows();
        $response = $this->compare('https://rival.example.org/to-mine');
        $this->assertSame(400, $response->get_status());
        $this->assertSame('Enter a competitor page that is different from your page.', $response->get_data()['message']);
        $this->assertSame($before, $this->rows());
    }

    public function test_comparison_needs_a_keyword_on_the_server_too(): void
    {
        $response = $this->call('POST', '/analyze', ['url' => self::MINE, 'competitor_url' => self::THEIRS, 'keyword' => '  ']);
        $this->assertSame(400, $response->get_status());
        $this->assertSame('Enter a keyword.', $response->get_data()['message']);
        $this->assertSame([], $this->requested);
    }

    public function test_comparison_needs_your_page_address_and_never_reads_the_competitor_without_it(): void
    {
        $response = $this->call('POST', '/analyze', ['url' => '', 'competitor_url' => self::THEIRS, 'keyword' => 'mba']);
        $this->assertSame(422, $response->get_status());
        $this->assertStringStartsWith('Your page: ', $response->get_data()['message']);
        $this->assertSame([], $this->requested);
    }

    public function test_competitor_address_is_limited_in_length(): void
    {
        $this->assertSame(400, $this->compare('https://rival.example.org/' . str_repeat('a', 2000))->get_status());
    }

    /* Address checks run on each page on its own */

    public function test_competitor_that_resolves_to_a_private_address_is_blocked(): void
    {
        $this->dns['rival.example.org'] = ['10.0.0.5'];
        $response = $this->compare();

        $this->assertSame(422, $response->get_status());
        $this->assertSame('Competitor page: This address cannot be checked.', $response->get_data()['message']);
        $this->assertSame(0, $this->pageFetches(self::THEIRS));
        $this->assertSame(1, $this->pageFetches(self::MINE), 'The public page is still read on its own merits.');
    }

    public function test_own_page_that_resolves_to_a_private_address_is_blocked_and_competitor_untouched(): void
    {
        $this->dns['example.com'] = ['127.0.0.1'];
        $response = $this->compare();

        $this->assertSame(422, $response->get_status());
        $this->assertSame('Your page: This address cannot be checked.', $response->get_data()['message']);
        $this->assertSame([], $this->requested);
    }

    /** @dataProvider blockedAddresses */
    public function test_competitor_cannot_be_a_metadata_or_private_ip(string $address): void
    {
        $response = $this->compare($address);
        $this->assertSame(422, $response->get_status(), $address);
        $this->assertStringStartsWith('Competitor page: ', $response->get_data()['message']);
        $this->assertSame(0, count(array_filter($this->requested, static fn($u) => strpos($u, 'example.com') === false)), $address);
    }

    public function blockedAddresses(): array
    {
        return [
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'loopback' => ['http://127.0.0.1/'],
            'private range' => ['https://192.168.1.10/admin'],
            'IPv6 loopback' => ['http://[::1]/'],
        ];
    }

    /* Rate limit */

    public function test_a_comparison_counts_as_two_audits(): void
    {
        foreach (range(1, 5) as $i) {
            $this->assertSame(200, $this->compare()->get_status(), "comparison $i");
        }
        $this->assertSame(429, $this->call('POST', '/analyze', ['url' => self::MINE, 'keyword' => 'mba'])->get_status());
    }

    public function test_a_comparison_is_refused_when_only_one_audit_is_left(): void
    {
        foreach (range(1, 9) as $i) {
            $this->assertSame(200, $this->call('POST', '/analyze', ['url' => self::MINE, 'keyword' => 'mba'])->get_status());
        }
        $response = $this->compare();
        $this->assertSame(429, $response->get_status());
        $this->assertStringContainsString('A comparison counts as two', $response->get_data()['message']);
        $this->assertSame(200, $this->call('POST', '/analyze', ['url' => self::MINE, 'keyword' => 'mba'])->get_status(), 'The refused comparison used nothing.');
    }

    /* Speed */

    public function test_speed_measures_both_pages_when_enabled(): void
    {
        delete_option('halo_audit_psi_key');
        add_filter('halo_audit_speed_enabled', '__return_true');
        $first = $this->compare()->get_data();
        $this->assertFalse($first['speed_done']);
        $this->assertFalse($first['competitor']['speed_done']);

        $psiCalls = fn() => count(array_filter($this->requested, static fn($u) => strpos($u, 'pagespeedonline') !== false));

        // One page per call, so one request never waits for two PageSpeed runs.
        $mid = $this->call('POST', '/speed', ['report_id' => $first['report_id']])->get_data();
        $this->assertTrue($mid['speed_done']);
        $this->assertSame(100, $mid['summary']['groups']['speed']['score']);
        $this->assertFalse($mid['competitor']['speed_done']);
        $this->assertSame(1, $psiCalls());

        $after = $this->call('POST', '/speed', ['report_id' => $first['report_id']])->get_data();
        $this->assertTrue($after['competitor']['speed_done']);
        $this->assertSame(100, $after['competitor']['summary']['groups']['speed']['score']);
        $this->assertSame(2, $psiCalls());
        $this->assertTrue(Plugin::store()->get($first['report_id'])['report']['competitor']['speed_done']);

        $this->call('POST', '/speed', ['report_id' => $first['report_id']]);
        $this->assertSame(2, $psiCalls(), 'Nothing is measured twice.');
    }

    public function test_a_failed_speed_test_for_the_competitor_is_not_measured_not_invented(): void
    {
        delete_option('halo_audit_psi_key');
        add_filter('halo_audit_speed_enabled', '__return_true');
        $this->psiFailsForRival = true;
        $id = $this->compare()->get_data()['report_id'];

        $this->call('POST', '/speed', ['report_id' => $id]);
        $after = $this->call('POST', '/speed', ['report_id' => $id])->get_data();
        $this->assertSame(100, $after['summary']['groups']['speed']['score']);
        $this->assertTrue($after['competitor']['speed_done']);
        $this->assertNull($after['competitor']['summary']['groups']['speed']['score']);
        $this->assertSame(3, $after['competitor']['summary']['groups']['speed']['not_measured']);
    }

    public function test_without_a_key_neither_page_calls_google(): void
    {
        delete_option('halo_audit_psi_key');
        $data = $this->compare()->get_data();
        $this->assertTrue($data['speed_done']);
        $this->assertTrue($data['competitor']['speed_done']);
        $this->call('POST', '/speed', ['report_id' => $data['report_id']]);
        $this->assertSame(0, count(array_filter($this->requested, static fn($u) => strpos($u, 'pagespeedonline') !== false)));
    }

    /* PDF */

    public function test_pdf_holds_both_pages_and_escapes_the_competitor_address(): void
    {
        $id = $this->compare()->get_data()['report_id'];
        $report = Plugin::store()->get($id)['report'];
        $report['competitor']['url'] = 'https://rival.example.org/?q=<script>alert(1)</script>';

        // Zero-width spaces are the PDF's line-break points in long addresses. Without them the address is
        // still escaped, and nothing in it is markup.
        $html = str_replace("\u{200B}", '', Pdf::html($report));
        $this->assertStringContainsString('<td class="meta-label">Your page</td>', $html);
        $this->assertStringContainsString('<td class="meta-label">Competitor page</td>', $html);
        $this->assertStringContainsString('<h1>SEO comparison report</h1>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        // One row per check that ran on either page, each page in its own column (see CompareFindingsSpeedTest).
        $this->assertCount(count(\HaloAudit\ReportView::pairs($report['checks'], $report['competitor']['checks'])), Halo_Pdf_Rows::comparison($html));
        $this->assertStringStartsWith('%PDF-', Pdf::render($report));
    }

    public function test_single_page_pdf_has_no_comparison(): void
    {
        $id = $this->call('POST', '/analyze', ['url' => self::MINE, 'keyword' => 'mba'])->get_data()['report_id'];
        $html = Pdf::html(Plugin::store()->get($id)['report']);
        $this->assertStringNotContainsString('comparison', $html);
        $this->assertStringNotContainsString('Competitor', $html);
        $this->assertStringNotContainsString('competitor', $html);
        $this->assertStringContainsString('<td class="meta-label">Page</td>', $html);
    }

    public function test_pdf_route_still_needs_the_unlock(): void
    {
        $id = $this->compare()->get_data()['report_id'];
        $this->assertSame(403, $this->call('GET', '/report/' . $id . '/pdf')->get_status());
    }
}
