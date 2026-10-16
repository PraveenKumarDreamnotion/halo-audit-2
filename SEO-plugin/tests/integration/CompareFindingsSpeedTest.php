<?php
use HaloAudit\CertProbe;
use HaloAudit\ReportView;
use HaloAudit\Scorer;
use HaloAudit\Wp\Gate;
use HaloAudit\Wp\Pdf;
use HaloAudit\Wp\Plugin;
use HaloAudit\Wp\RestController;

/**
 * Comparison findings and Speed. The two pages get deliberately different answers, so a swapped, duplicated
 * or carried-over result cannot pass: every assertion checks one page's cell against that page's own data.
 */
class Halo_Compare_Findings_Speed_Test extends WP_UnitTestCase
{
    private const MINE = 'https://example.com/page';
    private const THEIRS = 'https://rival.example.org/page';

    /** @var array<string,mixed> page URL => PageSpeed answer for it (array = HTTP answer, WP_Error = failure) */
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
        delete_option('halo_audit_psi_key');
        add_filter('halo_audit_speed_enabled', '__return_true');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.30';
    }

    public function tear_down(): void
    {
        unset($_COOKIE[Gate::COOKIE]);
        parent::tear_down();
    }

    private static function ok(string $body, string $type = 'text/html'): array
    {
        return ['headers' => ['content-type' => $type], 'body' => $body, 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
    }

    private static function lighthouse($score, $lcp, $cls): array
    {
        $audits = [];
        if ($lcp !== null) { $audits['largest-contentful-paint'] = ['numericValue' => $lcp]; }
        if ($cls !== null) { $audits['cumulative-layout-shift'] = ['numericValue' => $cls]; }
        return self::ok(json_encode(['lighthouseResult' => ['categories' => ['performance' => ['score' => $score]], 'audits' => $audits]]), 'application/json');
    }

    public function fake_http($pre, $args, $url)
    {
        $this->requested[] = $url;
        if (strpos($url, 'googleapis.com/pagespeedonline') !== false) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $this->assertSame('mobile', $q['strategy']);
            $this->assertSame('performance', $q['category']);
            return $this->psi[$q['url']] ?? new WP_Error('http_request_failed', 'no PageSpeed fixture for this page');
        }
        if ($url === self::MINE) {
            return self::ok('<html lang="en"><head><title>MBA Programme at Example Business School India</title><meta name="description" content="' . str_repeat('An MBA programme for working people. ', 4) . '"></head><body><h1>MBA programme</h1><p>' . str_repeat('word ', 320) . '</p></body></html>');
        }
        if ($url === self::THEIRS) {
            return self::ok('<html><head></head><body><p>Hello</p></body></html>');
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

    /** Runs a comparison and both speed steps; returns the full (unlocked) view. */
    private function compareWithSpeed(): array
    {
        $id = $this->call('POST', '/analyze', ['url' => self::MINE, 'competitor_url' => self::THEIRS, 'keyword' => 'mba'])->get_data()['report_id'];
        $this->call('POST', '/speed', ['report_id' => $id]);
        $this->call('POST', '/speed', ['report_id' => $id]);
        $_COOKIE[Gate::COOKIE] = Plugin::gate()->token('Asha', 'asha@example.com', time() + 60);
        return $this->call('GET', '/report/' . $id)->get_data();
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

    /* Findings: pairing */

    private static function row(string $id, string $group, string $status, string $side): array
    {
        return ['id' => $id, 'group' => $group, 'severity' => 2, 'title' => 'Title ' . $id, 'why' => 'Why ' . $id, 'status' => $status,
            'evidence' => $side . '-' . $id, 'message' => in_array($status, ['warn', 'fail'], true) ? 'Fix ' . $side . '-' . $id : ''];
    }

    private function fixtures(): array
    {
        $mine = [self::row('title_length', 'onpage', 'pass', 'MINE'), self::row('meta_description', 'onpage', 'fail', 'MINE'),
            self::row('edu_programs', 'education', 'pass', 'MINE'), self::row('https', 'technical', 'warn', 'MINE'), self::row('psi_score', 'speed', 'not_measured', 'MINE')];
        $theirs = [self::row('https', 'technical', 'pass', 'THEIRS'), self::row('psi_score', 'speed', 'fail', 'THEIRS'), self::row('title_length', 'onpage', 'fail', 'THEIRS'),
            self::row('meta_description', 'onpage', 'pass', 'THEIRS'), self::row('theirs_only', 'ai', 'warn', 'THEIRS')];
        return [$mine, $theirs];
    }

    public function test_every_finding_is_matched_to_its_own_page_by_check_id(): void
    {
        [$mine, $theirs] = $this->fixtures();
        $pairs = ReportView::pairs($mine, $theirs);
        $byId = array_column($pairs, null, 'id');

        $this->assertSame(['title_length', 'meta_description', 'https', 'theirs_only', 'psi_score', 'edu_programs'], array_column($pairs, 'id'), 'Scorer::LABELS group order, then first appearance.');
        foreach ($pairs as $pair) {
            if ($pair['mine'] !== null) {
                $this->assertSame('MINE-' . $pair['id'], $pair['mine']['evidence'], $pair['id']);
                $this->assertSame(self::check($mine, $pair['id'])['status'], $pair['mine']['status'], $pair['id']);
            }
            if ($pair['theirs'] !== null) {
                $this->assertSame('THEIRS-' . $pair['id'], $pair['theirs']['evidence'], $pair['id']);
                $this->assertSame(self::check($theirs, $pair['id'])['status'], $pair['theirs']['status'], $pair['id']);
                $this->assertArrayNotHasKey('message', $pair['theirs'], 'The fix sentence belongs to your page only.');
            }
        }
        $this->assertNull($byId['edu_programs']['theirs'], 'Ran on your page only: the competitor side is "not checked", not invented.');
        $this->assertNull($byId['theirs_only']['mine'], 'A competitor-only check is kept, with no result invented for your page.');
        $this->assertSame('not_measured', $byId['psi_score']['mine']['status'], 'Not measured stays not measured.');
        $this->assertSame('Fix MINE-meta_description', $byId['meta_description']['mine']['message']);
    }

    public function test_full_comparison_carries_the_pairs_and_the_free_view_carries_no_findings(): void
    {
        $id = $this->call('POST', '/analyze', ['url' => self::MINE, 'competitor_url' => self::THEIRS, 'keyword' => 'mba'])->get_data()['report_id'];
        $free = $this->call('POST', '/speed', ['report_id' => $id])->get_data();
        $this->assertArrayNotHasKey('pairs', $free);
        $this->assertStringNotContainsString('"evidence"', wp_json_encode($free));

        $_COOKIE[Gate::COOKIE] = Plugin::gate()->token('Asha', 'asha@example.com', time() + 60);
        $full = $this->call('GET', '/report/' . $id)->get_data();
        $this->assertSame(array_column(ReportView::pairs($full['checks'], $full['competitor']['checks']), 'id'), array_column($full['pairs'], 'id'));
        foreach ($full['pairs'] as $pair) {
            $this->assertSame(self::check($full['checks'], $pair['id'])['evidence'], $pair['mine']['evidence'], $pair['id']);
            $this->assertSame(self::check($full['competitor']['checks'], $pair['id'])['evidence'], $pair['theirs']['evidence'], $pair['id']);
        }
        $title = array_column($full['pairs'], null, 'id')['title_length'];
        $this->assertNotSame($title['mine']['evidence'], $title['theirs']['evidence'], 'The two pages have different titles, so the cells differ.');
    }

    public function test_single_page_full_report_has_no_pairs(): void
    {
        $id = $this->call('POST', '/analyze', ['url' => self::MINE, 'keyword' => 'mba'])->get_data()['report_id'];
        $_COOKIE[Gate::COOKIE] = Plugin::gate()->token('Asha', 'asha@example.com', time() + 60);
        $this->assertArrayNotHasKey('pairs', $this->call('GET', '/report/' . $id)->get_data());
    }

    public function test_pdf_puts_each_page_in_its_own_column_row_by_row(): void
    {
        [$mine, $theirs] = $this->fixtures();
        $summary = static fn(?int $s) => ['groups' => ['onpage' => ['score' => $s, 'pass' => 1, 'warn' => 0, 'fail' => 1, 'not_measured' => 0]] + array_fill_keys(['technical', 'ai', 'speed'], ['score' => null, 'pass' => 0, 'warn' => 0, 'fail' => 0, 'not_measured' => 0]), 'overall' => $s, 'grade' => 'C'];
        $report = ['engine_version' => '2.0.1', 'url' => self::MINE, 'keyword' => 'k', 'created_at' => time(), 'is_education' => true, 'speed_done' => true, 'checks' => $mine, 'summary' => $summary(50),
            'competitor' => ['url' => self::THEIRS, 'is_education' => false, 'speed_done' => true, 'checks' => $theirs, 'summary' => $summary(67)]];
        $html = Pdf::html($report);

        preg_match_all('#<tr><td><strong>Title ([a-z_]+)</strong></td><td class="mine">(.*?)</td><td class="theirs">(.*?)</td></tr>#s', $html, $rows, PREG_SET_ORDER);
        $this->assertCount(6, $rows);
        foreach ($rows as [, $id, $mineCell, $theirCell]) {
            $this->assertStringNotContainsString('THEIRS-', $mineCell, $id);
            $this->assertStringNotContainsString('MINE-', $theirCell, $id);
            $this->assertStringNotContainsString('What to do', $theirCell, $id);
        }
        $byId = array_column(array_map(static fn($r) => ['id' => $r[1], 'mine' => $r[2], 'theirs' => $r[3]], $rows), null, 'id');
        $this->assertStringContainsString('MINE-meta_description', $byId['meta_description']['mine']);
        $this->assertStringContainsString('THEIRS-meta_description', $byId['meta_description']['theirs']);
        $this->assertStringContainsString('Not checked', $byId['edu_programs']['theirs']);
        $this->assertStringContainsString('Not checked', $byId['theirs_only']['mine']);
        $this->assertStringContainsString('Your page: 50 / 100 &nbsp; Competitor page: 67 / 100', $html);
    }

    /* Speed: mapping and independence */

    public function test_speed_score_mapping_is_from_check_statuses_not_the_lighthouse_number(): void
    {
        // Your page: Lighthouse 0.95, LCP 1.8 s, CLS 0.01 -> pass, pass, pass -> Speed 100.
        // Competitor: Lighthouse 0.70, LCP 3.0 s, CLS 0.05 -> improve (50 x 3), improve (50 x 2), pass (100 x 2) -> 450 / 7 = 64.
        $this->psi[self::MINE] = self::lighthouse(0.95, 1800, 0.01);
        $this->psi[self::THEIRS] = self::lighthouse(0.70, 3000, 0.05);
        $full = $this->compareWithSpeed();

        $this->assertSame(100, $full['summary']['groups']['speed']['score']);
        $this->assertSame(64, $full['competitor']['summary']['groups']['speed']['score']);
        $this->assertSame('95 out of 100 (Google PageSpeed, mobile)', self::check($full['checks'], 'psi_score')['evidence']);
        $this->assertSame('70 out of 100 (Google PageSpeed, mobile)', self::check($full['competitor']['checks'], 'psi_score')['evidence']);
        $this->assertSame('3.0 seconds', self::check($full['competitor']['checks'], 'lcp')['evidence']);
        $this->assertSame(Scorer::WEIGHTS['speed'], 20, 'Speed is 20 of the 100 overall weight.');
    }

    /** @dataProvider oneSideFails */
    public function test_a_failed_speed_test_is_not_measured_on_that_page_only(string $failing): void
    {
        $other = $failing === self::MINE ? self::THEIRS : self::MINE;
        $this->psi[$other] = self::lighthouse(0.95, 1800, 0.01);
        $this->psi[$failing] = $failing === self::MINE
            ? ['headers' => [], 'body' => '{"error":{"code":500}}', 'response' => ['code' => 500, 'message' => 'Error'], 'cookies' => []]
            : new WP_Error('http_request_failed', 'cURL error 28: Operation timed out');
        $full = $this->compareWithSpeed();

        $sides = [self::MINE => $full, self::THEIRS => $full['competitor']];
        $this->assertNull($sides[$failing]['summary']['groups']['speed']['score']);
        $this->assertSame(3, $sides[$failing]['summary']['groups']['speed']['not_measured']);
        $this->assertSame('not_measured', self::check($sides[$failing]['checks'], 'psi_score')['status']);
        $this->assertTrue($sides[$failing]['speed_done']);
        $this->assertSame(100, $sides[$other]['summary']['groups']['speed']['score']);
        $this->assertIsInt($sides[$failing]['summary']['overall'], 'The rest of the report is still scored.');
    }

    public function oneSideFails(): array
    {
        return ['competitor fails' => [self::THEIRS], 'your page fails' => [self::MINE]];
    }

    public function test_both_speed_tests_failing_leaves_the_rest_of_the_comparison_intact(): void
    {
        $full = $this->compareWithSpeed(); // no fixtures: both PageSpeed calls fail
        foreach ([$full, $full['competitor']] as $side) {
            $this->assertNull($side['summary']['groups']['speed']['score']);
            $this->assertIsInt($side['summary']['groups']['onpage']['score']);
            $this->assertIsInt($side['summary']['overall']);
        }
        $this->assertSame(2, count(array_filter($this->requested, static fn($u) => strpos($u, 'pagespeedonline') !== false)));
    }

    /** @dataProvider unusableAnswers */
    public function test_an_unusable_pagespeed_answer_is_not_measured_never_zero(array $answer): void
    {
        $this->psi[self::MINE] = $answer;
        $this->psi[self::THEIRS] = self::lighthouse(0.5, 2000, 0.01);
        $full = $this->compareWithSpeed();
        $this->assertNull($full['summary']['groups']['speed']['score']);
        $this->assertSame('not_measured', self::check($full['checks'], 'psi_score')['status']);
    }

    public function unusableAnswers(): array
    {
        return [
            'score null (Lighthouse runtime error)' => [self::lighthouse(null, 1800, 0.01)],
            'no LCP audit' => [self::lighthouse(0.9, null, 0.01)],
            'no CLS audit' => [self::lighthouse(0.9, 1800, null)],
            'not JSON' => [self::ok('<html>oops</html>')],
            'empty body' => [self::ok('', 'application/json')],
        ];
    }

    public function test_a_real_pagespeed_score_of_zero_is_a_measured_fail(): void
    {
        $this->psi[self::MINE] = self::lighthouse(0.0, 9000, 0.6);
        $this->psi[self::THEIRS] = self::lighthouse(0.95, 1800, 0.01);
        $full = $this->compareWithSpeed();
        $this->assertSame('fail', self::check($full['checks'], 'psi_score')['status']);
        $this->assertSame(0, $full['summary']['groups']['speed']['score'], 'Measured and slow, not missing.');
        $this->assertSame(100, $full['competitor']['summary']['groups']['speed']['score']);
    }

    public function test_speed_results_never_carry_over_between_reports(): void
    {
        $this->psi[self::MINE] = self::lighthouse(0.95, 1800, 0.01);
        $this->psi[self::THEIRS] = self::lighthouse(0.30, 6000, 0.4);
        $first = $this->compareWithSpeed();

        $this->psi[self::MINE] = self::lighthouse(0.30, 6000, 0.4);
        $this->psi[self::THEIRS] = self::lighthouse(0.95, 1800, 0.01);
        $second = $this->compareWithSpeed();

        $this->assertSame([100, 0], [$first['summary']['groups']['speed']['score'], $first['competitor']['summary']['groups']['speed']['score']]);
        $this->assertSame([0, 100], [$second['summary']['groups']['speed']['score'], $second['competitor']['summary']['groups']['speed']['score']]);
    }

    public function test_the_speed_steps_measure_your_page_first_then_the_competitor_and_nothing_twice(): void
    {
        $this->psi[self::MINE] = self::lighthouse(0.95, 1800, 0.01);
        $this->psi[self::THEIRS] = self::lighthouse(0.70, 3000, 0.05);
        $id = $this->call('POST', '/analyze', ['url' => self::MINE, 'competitor_url' => self::THEIRS, 'keyword' => 'mba'])->get_data()['report_id'];
        $psiTargets = function (): array {
            $out = [];
            foreach ($this->requested as $u) {
                if (strpos($u, 'pagespeedonline') !== false) {
                    parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
                    $out[] = $q['url'];
                }
            }
            return $out;
        };
        $one = $this->call('POST', '/speed', ['report_id' => $id])->get_data();
        $this->assertSame([self::MINE], $psiTargets());
        $this->assertSame([true, false], [$one['speed_done'], $one['competitor']['speed_done']]);
        $two = $this->call('POST', '/speed', ['report_id' => $id])->get_data();
        $this->assertSame([self::MINE, self::THEIRS], $psiTargets());
        $this->assertSame([100, 64], [$two['summary']['groups']['speed']['score'], $two['competitor']['summary']['groups']['speed']['score']]);
        $this->call('POST', '/speed', ['report_id' => $id]);
        $this->assertCount(2, $psiTargets());
    }
}
