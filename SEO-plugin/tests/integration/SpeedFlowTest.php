<?php
use HaloAudit\Analyzer;
use HaloAudit\CertProbe;
use HaloAudit\PsiClient;
use HaloAudit\Wp\Gate;
use HaloAudit\Wp\Pdf;
use HaloAudit\Wp\Plugin;
use HaloAudit\Wp\RestController;

/**
 * Speed from the raw PageSpeed answer to what the visitor sees, through the real REST routes, storage and PDF.
 * PageSpeed is faked per page; nothing reaches Google. Expected values are worked out by hand:
 *
 *   Speed = (points(score) x 3 + points(LCP) x 2 + points(CLS) x 2) / 7, rounded; pass 100, improve 50, fix 0.
 *   score: 90+ pass, 50-89 improve, under 50 fix. LCP: under 2500 ms pass, to 4000 ms improve. CLS: under 0.1 pass, to 0.25 improve.
 *
 * Only 13 Speed values are possible (0 14 21 29 36 43 50 57 64 71 79 86 100). 50 comes from three different
 * combinations, among them the common mobile result "score 50-89, LCP over 4 s, CLS fine".
 */
class Halo_Speed_Flow_Test extends WP_UnitTestCase
{
    private const MINE = 'https://speed-mine.example.com/page';
    private const THEIRS = 'https://speed-rival.example.org/page';

    /** @var array<string,mixed> */
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

    /** A PageSpeed v5 answer shaped like the real one (fraction score, LCP in ms, CLS unitless). */
    private static function lighthouse($score, $lcp, $cls): array
    {
        $audits = [
            'first-contentful-paint' => ['numericValue' => 1234.5, 'displayValue' => '1.2 s'],
            'speed-index' => ['numericValue' => 3456.7],
        ];
        if ($lcp !== null) { $audits['largest-contentful-paint'] = ['id' => 'largest-contentful-paint', 'score' => 0.4, 'numericValue' => $lcp, 'numericUnit' => 'millisecond']; }
        if ($cls !== null) { $audits['cumulative-layout-shift'] = ['id' => 'cumulative-layout-shift', 'score' => 1, 'numericValue' => $cls, 'numericUnit' => 'unitless']; }
        return self::answer(200, (string) json_encode([
            'id' => 'x', 'loadingExperience' => ['metrics' => ['LARGEST_CONTENTFUL_PAINT_MS' => ['percentile' => 999999]]], // field data is not used
            'lighthouseResult' => ['configSettings' => ['formFactor' => 'mobile'], 'categories' => ['performance' => ['id' => 'performance', 'score' => $score]], 'audits' => $audits],
        ]));
    }

    public function fake_http($pre, $args, $url)
    {
        $this->requested[] = $url;
        if (strpos($url, 'googleapis.com/pagespeedonline') !== false) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $this->assertSame(['mobile', 'performance'], [$q['strategy'], $q['category']]);
            $answer = $this->psi[$q['url']] ?? new WP_Error('http_request_failed', 'no fixture');
            return is_callable($answer) ? $answer() : $answer;
        }
        if ($url === self::MINE || $url === self::THEIRS) {
            return self::answer(200, '<html><head><title>Speed test page</title></head><body><h1>Speed</h1><p>' . str_repeat('word ', 250) . '</p></body></html>', 'text/html');
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

    private function psiCalls(): int
    {
        return count(array_filter($this->requested, static fn($u) => strpos($u, 'pagespeedonline') !== false));
    }

    private static function speedRows(array $checks): array
    {
        $out = [];
        foreach ($checks as $row) {
            if ($row['group'] === 'speed') {
                $out[$row['id']] = [$row['status'], $row['evidence']];
            }
        }
        return $out;
    }

    /* Real values in, the right Speed score out */

    /** @dataProvider targets */
    public function test_raw_pagespeed_values_reach_the_visitor_and_the_pdf_unchanged($score, $lcp, $cls, array $expectedRows, int $speed): void
    {
        $this->psi[self::MINE] = self::lighthouse($score, $lcp, $cls);
        $id = $this->call('POST', '/analyze', ['url' => self::MINE, 'keyword' => 'speed'])->get_data()['report_id'];

        $free = $this->call('POST', '/speed', ['report_id' => $id])->get_data();
        $this->assertTrue($free['speed_done']);
        $this->assertSame($speed, $free['summary']['groups']['speed']['score'], 'The free view shows the weighted Speed score.');

        $stored = Plugin::store()->get($id)['report'];
        $this->assertSame($expectedRows, self::speedRows($stored['checks']), 'Stored check results hold the raw values.');
        $this->assertSame($speed, $stored['summary']['groups']['speed']['score']);

        $_COOKIE[Gate::COOKIE] = Plugin::gate()->token('Asha', 'asha@example.com', time() + 60);
        $full = $this->call('GET', '/report/' . $id)->get_data();
        $this->assertSame($expectedRows, self::speedRows($full['checks']));
        $this->assertSame($speed, $full['summary']['groups']['speed']['score']);

        $html = Pdf::html($stored);
        $this->assertStringContainsString('<tr><td>Speed</td><td>' . $speed . '</td>', $html);
        foreach ($expectedRows as [, $evidence]) {
            $this->assertStringContainsString(htmlspecialchars($evidence), $html);
        }
        $this->assertSame(1, $this->psiCalls());
    }

    public function targets(): array
    {
        $r = static fn($ps, $pe, $ls, $le, $cs, $ce) => ['psi_score' => [$ps, $pe . ' out of 100 (Google PageSpeed, mobile)'], 'lcp' => [$ls, $le], 'cls' => [$cs, $ce]];
        return [
            // Lighthouse, LCP ms, CLS => statuses => Speed. Arithmetic in the trailing comments.
            '0: fix fix fix' => [0.31, 6120, 0.41, $r('fail', 31, 'fail', '6.1 seconds', 'fail', '0.41'), 0],
            '21: improve fix fix' => [0.66, 5480, 0.33, $r('warn', 66, 'fail', '5.5 seconds', 'fail', '0.33'), 21],              // 150/7 = 21.4
            '50: improve fix pass (the DevKinsta report)' => [0.75, 4300, 0.0, $r('warn', 75, 'fail', '4.3 seconds', 'pass', '0.00'), 50], // 350/7
            '50: improve improve improve' => [0.58, 3120, 0.17, $r('warn', 58, 'warn', '3.1 seconds', 'warn', '0.17'), 50],      // 350/7
            '50: improve pass fix' => [0.82, 1940, 0.29, $r('warn', 82, 'pass', '1.9 seconds', 'fail', '0.29'), 50],             // 350/7
            '57: fix pass pass' => [0.43, 2210, 0.06, $r('fail', 43, 'pass', '2.2 seconds', 'pass', '0.06'), 57],                // 400/7 = 57.1
            '57: pass fix improve' => [0.93, 4800, 0.12, $r('pass', 93, 'fail', '4.8 seconds', 'warn', '0.12'), 57],             // 400/7
            '64: improve improve pass' => [0.71, 2870, 0.03, $r('warn', 71, 'warn', '2.9 seconds', 'pass', '0.03'), 64],         // 450/7 = 64.3
            '79: improve pass pass' => [0.88, 2060, 0.02, $r('warn', 88, 'pass', '2.1 seconds', 'pass', '0.02'), 79],            // 550/7 = 78.6
            '86: pass improve pass' => [0.91, 3360, 0.08, $r('pass', 91, 'warn', '3.4 seconds', 'pass', '0.08'), 86],            // 600/7 = 85.7
            '100: pass pass pass' => [0.97, 1620, 0.01, $r('pass', 97, 'pass', '1.6 seconds', 'pass', '0.01'), 100],
        ];
    }

    /** Every combination of the three statuses, written out by hand. */
    public function test_all_27_status_combinations_give_the_hand_worked_speed_score(): void
    {
        // Representative raw values for each status.
        $score = ['pass' => 0.95, 'warn' => 0.70, 'fail' => 0.30];
        $lcp = ['pass' => 1800, 'warn' => 3000, 'fail' => 5000];
        $cls = ['pass' => 0.02, 'warn' => 0.15, 'fail' => 0.40];
        // [score, LCP, CLS] => Speed. (3 x s + 2 x l + 2 x c) / 7 with pass 100, warn 50, fail 0.
        $expected = [
            'pass pass pass' => 100, 'pass pass warn' => 86, 'pass pass fail' => 71,
            'pass warn pass' => 86, 'pass warn warn' => 71, 'pass warn fail' => 57,
            'pass fail pass' => 71, 'pass fail warn' => 57, 'pass fail fail' => 43,
            'warn pass pass' => 79, 'warn pass warn' => 64, 'warn pass fail' => 50,
            'warn warn pass' => 64, 'warn warn warn' => 50, 'warn warn fail' => 36,
            'warn fail pass' => 50, 'warn fail warn' => 36, 'warn fail fail' => 21,
            'fail pass pass' => 57, 'fail pass warn' => 43, 'fail pass fail' => 29,
            'fail warn pass' => 43, 'fail warn warn' => 29, 'fail warn fail' => 14,
            'fail fail pass' => 29, 'fail fail warn' => 14, 'fail fail fail' => 0,
        ];
        foreach ($expected as $combo => $speed) {
            [$s, $l, $c] = explode(' ', $combo);
            $psi = PsiClient::parse((string) json_encode(['lighthouseResult' => ['categories' => ['performance' => ['score' => $score[$s]]],
                'audits' => ['largest-contentful-paint' => ['numericValue' => $lcp[$l]], 'cumulative-layout-shift' => ['numericValue' => $cls[$c]]]]]));
            $report = Analyzer::applySpeed(['url' => self::MINE, 'keyword' => '', 'created_at' => time(), 'checks' => [
                ['id' => 'psi_score', 'group' => 'speed'], ['id' => 'lcp', 'group' => 'speed'], ['id' => 'cls', 'group' => 'speed'],
            ]], $psi);
            $this->assertSame([$s, $l, $c], array_column($report['checks'], 'status'), $combo);
            $this->assertSame($speed, $report['summary']['groups']['speed']['score'], $combo);
        }
        $values = array_values(array_unique(array_values($expected)));
        sort($values);
        $this->assertSame([0, 14, 21, 29, 36, 43, 50, 57, 64, 71, 79, 86, 100], $values, 'The only Speed values there can be.');
    }

    /* No valid measurement: "Not measured", never a number */

    /** @dataProvider unavailable */
    public function test_no_valid_pagespeed_measurement_is_not_measured_and_never_a_number($answer): void
    {
        $this->psi[self::MINE] = $answer;
        $first = $this->call('POST', '/analyze', ['url' => self::MINE, 'keyword' => 'speed'])->get_data();
        $before = $first['summary']['overall'];

        $free = $this->call('POST', '/speed', ['report_id' => $first['report_id']])->get_data();
        $this->assertTrue($free['speed_done']);
        $this->assertNull($free['summary']['groups']['speed']['score']);
        $this->assertSame([0, 0, 0, 3], [$free['summary']['groups']['speed']['pass'], $free['summary']['groups']['speed']['warn'], $free['summary']['groups']['speed']['fail'], $free['summary']['groups']['speed']['not_measured']]);
        $this->assertSame($before, $free['summary']['overall'], 'The overall is not pulled up or down by a missing speed result.');

        $stored = Plugin::store()->get($first['report_id'])['report'];
        foreach (self::speedRows($stored['checks']) as $id => [$status, $evidence]) {
            $this->assertSame(['not_measured', 'Google PageSpeed did not return a result'], [$status, $evidence], $id);
        }
        $this->assertStringContainsString('<tr><td>Speed</td><td>Not measured</td>', Pdf::html($stored));
    }

    public function unavailable(): array
    {
        return [
            'score null (Lighthouse runtime error)' => [self::lighthouse(null, 4300, 0.0)],
            'no LCP audit' => [self::lighthouse(0.75, null, 0.0)],
            'no CLS audit' => [self::lighthouse(0.75, 4300, null)],
            'no audits at all' => [self::answer(200, '{"lighthouseResult":{"categories":{"performance":{"score":0.75}}}}')],
            'score is text' => [self::lighthouse('fast', 4300, 0.0)],
            'invalid JSON' => [self::answer(200, '{"lighthouseResult":')],
            'HTML error page' => [self::answer(200, '<html><body>Service unavailable</body></html>', 'text/html')],
            'empty body' => [self::answer(200, '')],
            'HTTP 400 (page not reachable for Google)' => [self::answer(400, '{"error":{"code":400,"message":"Lighthouse returned error: FAILED_DOCUMENT_REQUEST"}}')],
            'HTTP 403 (key or quota)' => [self::answer(403, '{"error":{"code":403,"message":"The caller does not have permission"}}')],
            'HTTP 429 (quota)' => [self::answer(429, '{"error":{"code":429,"message":"Quota exceeded"}}')],
            'HTTP 500' => [self::answer(500, '{"error":{"code":500,"message":"Lighthouse returned error: NO_FCP"}}')],
            'timeout' => [new WP_Error('http_request_failed', 'cURL error 28: Operation timed out after 90001 milliseconds')],
        ];
    }

    public function test_without_a_pagespeed_key_nothing_is_requested_and_speed_is_not_measured(): void
    {
        delete_option('halo_audit_psi_key');
        $this->psi[self::MINE] = self::lighthouse(0.97, 1620, 0.01);
        $id = $this->call('POST', '/analyze', ['url' => self::MINE, 'keyword' => 'speed'])->get_data()['report_id'];
        $free = $this->call('POST', '/speed', ['report_id' => $id])->get_data();
        $this->assertSame(0, $this->psiCalls());
        $this->assertNull($free['summary']['groups']['speed']['score']);
    }

    /* Order of requests */

    public function test_a_report_read_before_the_speed_test_has_no_speed_number(): void
    {
        $this->psi[self::MINE] = self::lighthouse(0.75, 4300, 0.0);
        $id = $this->call('POST', '/analyze', ['url' => self::MINE, 'keyword' => 'speed'])->get_data()['report_id'];
        $_COOKIE[Gate::COOKIE] = Plugin::gate()->token('Asha', 'asha@example.com', time() + 60);

        $early = $this->call('GET', '/report/' . $id)->get_data();
        $this->assertFalse($early['speed_done']);
        $this->assertNull($early['summary']['groups']['speed']['score'], 'Before the test: no number, the page shows "Testing speed...".');
        $this->assertSame(['not_measured', 'not_measured', 'not_measured'], array_column(self::speedRows($early['checks']), 0));

        $this->call('POST', '/speed', ['report_id' => $id]);
        $late = $this->call('GET', '/report/' . $id)->get_data();
        $this->assertSame([true, 50], [$late['speed_done'], $late['summary']['groups']['speed']['score']]);
    }

    public function test_a_repeated_or_late_speed_call_never_measures_again_or_changes_the_result(): void
    {
        $this->psi[self::MINE] = self::lighthouse(0.88, 2050, 0.02); // 79
        $id = $this->call('POST', '/analyze', ['url' => self::MINE, 'keyword' => 'speed'])->get_data()['report_id'];
        $this->call('POST', '/speed', ['report_id' => $id]);
        $this->psi[self::MINE] = self::lighthouse(0.31, 6120, 0.41); // would be 0 if measured again
        $again = $this->call('POST', '/speed', ['report_id' => $id])->get_data();
        $this->assertSame(79, $again['summary']['groups']['speed']['score']);
        $this->assertSame(1, $this->psiCalls());
    }

    public function test_comparison_speed_steps_keep_each_pages_own_result(): void
    {
        $this->psi[self::MINE] = self::lighthouse(0.75, 4300, 0.0);   // 50
        $this->psi[self::THEIRS] = self::lighthouse(0.91, 3350, 0.08); // 86
        $id = $this->call('POST', '/analyze', ['url' => self::MINE, 'competitor_url' => self::THEIRS, 'keyword' => 'speed'])->get_data()['report_id'];

        $one = $this->call('POST', '/speed', ['report_id' => $id])->get_data();
        $this->assertSame([50, null], [$one['summary']['groups']['speed']['score'], $one['competitor']['summary']['groups']['speed']['score']]);
        $this->assertSame([true, false], [$one['speed_done'], $one['competitor']['speed_done']]);

        $two = $this->call('POST', '/speed', ['report_id' => $id])->get_data();
        $this->assertSame([50, 86], [$two['summary']['groups']['speed']['score'], $two['competitor']['summary']['groups']['speed']['score']]);
        $this->assertSame(2, $this->psiCalls());
    }
}
