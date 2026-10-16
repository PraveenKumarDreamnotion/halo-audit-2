<?php
use HaloAudit\Wp\Plugin;
use HaloAudit\Wp\WpHttpClient;

class Halo_Adapters_Test extends WP_UnitTestCase
{
    public function test_http_client_maps_a_response_and_does_not_follow_redirects(): void
    {
        $seen = [];
        add_filter('pre_http_request', static function ($pre, $args, $url) use (&$seen) {
            $seen = $args;
            return ['headers' => ['Location' => 'https://example.com/next', 'X-A' => ['1', '2']], 'body' => 'hello', 'response' => ['code' => 301, 'message' => 'Moved'], 'cookies' => []];
        }, 10, 3);

        $response = (new WpHttpClient())->request('GET', 'https://example.com/', 7, 1234);

        $this->assertSame(301, $response->status);
        $this->assertSame('hello', $response->body);
        $this->assertSame('https://example.com/next', $response->header('location'));
        $this->assertSame('1, 2', $response->header('x-a'));
        $this->assertSame(0, $seen['redirection']);
        $this->assertSame(7, $seen['timeout']);
        $this->assertSame(1234, $seen['limit_response_size']);
        $this->assertTrue($seen['sslverify']);
        $this->assertTrue($seen['reject_unsafe_urls']);
        $this->assertStringContainsString('HaloAudit', $seen['user-agent']);
    }

    public function test_user_agent_names_this_site_and_can_be_filtered(): void
    {
        $this->assertSame('Mozilla/5.0 (compatible; HaloAudit/2.0; +' . home_url('/') . ')', WpHttpClient::userAgent());
        add_filter('halo_audit_user_agent', static fn() => 'CustomBot/1.0');
        $this->assertSame('CustomBot/1.0', WpHttpClient::userAgent());
    }

    public function test_http_client_turns_wp_error_into_error_response(): void
    {
        add_filter('pre_http_request', static fn() => new WP_Error('http_request_failed', 'cURL error 28'));
        $response = (new WpHttpClient())->request('GET', 'https://example.com/', 5, 1000);
        $this->assertSame('cURL error 28', $response->error);
    }

    public function test_plugin_builds_an_analyzer_that_blocks_private_hosts(): void
    {
        $out = Plugin::analyzer()->analyze('http://127.0.0.1/', 'x');
        $this->assertFalse($out['ok']);
        $this->assertSame('blocked', $out['code']);
    }

    public function test_resolve_entry_pins_the_checked_address(): void
    {
        $this->assertSame('example.com:443:93.184.216.34', WpHttpClient::resolveEntry('https://Example.com/a', ['2606:4700:4700::1111', '93.184.216.34']));
        $this->assertSame('example.com:80:[2606:4700:4700::1111]', WpHttpClient::resolveEntry('http://example.com/', ['2606:4700:4700::1111']));
        $this->assertSame('example.com:443:93.184.216.34', WpHttpClient::resolveEntry('https://example.com:443/', ['93.184.216.34']));
        $this->assertNull(WpHttpClient::resolveEntry('https://93.184.216.34/', ['93.184.216.34']));
        $this->assertNull(WpHttpClient::resolveEntry('https://example.com/', []));
    }

    private static function curlHooks(): int
    {
        global $wp_filter;
        return isset($wp_filter['http_api_curl']) ? count($wp_filter['http_api_curl']->callbacks[10] ?? []) : 0;
    }

    public function test_pin_is_active_only_during_the_request(): void
    {
        $before = self::curlHooks();
        $during = null;
        add_filter('pre_http_request', static function () use (&$during) {
            $during = self::curlHooks();
            return ['headers' => [], 'body' => 'ok', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
        });

        (new WpHttpClient())->request('GET', 'https://example.com/', 5, 1000, [], ['93.184.216.34']);
        $this->assertSame($before + 1, $during);
        $this->assertSame($before, self::curlHooks());

        (new WpHttpClient())->request('GET', 'https://example.com/', 5, 1000);
        $this->assertSame($before, $during, 'no pin without checked addresses');
    }

    public function test_pin_is_removed_when_the_request_throws(): void
    {
        $before = self::curlHooks();
        add_filter('pre_http_request', static function () {
            throw new RuntimeException('transport blew up');
        });
        try {
            (new WpHttpClient())->request('GET', 'https://example.com/', 5, 1000, [], ['93.184.216.34']);
            $this->fail('expected the exception');
        } catch (RuntimeException $e) {
            $this->assertSame($before, self::curlHooks());
        }
    }

    public function test_curl_is_usable_in_this_environment(): void
    {
        $this->assertTrue(WpHttpClient::curlUsable('https://example.com/'));
    }

    public function test_without_usable_curl_a_page_fetch_fails_closed_before_any_request(): void
    {
        add_filter('halo_audit_curl_usable', '__return_false');
        $calls = 0;
        add_filter('pre_http_request', static function ($pre) use (&$calls) {
            $calls++;
            return $pre;
        });

        $response = (new WpHttpClient())->request('GET', 'https://example.com/', 5, 1000, [], ['93.184.216.34']);
        $this->assertStringStartsWith(WpHttpClient::UNSAFE_TRANSPORT, (string) $response->error);
        $this->assertSame(0, $calls);

        $out = Plugin::analyzer()->analyze('https://example.com/', 'x');
        $this->assertFalse($out['ok']);
    }

    public function test_requests_without_pins_are_not_forced(): void
    {
        // PageSpeed and other fixed API calls carry no pinned address and are not affected.
        add_filter('halo_audit_curl_usable', '__return_false');
        add_filter('pre_http_request', static fn() => ['headers' => [], 'body' => '{}', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []]);
        $this->assertSame(200, (new WpHttpClient())->request('GET', 'https://www.googleapis.com/x', 5, 1000)->status);
    }

    public function test_an_answer_that_did_not_pass_the_pin_is_refused(): void
    {
        // Registered while the request runs, after the client's own watcher: to the client this looks
        // like a real answer that arrived without the cURL pin firing (another transport).
        add_filter('http_request_args', static function ($args) {
            add_filter('pre_http_request', static fn() => ['headers' => [], 'body' => 'x', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []], PHP_INT_MAX);
            return $args;
        });
        $response = (new WpHttpClient())->request('GET', 'https://example.com/', 5, 1000, [], ['93.184.216.34']);
        $this->assertStringStartsWith(WpHttpClient::UNSAFE_TRANSPORT, (string) $response->error);
    }

    public function test_old_public_ajax_actions_are_gone(): void
    {
        foreach (['perform_seo_analysis', 'fetch_page_title', 'send_otp', 'verify_otp_and_save_data', 'download_pdf_report', 'test_wp_mail'] as $action) {
            $this->assertFalse(has_action('wp_ajax_nopriv_' . $action), $action);
        }
        $this->assertSame('2.0.1', SEO_ANALYZER_VERSION);
    }
}
