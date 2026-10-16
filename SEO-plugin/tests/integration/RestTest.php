<?php
use HaloAudit\CertProbe;
use HaloAudit\Wp\Gate;
use HaloAudit\Wp\Plugin;
use HaloAudit\Wp\RestController;

class Halo_Rest_Test extends WP_UnitTestCase
{
    private string $code = '';

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
        add_filter('halo_audit_send_code', function ($sent, $email, $code) {
            $this->code = $code;
            return true;
        }, 10, 3);
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
    }

    public function tear_down(): void
    {
        unset($_COOKIE[Gate::COOKIE]);
        parent::tear_down();
    }

    public function fake_http($pre, $args, $url)
    {
        $ok = static fn(string $body, string $type = 'text/html') => ['headers' => ['content-type' => $type], 'body' => $body, 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
        if ($url === 'https://example.com/page') {
            return $ok('<html><head><title>MBA Programme at Example Business School India</title></head><body><h1>MBA</h1><p>' . str_repeat('word ', 320) . '</p></body></html>');
        }
        if (strpos($url, 'googleapis.com/pagespeedonline') !== false) {
            return $ok('{"lighthouseResult":{"categories":{"performance":{"score":0.95}},"audits":{"largest-contentful-paint":{"numericValue":1800},"cumulative-layout-shift":{"numericValue":0.01}}}}', 'application/json');
        }
        return ['headers' => [], 'body' => '', 'response' => ['code' => 404, 'message' => 'Not Found'], 'cookies' => []];
    }

    private function call(string $method, string $route, array $params = [], bool $withNonce = true): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/' . RestController::NS . $route);
        if ($withNonce) {
            $request->set_header('X-Halo-Nonce', wp_create_nonce(RestController::NONCE_ACTION));
        }
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        return rest_get_server()->dispatch($request);
    }

    private function analyze(): array
    {
        return $this->call('POST', '/analyze', ['url' => 'example.com/page', 'keyword' => 'mba'])->get_data();
    }

    public function test_nonce_route_returns_a_valid_nonce(): void
    {
        $data = $this->call('GET', '/nonce', [], false)->get_data();
        $this->assertSame(1, wp_verify_nonce($data['nonce'], RestController::NONCE_ACTION));
    }

    public function test_routes_refuse_a_missing_nonce(): void
    {
        $this->assertSame(403, $this->call('POST', '/analyze', ['url' => 'example.com/page', 'keyword' => 'mba'], false)->get_status());
    }

    public function test_analyze_returns_the_free_view_only(): void
    {
        $data = $this->analyze();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $data['report_id']);
        $this->assertFalse($data['unlocked']);
        $this->assertIsInt($data['summary']['overall']);
        $this->assertArrayNotHasKey('checks', $data);
    }

    public function test_analyze_reports_unreadable_pages_with_422(): void
    {
        $response = $this->call('POST', '/analyze', ['url' => 'https://example.com/missing', 'keyword' => 'mba']);
        $this->assertSame(422, $response->get_status());
        $this->assertStringContainsString('404', $response->get_data()['message']);
    }

    public function test_eleventh_analysis_from_one_ip_is_refused(): void
    {
        foreach (range(1, 10) as $i) {
            $this->assertSame(200, $this->call('POST', '/analyze', ['url' => 'example.com/page', 'keyword' => 'mba'])->get_status());
        }
        $this->assertSame(429, $this->call('POST', '/analyze', ['url' => 'example.com/page', 'keyword' => 'mba'])->get_status());
    }

    public function test_client_ip_filter_is_used(): void
    {
        add_filter('halo_audit_client_ip', static fn() => '198.51.100.7');
        $this->assertSame('198.51.100.7', RestController::clientIp());
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
        remove_all_filters('halo_audit_client_ip');
        $this->assertSame('203.0.113.5', RestController::clientIp());
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    public function test_full_report_is_refused_without_unlock(): void
    {
        $id = $this->analyze()['report_id'];
        $this->assertSame(403, $this->call('GET', '/report/' . $id)->get_status());
    }

    public function test_gate_flow_unlocks_the_full_report_and_saves_the_lead(): void
    {
        $id = $this->analyze()['report_id'];
        $this->assertSame(200, $this->call('POST', '/gate/send', ['email' => 'asha@example.com', 'report_id' => $id])->get_status());

        $verified = $this->call('POST', '/gate/verify', ['email' => 'asha@example.com', 'code' => $this->code, 'name' => 'Asha Rao', 'report_id' => $id]);
        $this->assertSame(200, $verified->get_status());
        $this->assertNotEmpty($verified->get_data()['checks']);
        $this->assertSame('asha@example.com', Plugin::store()->get($id)['email']);
    }

    public function test_unlock_cookie_opens_later_reports_and_records_the_lead(): void
    {
        $_COOKIE[Gate::COOKIE] = Plugin::gate()->token('Asha Rao', 'asha@example.com', time() + 60);
        $data = $this->analyze();
        $this->assertTrue($data['unlocked']);

        $full = $this->call('GET', '/report/' . $data['report_id']);
        $this->assertSame(200, $full->get_status());
        $this->assertNotEmpty($full->get_data()['checks']);
        $this->assertSame('asha@example.com', Plugin::store()->get($data['report_id'])['email']);
    }

    public function test_wrong_code_is_refused_and_report_stays_locked(): void
    {
        $id = $this->analyze()['report_id'];
        $this->call('POST', '/gate/send', ['email' => 'asha@example.com', 'report_id' => $id]);
        $this->assertSame(400, $this->call('POST', '/gate/verify', ['email' => 'asha@example.com', 'code' => '000000', 'name' => 'A', 'report_id' => $id])->get_status());
        $this->assertNull(Plugin::store()->get($id)['email']);
    }

    public function test_code_checks_from_one_ip_are_rate_limited(): void
    {
        $unknown = str_repeat('a', 32);
        foreach (range(1, RestController::VERIFY_PER_HOUR) as $i) {
            $status = $this->call('POST', '/gate/verify', ['email' => "u$i@example.com", 'code' => '000000', 'name' => 'A', 'report_id' => $unknown])->get_status();
            $this->assertSame(404, $status);
        }
        $response = $this->call('POST', '/gate/verify', ['email' => 'x@example.com', 'code' => '000000', 'name' => 'A', 'report_id' => $unknown]);
        $this->assertSame(429, $response->get_status());

        $_SERVER['REMOTE_ADDR'] = '203.0.113.99';
        $this->assertSame(404, $this->call('POST', '/gate/verify', ['email' => 'x@example.com', 'code' => '000000', 'name' => 'A', 'report_id' => $unknown])->get_status());
    }

    public function test_speed_fills_the_speed_group(): void
    {
        update_option('halo_audit_psi_key', 'KEY');
        $id = $this->analyze()['report_id'];
        $data = $this->call('POST', '/speed', ['report_id' => $id])->get_data();
        $this->assertTrue($data['speed_done']);
        $this->assertSame(100, $data['summary']['groups']['speed']['score']);
        $this->assertArrayNotHasKey('checks', $data);
    }

    public function test_without_a_key_speed_is_not_measured_and_google_is_not_called(): void
    {
        delete_option('halo_audit_psi_key');
        $calls = 0;
        add_filter('pre_http_request', static function ($pre, $args, $url) use (&$calls) {
            if (strpos($url, 'googleapis.com') !== false) {
                $calls++;
            }
            return $pre;
        }, 5, 3);

        $first = $this->analyze();
        $this->assertTrue($first['speed_done']);
        $this->assertNull($first['summary']['groups']['speed']['score']);
        $this->assertSame(3, $first['summary']['groups']['speed']['not_measured']);

        $after = $this->call('POST', '/speed', ['report_id' => $first['report_id']])->get_data();
        $this->assertSame($first['summary'], $after['summary']);
        $this->assertSame(0, $calls);
    }

    public function test_speed_can_be_switched_on_without_a_key_by_filter(): void
    {
        delete_option('halo_audit_psi_key');
        add_filter('halo_audit_speed_enabled', '__return_true');
        $first = $this->analyze();
        $this->assertFalse($first['speed_done']);
        $this->assertSame(100, $this->call('POST', '/speed', ['report_id' => $first['report_id']])->get_data()['summary']['groups']['speed']['score']);
    }

    public function test_unknown_report_is_404(): void
    {
        $this->assertSame(404, $this->call('POST', '/speed', ['report_id' => str_repeat('a', 32)])->get_status());
    }

    public function test_scan_slot_says_not_available(): void
    {
        $_COOKIE[Gate::COOKIE] = Plugin::gate()->token('Asha', 'asha@example.com', time() + 60);
        $id = $this->analyze()['report_id'];
        $this->assertFalse($this->call('POST', '/scan', ['report_id' => $id])->get_data()['available']);
        $this->assertFalse($this->call('GET', '/scan/abc123')->get_data()['available']);
    }

    public function test_scan_is_refused_without_unlock(): void
    {
        $id = $this->analyze()['report_id'];
        $this->assertSame(403, $this->call('POST', '/scan', ['report_id' => $id])->get_status());
    }
}
