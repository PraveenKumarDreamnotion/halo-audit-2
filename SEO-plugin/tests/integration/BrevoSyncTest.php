<?php
use HaloAudit\CertProbe;
use HaloAudit\Wp\Admin\Admin;
use HaloAudit\Wp\Admin\ReportsTable;
use HaloAudit\Wp\BrevoSync;
use HaloAudit\Wp\Installer;
use HaloAudit\Wp\Plugin;
use HaloAudit\Wp\RestController;

/**
 * Brevo list sync after the email code is confirmed. Every Brevo answer is scripted here; a request to
 * api.brevo.com that the test did not script fails the test, so nothing can reach the real Brevo.
 */
class Halo_Brevo_Sync_Test extends WP_UnitTestCase
{
    private const KEY = 'xkeysib-FAKE-test-key-not-real-0000000000';
    private const EMAIL = 'asha+halo@example.com';

    /** @var array<int,array{method:string,path:string,body:?array,headers:array}> */
    private array $calls = [];
    /** @var array<string,array<int,mixed>> "METHOD path" => queue of answers */
    private array $script = [];
    private array $unscripted = [];
    private string $code = '';

    public function set_up(): void
    {
        parent::set_up();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action('rest_api_init');
        update_option('seo_analyzer_brevo_api_key', self::KEY);
        update_option(BrevoSync::LIST_OPTION, '14');
        // List membership on its own here; the five contact attributes are covered by BrevoAttributesTest.
        add_filter('halo_audit_brevo_attributes', '__return_empty_array');
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
        add_filter('halo_audit_dns_resolver', static fn() => static fn(string $host): array => ['93.184.216.34']);
        add_filter('halo_audit_cert_probe', static fn() => new class implements CertProbe {
            public function daysLeft(string $host): ?int { return 90; }
        });
        add_filter('halo_audit_send_code', function ($sent, $email, $code) {
            $this->code = $code;
            return true;
        }, 10, 3);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.20';
    }

    public function tear_down(): void
    {
        $this->assertSame([], $this->unscripted, 'A Brevo request was not scripted by the test.');
        wp_unschedule_hook(BrevoSync::HOOK);
        parent::tear_down();
    }

    private function on(string $method, string $path, ...$answers): void
    {
        foreach ($answers as $answer) {
            $this->script[$method . ' ' . $path][] = $answer;
        }
    }

    private static function json(int $status, array $body = [], array $headers = []): array
    {
        return ['headers' => $headers, 'body' => $body ? json_encode($body) : '', 'response' => ['code' => $status, 'message' => ''], 'cookies' => []];
    }

    public function fake_http($pre, $args, $url)
    {
        if (strpos($url, 'https://api.brevo.com/v3') === 0) {
            $path = substr($url, strlen('https://api.brevo.com/v3'));
            $method = $args['method'] ?? 'GET';
            $this->calls[] = ['method' => $method, 'path' => $path, 'body' => isset($args['body']) ? json_decode($args['body'], true) : null, 'headers' => $args['headers'] ?? []];
            $queue = &$this->script[$method . ' ' . $path];
            if (empty($queue)) {
                $this->unscripted[] = $method . ' ' . $path;
                return new WP_Error('http_request_failed', 'unscripted');
            }
            return array_shift($queue);
        }
        if ($url === 'https://example.com/page') {
            return ['headers' => ['content-type' => 'text/html'], 'body' => '<html><head><title>Page</title></head><body><h1>Hi</h1></body></html>', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
        }
        return ['headers' => [], 'body' => '', 'response' => ['code' => 404, 'message' => 'Not Found'], 'cookies' => []];
    }

    /** A stored report with a confirmed lead, as Gate::verify leaves it. */
    private function lead(string $email = self::EMAIL): string
    {
        $key = Plugin::store()->create([
            'engine_version' => '2.0.1', 'url' => 'https://example.com/page', 'keyword' => 'k', 'created_at' => time(), 'is_education' => false,
            'speed_done' => true, 'checks' => [], 'summary' => ['groups' => [], 'overall' => 50, 'grade' => 'C'],
        ]);
        Plugin::store()->attachLead($key, 'Asha Rao', $email);
        return $key;
    }

    private function path(string $email = self::EMAIL): string
    {
        return '/contacts/' . rawurlencode($email);
    }

    private function paths(): array
    {
        return array_map(static fn($c) => $c['method'] . ' ' . $c['path'], $this->calls);
    }

    /* Success paths */

    public function test_a_new_contact_is_created_in_list_14_and_confirmed(): void
    {
        $key = $this->lead();
        $this->on('GET', $this->path(), self::json(404, ['code' => 'document_not_found', 'message' => 'Contact does not exist']), self::json(200, ['email' => self::EMAIL, 'listIds' => [14]]));
        $this->on('POST', '/contacts', self::json(201, ['id' => 501]));

        BrevoSync::run($key);

        $this->assertSame(['GET ' . $this->path(), 'POST /contacts', 'GET ' . $this->path()], $this->paths());
        $this->assertSame(['email' => self::EMAIL, 'listIds' => [14]], $this->calls[1]['body'], 'Email and list only: no attributes, no updateEnabled.');
        $this->assertSame('synced', BrevoSync::status(self::EMAIL)['state']);
        $this->assertSame(self::KEY, $this->calls[0]['headers']['api-key']);
    }

    public function test_an_existing_contact_is_only_added_to_the_list_never_changed(): void
    {
        $key = $this->lead();
        $this->on('GET', $this->path(), self::json(200, ['email' => self::EMAIL, 'listIds' => [3, 7]]), self::json(200, ['email' => self::EMAIL, 'listIds' => [3, 7, 14]]));
        $this->on('POST', '/contacts/lists/14/contacts/add', self::json(201, ['contacts' => ['success' => [self::EMAIL], 'failure' => []]]));

        BrevoSync::run($key);

        $this->assertSame(['GET ' . $this->path(), 'POST /contacts/lists/14/contacts/add', 'GET ' . $this->path()], $this->paths());
        $this->assertSame(['emails' => [self::EMAIL]], $this->calls[1]['body']);
        $this->assertNotContains('POST /contacts', $this->paths(), 'The existing contact is not created or updated.');
        $this->assertSame('synced', BrevoSync::status(self::EMAIL)['state']);
    }

    public function test_a_contact_already_in_the_list_needs_no_write(): void
    {
        $key = $this->lead();
        $this->on('GET', $this->path(), self::json(200, ['email' => self::EMAIL, 'listIds' => [14]]));

        BrevoSync::run($key);

        $this->assertSame(['GET ' . $this->path()], $this->paths());
        $this->assertSame('synced', BrevoSync::status(self::EMAIL)['state']);
    }

    public function test_already_in_list_400_on_add_is_settled_by_brevos_own_record(): void
    {
        $key = $this->lead();
        $this->on('GET', $this->path(), self::json(200, ['listIds' => [2]]), self::json(200, ['listIds' => [2, 14]]));
        $this->on('POST', '/contacts/lists/14/contacts/add', self::json(400, ['code' => 'invalid_parameter', 'message' => 'Contact already in list and/or does not exist']));

        BrevoSync::run($key);

        $this->assertSame('synced', BrevoSync::status(self::EMAIL)['state']);
    }

    public function test_a_contact_created_meanwhile_is_added_to_the_list_instead(): void
    {
        $key = $this->lead();
        $this->on('GET', $this->path(), self::json(404, []), self::json(200, ['listIds' => [14]]));
        $this->on('POST', '/contacts', self::json(400, ['code' => 'duplicate_parameter', 'message' => 'Contact already exist']));
        $this->on('POST', '/contacts/lists/14/contacts/add', self::json(201, ['contacts' => ['success' => [self::EMAIL]]]));

        BrevoSync::run($key);

        $this->assertSame('synced', BrevoSync::status(self::EMAIL)['state']);
    }

    public function test_success_is_never_recorded_without_list_membership_in_brevo(): void
    {
        $key = $this->lead();
        $this->on('GET', $this->path(), self::json(200, ['listIds' => [3]]), self::json(200, ['listIds' => [3]]));
        $this->on('POST', '/contacts/lists/14/contacts/add', self::json(201, ['contacts' => ['success' => [self::EMAIL]]]));

        BrevoSync::run($key);

        $status = BrevoSync::status(self::EMAIL);
        $this->assertSame('pending', $status['state']);
        $this->assertSame('not_confirmed', $status['code']);
        $this->assertNotFalse(wp_next_scheduled(BrevoSync::HOOK, [$key]));
    }

    public function test_a_synced_contact_is_not_sent_again(): void
    {
        $key = $this->lead();
        $this->on('GET', $this->path(), self::json(200, ['listIds' => [14]]));
        BrevoSync::run($key);
        BrevoSync::run($key);
        BrevoSync::queue($key);
        $this->assertCount(1, $this->calls);
    }

    public function test_a_run_already_in_progress_is_not_doubled(): void
    {
        $key = $this->lead();
        add_option(BrevoSync::LOCK_PREFIX . md5(self::EMAIL), (string) time(), '', 'no');
        BrevoSync::run($key);
        $this->assertSame([], $this->calls);
    }

    /* Permanent failures: no retry, visible to the admin */

    /** @dataProvider permanentFailures */
    public function test_permanent_failures_are_recorded_and_not_retried(array $script, string $code, int $http): void
    {
        $key = $this->lead();
        foreach ($script as [$method, $path, $answer]) {
            $this->on($method, $path === 'CONTACT' ? $this->path() : $path, $answer);
        }

        BrevoSync::run($key);

        $status = BrevoSync::status(self::EMAIL);
        $this->assertSame('failed', $status['state']);
        $this->assertSame($code, $status['code']);
        $this->assertSame($http, $status['http']);
        $this->assertFalse(wp_next_scheduled(BrevoSync::HOOK, [$key]));
        $this->assertSame($code, get_option(BrevoSync::FAILURE_OPTION)['code']);
    }

    public function permanentFailures(): array
    {
        return [
            'invalid API key' => [[['GET', 'CONTACT', self::json(401, ['code' => 'unauthorized', 'message' => 'Key not found'])]], 'invalid_key', 401],
            'IP not authorised' => [[['GET', 'CONTACT', self::json(401, ['code' => 'unauthorized', 'message' => 'We have detected you are using an unrecognised IP address 203.0.113.9.'])]], 'ip_not_authorised', 401],
            'invalid list, existing contact' => [[['GET', 'CONTACT', self::json(200, ['listIds' => []])], ['POST', '/contacts/lists/14/contacts/add', self::json(404, ['code' => 'document_not_found', 'message' => 'List ID does not exist'])]], 'invalid_list', 404],
            'invalid list, new contact' => [[['GET', 'CONTACT', self::json(404, [])], ['POST', '/contacts', self::json(400, ['code' => 'invalid_parameter', 'message' => 'listIds: list does not exist'])]], 'invalid_list', 400],
            'contact refused' => [[['GET', 'CONTACT', self::json(404, [])], ['POST', '/contacts', self::json(400, ['code' => 'invalid_parameter', 'message' => 'email is not valid'])]], 'rejected', 400],
            'forbidden' => [[['GET', 'CONTACT', self::json(403, ['code' => 'permission_denied', 'message' => 'Not allowed'])]], 'forbidden', 403],
        ];
    }

    /* Transient failures: retried with WP-Cron, then failed */

    public function test_timeout_is_retried_later(): void
    {
        $key = $this->lead();
        $this->on('GET', $this->path(), new WP_Error('http_request_failed', 'cURL error 28: Operation timed out after 10001 milliseconds'));

        BrevoSync::run($key);

        $status = BrevoSync::status(self::EMAIL);
        $this->assertSame(['pending', 'timeout', 1], [$status['state'], $status['code'], $status['attempts']]);
        $this->assertGreaterThanOrEqual(time() + 290, wp_next_scheduled(BrevoSync::HOOK, [$key]));
        $this->assertFalse(get_option(BrevoSync::FAILURE_OPTION));
    }

    public function test_rate_limit_waits_at_least_as_long_as_brevo_asks(): void
    {
        $key = $this->lead();
        $this->on('GET', $this->path(), self::json(429, ['code' => 'too_many_requests', 'message' => 'Too many requests'], ['retry-after' => '3600']));

        BrevoSync::run($key);

        $this->assertSame('rate_limited', BrevoSync::status(self::EMAIL)['code']);
        $this->assertGreaterThanOrEqual(time() + 3590, wp_next_scheduled(BrevoSync::HOOK, [$key]));
    }

    public function test_server_errors_fail_after_the_last_attempt(): void
    {
        $key = $this->lead();
        for ($i = 1; $i <= BrevoSync::MAX_ATTEMPTS; $i++) {
            $this->on('GET', $this->path(), self::json(503, ['message' => 'Service unavailable']));
            BrevoSync::run($key);
        }
        $status = BrevoSync::status(self::EMAIL);
        $this->assertSame(['failed', 'server_error', BrevoSync::MAX_ATTEMPTS], [$status['state'], $status['code'], $status['attempts']]);
        $this->assertCount(BrevoSync::MAX_ATTEMPTS, $this->calls);
    }

    public function test_a_later_success_clears_the_failure_notice(): void
    {
        update_option(BrevoSync::FAILURE_OPTION, ['at' => time(), 'code' => 'timeout', 'http' => 0]);
        $key = $this->lead();
        $this->on('GET', $this->path(), self::json(200, ['listIds' => [14]]));
        BrevoSync::run($key);
        $this->assertFalse(get_option(BrevoSync::FAILURE_OPTION));
    }

    /* Off unless configured */

    public function test_nothing_is_sent_without_a_list_id_or_a_key(): void
    {
        $key = $this->lead();
        delete_option(BrevoSync::LIST_OPTION);
        BrevoSync::queue($key);
        BrevoSync::run($key);
        update_option(BrevoSync::LIST_OPTION, '14');
        delete_option('seo_analyzer_brevo_api_key');
        BrevoSync::queue($key);
        BrevoSync::run($key);
        $this->assertSame([], $this->calls);
        $this->assertNull(BrevoSync::status(self::EMAIL));
    }

    /* The unlock and Brevo are independent */

    private function rest(string $method, string $route, array $params): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/' . RestController::NS . $route);
        $request->set_header('X-Halo-Nonce', wp_create_nonce(RestController::NONCE_ACTION));
        foreach ($params as $k => $v) {
            $request->set_param($k, $v);
        }
        return rest_get_server()->dispatch($request);
    }

    public function test_code_verification_stores_the_lead_and_only_queues_the_sync(): void
    {
        $id = $this->rest('POST', '/analyze', ['url' => 'https://example.com/page', 'keyword' => 'k'])->get_data()['report_id'];
        $this->rest('POST', '/gate/send', ['email' => self::EMAIL, 'report_id' => $id]);

        $verified = $this->rest('POST', '/gate/verify', ['email' => self::EMAIL, 'code' => $this->code, 'name' => 'Asha Rao', 'report_id' => $id]);

        $this->assertSame(200, $verified->get_status());
        $this->assertStringNotContainsStringIgnoringCase('brevo', wp_json_encode($verified->get_data()), 'The unlock answer says nothing about Brevo.');
        $this->assertSame(self::EMAIL, Plugin::store()->get($id)['email'], 'The lead is stored locally first.');
        $this->assertSame([], $this->calls, 'Brevo is not called while the visitor waits.');
        $this->assertSame('pending', BrevoSync::status(self::EMAIL)['state']);
        $this->assertNotFalse(wp_next_scheduled(BrevoSync::HOOK, [$id]), 'A backstop run is scheduled.');
        $this->assertNotFalse(has_action('shutdown', [BrevoSync::class, 'runQueued']));
    }

    public function test_a_failing_brevo_never_blocks_the_unlock_and_is_not_reported_as_success(): void
    {
        $id = $this->rest('POST', '/analyze', ['url' => 'https://example.com/page', 'keyword' => 'k'])->get_data()['report_id'];
        $this->rest('POST', '/gate/send', ['email' => self::EMAIL, 'report_id' => $id]);
        $verified = $this->rest('POST', '/gate/verify', ['email' => self::EMAIL, 'code' => $this->code, 'name' => 'Asha Rao', 'report_id' => $id]);
        $this->assertSame(200, $verified->get_status());

        $this->on('GET', $this->path(), self::json(401, ['code' => 'unauthorized', 'message' => 'Key not found']));
        BrevoSync::runQueued();

        $this->assertSame('failed', BrevoSync::status(self::EMAIL)['state']);
        $this->assertNotEmpty($verified->get_data()['checks'], 'The report was unlocked all the same.');
        $this->assertSame(self::EMAIL, Plugin::store()->get($id)['email']);
    }

    /* Nothing sensitive is kept */

    public function test_status_holds_no_key_no_email_and_no_brevo_body(): void
    {
        $key = $this->lead();
        $this->on('GET', $this->path(), self::json(401, ['code' => 'unauthorized', 'message' => 'Key not found ' . self::KEY]));
        BrevoSync::run($key);

        global $wpdb;
        $stored = $wpdb->get_col("SELECT CONCAT(option_name, '=', option_value) FROM {$wpdb->options} WHERE option_name LIKE 'halo\\_audit\\_brevo%'");
        $all = implode("\n", $stored);
        $this->assertNotEmpty($stored);
        $this->assertStringNotContainsString(self::KEY, $all);
        $this->assertStringNotContainsString('example.com', $all);
        $this->assertStringNotContainsString('Key not found', $all);
    }

    /* Admin */

    public function test_reports_screen_and_notice_show_the_sync_state(): void
    {
        $key = $this->lead();
        $this->on('GET', $this->path(), self::json(401, ['code' => 'unauthorized', 'message' => 'We have detected you are using an unrecognised IP address 1.2.3.4']));
        BrevoSync::run($key);

        require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
        require_once ABSPATH . 'wp-admin/includes/screen.php';
        require_once ABSPATH . 'wp-admin/includes/template.php';
        set_current_screen('toplevel_page_seo-analyzer');
        $table = new ReportsTable();
        $table->prepare_items();
        $row = array_values(array_filter($table->items, static fn($r) => $r['email'] === self::EMAIL))[0];
        $this->assertSame("Failed: Brevo blocked this server&#039;s IP address (Authorised IPs) (HTTP 401)", $table->column_default($row, 'brevo'));
        $this->assertStringContainsString('Authorised IPs', Admin::brevoNotice());

        delete_option(BrevoSync::LIST_OPTION);
        $this->assertSame('', Admin::brevoNotice(), 'No notice when the sync is switched off.');
    }

    public function test_list_id_setting_accepts_only_a_positive_whole_number(): void
    {
        $this->assertSame('14', \HaloAudit\Wp\Admin\Settings::sanitizeListId(' 14 '));
        foreach (['0', '-14', '14a', '1.5', '', '<b>14</b>'] as $bad) {
            $this->assertSame('', \HaloAudit\Wp\Admin\Settings::sanitizeListId($bad), $bad);
        }
    }

    /* Configuration: list ID 14 */

    public function test_list_id_14_saved_in_settings_is_the_list_every_request_uses(): void
    {
        update_option(BrevoSync::LIST_OPTION, \HaloAudit\Wp\Admin\Settings::sanitizeListId(' 14 '));
        $this->assertSame(14, BrevoSync::listId());
        $this->assertTrue(BrevoSync::enabled());

        $key = $this->lead();
        $this->on('GET', $this->path(), self::json(200, ['listIds' => [9]]), self::json(200, ['listIds' => [9, 14]]));
        $this->on('POST', '/contacts/lists/14/contacts/add', self::json(201, ['contacts' => ['success' => [self::EMAIL]]]));
        BrevoSync::run($key);

        $this->assertSame('synced', BrevoSync::status(self::EMAIL)['state']);
        $this->assertSame(['GET', 'POST', 'GET'], array_column($this->calls, 'method'), 'Read, add, confirm: no PUT, no DELETE.');
    }

    public function test_the_list_id_comes_from_settings_not_from_code(): void
    {
        update_option(BrevoSync::LIST_OPTION, '9');
        $key = $this->lead();
        // In list 14 but not in 9: with 9 configured, it must be added to 9 and confirmed against 9.
        $this->on('GET', $this->path(), self::json(200, ['listIds' => [14]]), self::json(200, ['listIds' => [14, 9]]));
        $this->on('POST', '/contacts/lists/9/contacts/add', self::json(201, []));
        BrevoSync::run($key);
        $this->assertSame(['GET ' . $this->path(), 'POST /contacts/lists/9/contacts/add', 'GET ' . $this->path()], $this->paths());
        $this->assertSame('synced', BrevoSync::status(self::EMAIL)['state']);
    }

    public function test_unset_list_id_means_off_in_the_reports_column(): void
    {
        delete_option(BrevoSync::LIST_OPTION);
        $this->assertSame(0, BrevoSync::listId());
        $this->assertFalse(BrevoSync::enabled());
        $this->assertSame('Off', BrevoSync::label(null));
        update_option(BrevoSync::LIST_OPTION, '14');
        $this->assertSame('Not synced', BrevoSync::label(null));
    }

    /* Existing contacts keep their data */

    public function test_an_unrelated_attribute_is_never_sent(): void
    {
        add_filter('halo_audit_brevo_attributes', static fn() => ['FIRSTNAME' => 'Overwrite']);
        $key = $this->lead();
        $this->on('GET', $this->path(), self::json(200, ['listIds' => [3], 'attributes' => ['FIRSTNAME' => 'Asha', 'COMPANY' => 'Acme']]), self::json(200, ['listIds' => [3, 14]]));
        $this->on('POST', '/contacts/lists/14/contacts/add', self::json(201, []));
        BrevoSync::run($key);

        $this->assertSame(['emails' => [self::EMAIL]], $this->calls[1]['body'], 'Only the email goes to the add-to-list call.');
        foreach ($this->calls as $call) {
            $this->assertNotContains($call['method'], ['PUT', 'DELETE']);
            $this->assertArrayNotHasKey('attributes', (array) $call['body']);
        }
    }

    /* Failed list assignment, timeouts and rate limits at each step */

    public function test_list_assignment_that_never_shows_up_fails_after_the_last_attempt(): void
    {
        $key = $this->lead();
        for ($i = 1; $i <= BrevoSync::MAX_ATTEMPTS; $i++) {
            $this->on('GET', $this->path(), self::json(200, ['listIds' => [3]]), self::json(200, ['listIds' => [3]]));
            $this->on('POST', '/contacts/lists/14/contacts/add', self::json(400, ['code' => 'invalid_parameter', 'message' => 'Contact already in list and/or does not exist']));
            BrevoSync::run($key);
            $status = BrevoSync::status(self::EMAIL);
            $this->assertNotSame('synced', $status['state'], "attempt $i");
        }
        $this->assertSame(['failed', 'not_confirmed', BrevoSync::MAX_ATTEMPTS], [$status['state'], $status['code'], $status['attempts']]);
        $this->assertSame('not_confirmed', get_option(BrevoSync::FAILURE_OPTION)['code']);
        $this->assertStringStartsWith('Failed: Brevo did not confirm the list membership', BrevoSync::label($status));
    }

    public function test_confirmation_not_readable_yet_after_create_is_retried_not_failed(): void
    {
        $key = $this->lead();
        $this->on('GET', $this->path(), self::json(404, []), self::json(404, ['code' => 'document_not_found']));
        $this->on('POST', '/contacts', self::json(201, ['id' => 9]));
        BrevoSync::run($key);

        $status = BrevoSync::status(self::EMAIL);
        $this->assertSame(['pending', 'not_confirmed'], [$status['state'], $status['code']]);
        $this->assertNotFalse(wp_next_scheduled(BrevoSync::HOOK, [$key]));
        $this->assertFalse(get_option(BrevoSync::FAILURE_OPTION));
    }

    public function test_timeout_on_the_add_step_is_retried(): void
    {
        $key = $this->lead();
        $this->on('GET', $this->path(), self::json(200, ['listIds' => []]));
        $this->on('POST', '/contacts/lists/14/contacts/add', new WP_Error('http_request_failed', 'cURL error 28: Operation timed out after 10000 milliseconds'));
        BrevoSync::run($key);
        $status = BrevoSync::status(self::EMAIL);
        $this->assertSame(['pending', 'timeout'], [$status['state'], $status['code']]);
        $this->assertSame('Retrying (Brevo did not answer in time)', BrevoSync::label($status));
    }

    public function test_rate_limit_on_create_is_retried_after_brevos_wait(): void
    {
        $key = $this->lead();
        $this->on('GET', $this->path(), self::json(404, []));
        $this->on('POST', '/contacts', self::json(429, ['message' => 'Too many requests'], ['retry-after' => '900']));
        BrevoSync::run($key);
        $status = BrevoSync::status(self::EMAIL);
        $this->assertSame(['pending', 'rate_limited'], [$status['state'], $status['code']]);
        $this->assertGreaterThanOrEqual(time() + 890, wp_next_scheduled(BrevoSync::HOOK, [$key]));
    }

    public function test_invalid_key_on_the_add_step_fails_without_retry(): void
    {
        $key = $this->lead();
        $this->on('GET', $this->path(), self::json(200, ['listIds' => []]));
        $this->on('POST', '/contacts/lists/14/contacts/add', self::json(401, ['code' => 'unauthorized', 'message' => 'Key not found']));
        BrevoSync::run($key);
        $status = BrevoSync::status(self::EMAIL);
        $this->assertSame(['failed', 'invalid_key', 401], [$status['state'], $status['code'], $status['http']]);
        $this->assertFalse(wp_next_scheduled(BrevoSync::HOOK, [$key]));
    }

    /* The Reports column after a new confirmation */

    public function test_confirming_again_after_a_failure_starts_fresh_and_reads_pending(): void
    {
        $key = $this->lead();
        for ($i = 1; $i <= BrevoSync::MAX_ATTEMPTS; $i++) {
            $this->on('GET', $this->path(), self::json(503, []));
            BrevoSync::run($key);
        }
        $this->assertSame('failed', BrevoSync::status(self::EMAIL)['state']);

        BrevoSync::queue($key);
        $status = BrevoSync::status(self::EMAIL);
        $this->assertSame(['pending', '', 0], [$status['state'], $status['code'], $status['attempts']]);
        $this->assertSame('Pending', BrevoSync::label($status));

        $this->on('GET', $this->path(), self::json(503, []));
        BrevoSync::run($key);
        $status = BrevoSync::status(self::EMAIL);
        $this->assertSame(['pending', 1], [$status['state'], $status['attempts']], 'A transient error is retried again, not failed at once.');
    }

    public function test_reports_column_tells_pending_retrying_failed_and_in_list_apart(): void
    {
        $this->assertSame('Pending', BrevoSync::label(['state' => 'pending', 'code' => '', 'http' => 0, 'attempts' => 0, 'at' => 1]));
        $this->assertSame('Retrying (Brevo rate limit)', BrevoSync::label(['state' => 'pending', 'code' => 'rate_limited', 'http' => 429, 'attempts' => 1, 'at' => 1]));
        $this->assertSame('Failed: the list ID does not exist in Brevo (HTTP 404)', BrevoSync::label(['state' => 'failed', 'code' => 'invalid_list', 'http' => 404, 'attempts' => 1, 'at' => 1]));
        $this->assertSame('In list', BrevoSync::label(['state' => 'synced', 'code' => '', 'http' => 200, 'attempts' => 1, 'at' => 1]));
    }
}
