<?php
use HaloAudit\Analyzer;
use HaloAudit\CertProbe;
use HaloAudit\Wp\Gate;
use HaloAudit\Wp\Pdf;
use HaloAudit\Wp\Plugin;
use HaloAudit\Wp\RestController;

/**
 * Speed in a comparison, one page per /speed call (side=mine or side=competitor). The two pages get different
 * PageSpeed answers, so a result that is swapped, copied to the other page or lost cannot pass. PageSpeed is
 * faked per page; nothing reaches Google.
 */
class Halo_Competitor_Speed_Test extends WP_UnitTestCase
{
    private const MINE = 'https://speed-you.example.com/page';
    private const THEIRS = 'https://speed-them.example.org/page';

    /** @var array<string,mixed> page URL => PageSpeed answer (array, WP_Error or a callable that returns one) */
    private array $psi = [];
    private array $requested = [];

    public function set_up(): void
    {
        parent::set_up();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action('rest_api_init');
        add_filter('halo_audit_dns_resolver', static fn() => static fn(string $host): array => ['93.184.216.34']);
        add_filter('halo_audit_cert_probe', static fn() => new class implements CertProbe {
            public function daysLeft(string $host): ?int { return 90; }
        });
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
        update_option('halo_audit_psi_key', 'FAKE-psi-key-for-tests-0000');
        $_SERVER['REMOTE_ADDR'] = '198.51.100.' . wp_rand(1, 254);
    }

    public function tear_down(): void
    {
        unset($_COOKIE[Gate::COOKIE]);
        parent::tear_down();
    }

    private static function answer(int $code, string $body, string $type = 'application/json'): array
    {
        return ['headers' => ['content-type' => $type], 'body' => $body, 'response' => ['code' => $code, 'message' => ''], 'cookies' => []];
    }

    private static function lighthouse($score, $lcp, $cls): array
    {
        return self::answer(200, (string) json_encode(['lighthouseResult' => [
            'categories' => ['performance' => ['score' => $score]],
            'audits' => ['largest-contentful-paint' => ['numericValue' => $lcp], 'cumulative-layout-shift' => ['numericValue' => $cls]],
        ]]));
    }

    public function fake_http($pre, $args, $url)
    {
        $this->requested[] = $url;
        if (strpos($url, 'googleapis.com/pagespeedonline') !== false) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $answer = $this->psi[$q['url']] ?? new WP_Error('http_request_failed', 'no fixture');
            return is_callable($answer) ? $answer() : $answer;
        }
        if ($url === self::MINE || $url === self::THEIRS) {
            return self::answer(200, '<html><head><title>Speed page</title></head><body><h1>Speed</h1><p>' . str_repeat('word ', 250) . '</p></body></html>', 'text/html');
        }
        return self::answer(404, '', 'text/plain');
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

    private function compare(): string
    {
        return $this->call('POST', '/analyze', ['url' => self::MINE, 'competitor_url' => self::THEIRS, 'keyword' => 'speed'])->get_data()['report_id'];
    }

    /** @return string[] the page each PageSpeed request was for, in order */
    private function psiTargets(): array
    {
        $out = [];
        foreach ($this->requested as $u) {
            if (strpos($u, 'pagespeedonline') !== false) {
                parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
                $out[] = $q['url'];
            }
        }
        return $out;
    }

    private static function check(array $checks, string $id): array
    {
        foreach ($checks as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        throw new RuntimeException("no check $id");
    }

    /** [status, evidence] of psi_score, lcp and cls */
    private static function speed(array $side): array
    {
        $out = [];
        foreach (['psi_score', 'lcp', 'cls'] as $id) {
            $row = self::check($side['checks'], $id);
            $out[$id] = [$row['status'], $row['evidence']];
        }
        return $out;
    }

    /* Each page has its own PageSpeed run */

    public function test_each_side_runs_its_own_pagespeed_request_and_stores_it_on_that_side(): void
    {
        $this->psi[self::MINE] = self::lighthouse(0.83, 2100, 0.02);   // warn pass pass -> 79
        $this->psi[self::THEIRS] = self::lighthouse(0.33, 5200, 0.31); // fail fail fail -> 0
        $id = $this->compare();
        $this->assertSame([], $this->psiTargets(), 'The fast audit never calls PageSpeed.');

        $mine = $this->call('POST', '/speed', ['report_id' => $id, 'side' => 'mine']);
        $this->assertSame(200, $mine->get_status());
        $this->assertSame([self::MINE], $this->psiTargets());
        $this->assertSame([true, false], [$mine->get_data()['speed_done'], $mine->get_data()['competitor']['speed_done']]);
        $this->assertSame('mine', $mine->get_data()['side']);

        $theirs = $this->call('POST', '/speed', ['report_id' => $id, 'side' => 'competitor'])->get_data();
        $this->assertSame([self::MINE, self::THEIRS], $this->psiTargets(), 'One request per page, each for its own address.');
        $this->assertSame([79, 0], [$theirs['summary']['groups']['speed']['score'], $theirs['competitor']['summary']['groups']['speed']['score']]);

        $stored = Plugin::store()->get($id)['report'];
        $this->assertSame([
            'psi_score' => ['warn', '83 out of 100 (Google Lighthouse, mobile lab test)'], 'lcp' => ['pass', '2.1 seconds'], 'cls' => ['pass', '0.02'],
        ], self::speed($stored));
        $this->assertSame([
            'psi_score' => ['fail', '33 out of 100 (Google Lighthouse, mobile lab test)'], 'lcp' => ['fail', '5.2 seconds'], 'cls' => ['fail', '0.31'],
        ], self::speed($stored['competitor']));
    }

    public function test_the_competitor_can_be_measured_first_and_your_page_is_still_measured_after(): void
    {
        $this->psi[self::MINE] = self::lighthouse(0.97, 1500, 0.01);
        $this->psi[self::THEIRS] = self::lighthouse(0.58, 3100, 0.17);
        $id = $this->compare();
        $first = $this->call('POST', '/speed', ['report_id' => $id, 'side' => 'competitor'])->get_data();
        $this->assertSame([false, true], [$first['speed_done'], $first['competitor']['speed_done']]);
        $this->call('POST', '/speed', ['report_id' => $id, 'side' => 'mine']);
        $this->assertSame([self::THEIRS, self::MINE], $this->psiTargets());
        $stored = Plugin::store()->get($id)['report'];
        $this->assertSame([100, 50], [$stored['summary']['groups']['speed']['score'], $stored['competitor']['summary']['groups']['speed']['score']]);
    }

    public function test_lcp_and_cls_are_mapped_independently_for_each_page(): void
    {
        // Your page: LCP slow, layout stable. Competitor: LCP fast, layout jumps. Same Lighthouse band.
        $this->psi[self::MINE] = self::lighthouse(0.62, 4600, 0.04);
        $this->psi[self::THEIRS] = self::lighthouse(0.62, 1900, 0.29);
        $id = $this->compare();
        $this->call('POST', '/speed', ['report_id' => $id, 'side' => 'mine']);
        $this->call('POST', '/speed', ['report_id' => $id, 'side' => 'competitor']);
        $stored = Plugin::store()->get($id)['report'];
        $this->assertSame([['fail', '4.6 seconds'], ['pass', '0.04']], [self::speed($stored)['lcp'], self::speed($stored)['cls']]);
        $this->assertSame([['pass', '1.9 seconds'], ['fail', '0.29']], [self::speed($stored['competitor'])['lcp'], self::speed($stored['competitor'])['cls']]);
    }

    public function test_a_measured_zero_is_kept_on_the_competitor_side(): void
    {
        $this->psi[self::MINE] = self::lighthouse(0.95, 1800, 0.01);
        $this->psi[self::THEIRS] = self::lighthouse(0.0, 12000, 0.9);
        $id = $this->compare();
        $this->call('POST', '/speed', ['report_id' => $id, 'side' => 'mine']);
        $theirs = $this->call('POST', '/speed', ['report_id' => $id, 'side' => 'competitor'])->get_data()['competitor'];
        $this->assertSame(0, $theirs['summary']['groups']['speed']['score'], 'Measured and slow: 0, not "Not measured".');
        $this->assertSame(['fail', '0 out of 100 (Google Lighthouse, mobile lab test)'], self::speed(Plugin::store()->get($id)['report']['competitor'])['psi_score']);
    }

    /* One page failing never touches the other */

    /** @dataProvider failures */
    public function test_a_failure_on_one_side_never_erases_the_other_sides_result(string $failing, $answer): void
    {
        $other = $failing === 'mine' ? 'competitor' : 'mine';
        $this->psi[self::MINE] = $failing === 'mine' ? $answer : self::lighthouse(0.91, 2000, 0.02);
        $this->psi[self::THEIRS] = $failing === 'competitor' ? $answer : self::lighthouse(0.91, 2000, 0.02);
        $id = $this->compare();
        // The page that works is measured first, so the failure comes after its result is stored.
        $this->call('POST', '/speed', ['report_id' => $id, 'side' => $other]);
        $this->call('POST', '/speed', ['report_id' => $id, 'side' => $failing]);

        $stored = Plugin::store()->get($id)['report'];
        $sides = ['mine' => $stored, 'competitor' => $stored['competitor']];
        $this->assertSame(100, $sides[$other]['summary']['groups']['speed']['score'], 'The other page keeps its result.');
        $this->assertSame('91 out of 100 (Google Lighthouse, mobile lab test)', self::check($sides[$other]['checks'], 'psi_score')['evidence']);
        $this->assertNull($sides[$failing]['summary']['groups']['speed']['score']);
        $this->assertSame(['not_measured', 'Google PageSpeed did not return a result'], self::speed($sides[$failing])['psi_score']);
        $this->assertTrue($sides[$failing]['speed_done']);
        $this->assertIsInt($sides[$failing]['summary']['overall'], 'The rest of the failing page is still scored.');
    }

    public function failures(): array
    {
        $timeout = new WP_Error('http_request_failed', 'cURL error 28: Operation timed out after 90001 milliseconds');
        $quota = self::answer(429, '{"error":{"code":429}}');
        return [
            'your page times out' => ['mine', $timeout], 'competitor times out' => ['competitor', $timeout],
            'your page over quota' => ['mine', $quota], 'competitor over quota' => ['competitor', $quota],
        ];
    }

    /**
     * Two speed requests for one report that overlap: your page's request has read the row and is waiting for
     * Google when the competitor's request reads, measures and saves. Your page's request then saves. Each
     * request writes only its own page, so both results stay (a whole-row write would put back the
     * competitor's "not measured yet" copy).
     */
    public function test_overlapping_requests_for_the_two_pages_keep_both_results(): void
    {
        $this->psi[self::THEIRS] = self::lighthouse(0.44, 3300, 0.12);
        $id = $this->compare();
        $inner = null;
        $this->psi[self::MINE] = function () use ($id, &$inner) {
            $inner = $this->call('POST', '/speed', ['report_id' => $id, 'side' => 'competitor'])->get_data();
            return self::lighthouse(0.92, 1700, 0.03);
        };
        $outer = $this->call('POST', '/speed', ['report_id' => $id, 'side' => 'mine'])->get_data();

        $this->assertSame([false, true], [$inner['speed_done'], $inner['competitor']['speed_done']], 'The competitor finished first.');
        $stored = Plugin::store()->get($id)['report'];
        $this->assertSame([true, true], [$stored['speed_done'], $stored['competitor']['speed_done']]);
        $this->assertSame('92 out of 100 (Google Lighthouse, mobile lab test)', self::check($stored['checks'], 'psi_score')['evidence']);
        $this->assertSame('44 out of 100 (Google Lighthouse, mobile lab test)', self::check($stored['competitor']['checks'], 'psi_score')['evidence']);
        $this->assertSame([true, true], [$outer['speed_done'], $outer['competitor']['speed_done']], 'The answer is the stored report, with both pages.');
    }

    /* Requests */

    public function test_a_page_with_a_result_is_never_measured_again(): void
    {
        $this->psi[self::MINE] = self::lighthouse(0.95, 1800, 0.01);
        $this->psi[self::THEIRS] = self::lighthouse(0.70, 3000, 0.05);
        $id = $this->compare();
        $this->call('POST', '/speed', ['report_id' => $id, 'side' => 'competitor']);
        $this->psi[self::THEIRS] = self::lighthouse(0.10, 9000, 0.9);
        $again = $this->call('POST', '/speed', ['report_id' => $id, 'side' => 'competitor'])->get_data();
        $this->assertSame([self::THEIRS], $this->psiTargets());
        $this->assertSame(64, $again['competitor']['summary']['groups']['speed']['score']);
    }

    public function test_a_call_without_side_from_an_older_script_measures_your_page_then_the_competitor(): void
    {
        $this->psi[self::MINE] = self::lighthouse(0.95, 1800, 0.01);
        $this->psi[self::THEIRS] = self::lighthouse(0.70, 3000, 0.05);
        $id = $this->compare();
        $this->assertSame('mine', $this->call('POST', '/speed', ['report_id' => $id])->get_data()['side']);
        $this->assertSame('competitor', $this->call('POST', '/speed', ['report_id' => $id])->get_data()['side']);
        $this->call('POST', '/speed', ['report_id' => $id]);
        $this->assertSame([self::MINE, self::THEIRS], $this->psiTargets());
    }

    /** @dataProvider badSides */
    public function test_an_unknown_side_is_refused_before_google_is_called(string $side, bool $compare): void
    {
        $id = $compare ? $this->compare() : $this->call('POST', '/analyze', ['url' => self::MINE, 'keyword' => 'speed'])->get_data()['report_id'];
        $this->assertSame(400, $this->call('POST', '/speed', ['report_id' => $id, 'side' => $side])->get_status());
        $this->assertSame([], $this->psiTargets());
    }

    public function badSides(): array
    {
        return ['competitor of a one-page report' => ['competitor', false], 'made-up side' => ['theirs', true], 'other text' => ['mine ', true]];
    }

    public function test_a_one_page_report_is_measured_as_before(): void
    {
        $this->psi[self::MINE] = self::lighthouse(0.88, 2050, 0.02); // 79
        $id = $this->call('POST', '/analyze', ['url' => self::MINE, 'keyword' => 'speed'])->get_data()['report_id'];
        $free = $this->call('POST', '/speed', ['report_id' => $id, 'side' => 'mine'])->get_data();
        $this->assertSame([true, 79], [$free['speed_done'], $free['summary']['groups']['speed']['score']]);
        $this->assertArrayNotHasKey('competitor', $free);
        $this->assertSame([self::MINE], $this->psiTargets());
    }

    /* Not yet run, not set up */

    public function test_before_the_speed_test_runs_the_stored_rows_say_it_has_not_finished(): void
    {
        $id = $this->compare();
        $stored = Plugin::store()->get($id)['report'];
        foreach ([$stored, $stored['competitor']] as $side) {
            foreach (self::speed($side) as $id_ => $row) {
                $this->assertSame(['not_measured', Analyzer::SPEED_PENDING], $row, $id_);
            }
            $this->assertNull($side['summary']['groups']['speed']['score']);
        }
    }

    public function test_without_a_key_neither_page_is_tested_and_both_say_why(): void
    {
        delete_option('halo_audit_psi_key');
        $id = $this->compare();
        $stored = Plugin::store()->get($id)['report'];
        foreach ([$stored, $stored['competitor']] as $side) {
            $this->assertTrue($side['speed_done']);
            $this->assertSame(['not_measured', Analyzer::SPEED_OFF], self::speed($side)['psi_score']);
        }
        $this->call('POST', '/speed', ['report_id' => $id, 'side' => 'competitor']);
        $this->assertSame([], $this->psiTargets());
    }

    /* PDF */

    public function test_the_pdf_shows_each_pages_own_speed_values_in_its_own_column(): void
    {
        $this->psi[self::MINE] = self::lighthouse(0.83, 2100, 0.02);
        $this->psi[self::THEIRS] = self::lighthouse(0.33, 5200, 0.31);
        $id = $this->compare();
        $this->call('POST', '/speed', ['report_id' => $id, 'side' => 'mine']);
        $this->call('POST', '/speed', ['report_id' => $id, 'side' => 'competitor']);
        $html = str_replace("\u{200B}", '', Pdf::html(Plugin::store()->get($id)['report']));

        $cells = Halo_Pdf_Rows::comparison($html);
        $expect = [
            // The row says the Lighthouse value is Google's, out of 100 (Pdf::lighthouseValue), so the cell holds the number.
            'Google Performance score (mobile)' => ['83 / 100', '33 / 100'],
            'Time until the main content shows' => ['2.1 seconds', '5.2 seconds'],
            'Layout stability' => ['0.02', '0.31'],
        ];
        foreach ($expect as $title => [$mine, $theirs]) {
            $this->assertStringContainsString(' <span class="evidence">' . $mine . '</span>', $cells[$title][0], $title);
            $this->assertStringContainsString(' <span class="evidence">' . $theirs . '</span>', $cells[$title][1], $title);
            $this->assertStringNotContainsString($theirs, $cells[$title][0], $title);
        }
        // Page 1's Speed card: your page's Halo Audit Speed score, then the competitor's.
        $this->assertMatchesRegularExpression('#<div class="c-name">Speed \(Halo Audit\)</div><div class="c-counts">.*?</div></td><td class="ca-score"><div class="c-score">79<span class="c-of"> / 100</span></div>'
            . '.*?<td class="ca-bar" colspan="2"><div class="c-bar">.*?</div></td></tr>.*?<td class="ca-vs" colspan="2"><div class="c-vs">Competitor 0 / 100&nbsp;#s', $html);
        $this->assertStringContainsString('<td class="halo halo-score">79 / 100</td><td class="halo halo-score">0 / 100</td>', $html);
        $this->assertStringNotContainsString('had not finished', $html);
    }

    public function test_a_pdf_made_while_the_competitor_test_runs_says_so_and_shows_no_number(): void
    {
        $this->psi[self::MINE] = self::lighthouse(0.83, 2100, 0.02);
        $id = $this->compare();
        $this->call('POST', '/speed', ['report_id' => $id, 'side' => 'mine']);
        $html = Pdf::html(Plugin::store()->get($id)['report']);
        $this->assertStringContainsString('Competitor page: the speed test had not finished when this PDF was made', $html);
        $this->assertStringNotContainsString('Your page: the speed test', $html);
        $this->assertStringContainsString(Analyzer::SPEED_PENDING, $html);
    }

    public function test_the_pdf_route_still_needs_the_unlock_and_names_the_file_halo_audit_pdf(): void
    {
        $id = $this->compare();
        $this->assertSame(403, $this->call('GET', '/report/' . $id . '/pdf')->get_status());
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/engine/Wp/RestController.php');
        $this->assertStringContainsString("header('Content-Disposition: attachment; filename=\"' . Pdf::FILENAME . '\"');", $source);
        $this->assertSame('halo-audit.pdf', Pdf::FILENAME);
    }
}
