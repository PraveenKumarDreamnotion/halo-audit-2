<?php
use HaloAudit\CertProbe;
use HaloAudit\Wp\Admin\Admin;
use HaloAudit\Wp\BrevoSync;
use HaloAudit\Wp\Plugin;
use HaloAudit\Wp\RestController;
use HaloAudit\Wp\Shortcode;

/**
 * Brevo contact attributes NAME, KEYWORD, URL, COMPETITOR_URL and FORM_NAME, through the real flow: the shortcode
 * renders the form on a real page, /analyze stores the report, /gate/send and /gate/verify confirm the email, and
 * the sync runs. URL is the audited page; FORM_NAME is the page the form was submitted on.
 * Every Brevo answer is scripted; an unscripted Brevo request fails the test.
 */
class Halo_Brevo_Attributes_Test extends WP_UnitTestCase
{
    private const KEY = 'xkeysib-FAKE-attr-test-key-0000000000';
    private const EMAIL = 'asha.attr@example.com';
    private const MINE_TYPED = 'Mine.example.com/mba';
    private const MINE_SUBMITTED = 'https://mine.example.com/mba';
    private const MINE_FINAL = 'https://mine.example.com/mba/';
    private const THEIRS = 'https://rival.example.org/mba';

    private array $calls = [];
    private array $script = [];
    private array $unscripted = [];
    private string $code = '';
    private string $log = '';
    /** @var array<string,int> 'seo' and 'competitor' => page ID, as on the site: /ai-tools/seo-analysis/ and /ai-tools/competitor-analysis/ */
    private array $pages = [];

    public function set_up(): void
    {
        parent::set_up();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action('rest_api_init');
        update_option('seo_analyzer_brevo_api_key', self::KEY);
        update_option(BrevoSync::LIST_OPTION, '14');
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
        add_filter('halo_audit_dns_resolver', static fn() => static fn(string $host): array => ['93.184.216.34']);
        add_filter('halo_audit_cert_probe', static fn() => new class implements CertProbe {
            public function daysLeft(string $host): ?int { return 90; }
        });
        add_filter('halo_audit_send_code', function ($sent, $email, $code) {
            $this->code = $code;
            return true;
        }, 10, 3);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand(60, 250);
        // Everything PHP logs during the test goes to this file, which is checked for secrets and personal data.
        $this->log = (string) wp_tempnam('halo-brevo-log');
        ini_set('error_log', $this->log);

        $this->set_permalink_structure('/%postname%/');
        $parent = self::factory()->post->create(['post_type' => 'page', 'post_name' => 'ai-tools', 'post_title' => 'AI tools']);
        $this->pages['seo'] = self::factory()->post->create(['post_type' => 'page', 'post_parent' => $parent, 'post_name' => 'seo-analysis', 'post_title' => 'SEO Analysis',
            'post_content' => '[seo_analyzer title="Halo Audit" popup="false" nlp="false"]']);
        $this->pages['competitor'] = self::factory()->post->create(['post_type' => 'page', 'post_parent' => $parent, 'post_name' => 'competitor-analysis', 'post_title' => 'Competitor Analysis',
            'post_content' => '[seo_analyzer title="Halo Competitor Analysis" popup="false" comparison="true" nlp="false" competitor="true"]']);
    }

    private static function seoPage(): string
    {
        return home_url('/ai-tools/seo-analysis/');
    }

    private static function competitorPage(): string
    {
        return home_url('/ai-tools/competitor-analysis/');
    }

    /** Opens the page as a visitor would and returns the signed page value its form carries. */
    private function formPage(string $which): string
    {
        $this->go_to(get_permalink($this->pages[$which]));
        $html = do_shortcode((string) get_post($this->pages[$which])->post_content);
        preg_match('/data-form-page="([^"]+)"/', $html, $m);
        $this->assertNotEmpty($m, 'The form on a page carries its page value.');
        return html_entity_decode($m[1]);
    }

    public function tear_down(): void
    {
        $this->assertSame([], $this->unscripted, 'A Brevo request was not scripted by the test.');
        wp_unschedule_hook(BrevoSync::HOOK);
        ini_restore('error_log');
        @unlink($this->log);
        // The pages here use pretty permalinks; the script registered meanwhile holds that REST address.
        $this->set_permalink_structure('');
        wp_deregister_script('halo-audit');
        wp_deregister_style('halo-audit');
        parent::tear_down();
    }

    private function on(string $method, string $path, ...$answers): void
    {
        foreach ($answers as $answer) {
            $this->script[$method . ' ' . $path][] = $answer;
        }
    }

    private static function json(int $status, array $body = []): array
    {
        return ['headers' => [], 'body' => $body ? json_encode($body) : '', 'response' => ['code' => $status, 'message' => ''], 'cookies' => []];
    }

    public function fake_http($pre, $args, $url)
    {
        if (strpos($url, 'https://api.brevo.com/v3') === 0) {
            $path = substr($url, strlen('https://api.brevo.com/v3'));
            $method = $args['method'] ?? 'GET';
            $this->calls[] = ['method' => $method, 'path' => $path, 'body' => isset($args['body']) ? json_decode($args['body'], true) : null];
            $queue = &$this->script[$method . ' ' . $path];
            if (empty($queue)) {
                $this->unscripted[] = $method . ' ' . $path;
                return new WP_Error('http_request_failed', 'unscripted');
            }
            return array_shift($queue);
        }
        $page = ['headers' => ['content-type' => 'text/html'], 'body' => '<html><head><title>MBA</title></head><body><h1>MBA</h1></body></html>', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
        if ($url === self::MINE_SUBMITTED) {
            return ['headers' => ['location' => self::MINE_FINAL], 'body' => '', 'response' => ['code' => 301, 'message' => ''], 'cookies' => []];
        }
        if ($url === self::MINE_FINAL || $url === self::THEIRS) {
            return $page;
        }
        return ['headers' => [], 'body' => '', 'response' => ['code' => 404, 'message' => 'Not Found'], 'cookies' => []];
    }

    private function rest(string $method, string $route, array $params): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/' . RestController::NS . $route);
        $request->set_header('X-Halo-Nonce', wp_create_nonce(RestController::NONCE_ACTION));
        foreach ($params as $k => $v) {
            $request->set_param($k, $v);
        }
        return rest_get_server()->dispatch($request);
    }

    /** Audit (optionally a comparison), then confirm the email as the visitor would. Returns the report key. */
    private function auditAndVerify(bool $compare, string $form, string $keyword = '  MBA   online ', array $verifyExtra = []): string
    {
        $params = ['url' => self::MINE_TYPED, 'keyword' => $keyword] + ($compare ? ['competitor_url' => self::THEIRS] : []) + ($form !== '' ? ['form_page' => $form] : []);
        $analyzed = $this->rest('POST', '/analyze', $params);
        $this->assertSame(200, $analyzed->get_status());
        $id = $analyzed->get_data()['report_id'];
        $this->rest('POST', '/gate/send', ['email' => self::EMAIL, 'report_id' => $id]);
        $verified = $this->rest('POST', '/gate/verify', ['email' => self::EMAIL, 'code' => $this->code, 'name' => '  Asha <b>Rao</b> ', 'report_id' => $id] + $verifyExtra);
        $this->assertSame(200, $verified->get_status());
        $this->assertSame([], $this->calls, 'Nothing goes to Brevo while the visitor waits.');
        return $id;
    }

    /** Brevo's record of the contact after the write: in list 14, holding these attributes. */
    private static function stored(array $attributes, array $listIds = [14]): array
    {
        return self::json(200, ['email' => self::EMAIL, 'listIds' => $listIds, 'attributes' => $attributes]);
    }

    private function five(): array
    {
        return ['NAME' => 'Asha Rao', 'KEYWORD' => 'MBA online', 'URL' => self::MINE_SUBMITTED, 'COMPETITOR_URL' => self::THEIRS, 'FORM_NAME' => self::competitorPage()];
    }

    private function path(): string
    {
        return '/contacts/' . rawurlencode(self::EMAIL);
    }

    private function bodies(string $method, string $path): array
    {
        return array_values(array_map(static fn($c) => $c['body'], array_filter($this->calls, static fn($c) => $c['method'] === $method && $c['path'] === $path)));
    }

    /* 1, 4, 5: a new contact gets all five, from the stored submission */

    public function test_new_contact_from_a_comparison_gets_all_five_attributes(): void
    {
        $this->auditAndVerify(true, $this->formPage('competitor'));
        $this->on('GET', $this->path(), self::json(404, ['code' => 'document_not_found']), self::stored($this->five()));
        $this->on('POST', '/contacts', self::json(201, ['id' => 7]));
        BrevoSync::runQueued();

        $this->assertSame([[
            'email' => self::EMAIL,
            'listIds' => [14],
            'attributes' => [
                'NAME' => 'Asha Rao',                          // the name from the code form, as stored (tags stripped)
                'KEYWORD' => 'MBA online',                     // the keyword the report was made for
                'URL' => self::MINE_SUBMITTED,                 // the audited page as typed, normalised; not the redirect target
                'COMPETITOR_URL' => self::THEIRS,
                'FORM_NAME' => self::competitorPage(),         // the page the form was submitted on
            ],
        ]], $this->bodies('POST', '/contacts'));
        $status = BrevoSync::status(self::EMAIL);
        $this->assertSame(['synced', 'saved', [], 'ok'], [$status['state'], $status['attrs'], $status['missing'], $status['page']]);
        $this->assertSame('In list', BrevoSync::label($status));
        $this->assertFalse(get_option(BrevoSync::ATTRIBUTE_OPTION));
        $this->assertSame('14', get_option(BrevoSync::LIST_OPTION), 'The list ID is not changed.');
    }

    /* 3: single page has no COMPETITOR_URL */

    public function test_single_page_audit_sends_no_competitor_url(): void
    {
        $this->auditAndVerify(false, $this->formPage('seo'));
        $this->on('GET', $this->path(), self::json(404, []), self::json(200, ['listIds' => [14]]));
        $this->on('POST', '/contacts', self::json(201, []));
        BrevoSync::runQueued();

        $attributes = $this->bodies('POST', '/contacts')[0]['attributes'];
        $this->assertSame(['NAME', 'KEYWORD', 'URL', 'FORM_NAME'], array_keys($attributes));
        $this->assertSame(self::seoPage(), $attributes['FORM_NAME']);
        $this->assertSame(self::MINE_SUBMITTED, $attributes['URL'], 'URL stays the audited page.');
    }

    /* 2, 6: an existing contact gets only these attributes, and an empty one never blanks a value */

    public function test_existing_contact_gets_the_five_attributes_and_nothing_else(): void
    {
        $this->auditAndVerify(true, $this->formPage('competitor'));
        $this->on('GET', $this->path(), self::json(200, ['listIds' => [3, 9], 'attributes' => ['NAME' => 'Old name', 'FIRSTNAME' => 'Keep', 'KEYWORD' => 'old']]),
            self::stored($this->five() + ['FIRSTNAME' => 'Keep'], [3, 9, 14]));
        $this->on('PUT', $this->path(), self::json(204));
        $this->on('POST', '/contacts/lists/14/contacts/add', self::json(201, []));
        BrevoSync::runQueued();

        $this->assertSame(['GET ' . $this->path(), 'PUT ' . $this->path(), 'POST /contacts/lists/14/contacts/add', 'GET ' . $this->path()], array_map(static fn($c) => $c['method'] . ' ' . $c['path'], $this->calls));
        $this->assertSame([['attributes' => ['NAME' => 'Asha Rao', 'KEYWORD' => 'MBA online', 'URL' => self::MINE_SUBMITTED, 'COMPETITOR_URL' => self::THEIRS, 'FORM_NAME' => self::competitorPage()]]],
            $this->bodies('PUT', $this->path()), 'Only the attributes object: no listIds, unlinkListIds, emailBlacklisted or other fields.');
        $this->assertSame([['emails' => [self::EMAIL]]], $this->bodies('POST', '/contacts/lists/14/contacts/add'));
        $this->assertSame(['state' => 'synced', 'attrs' => 'saved'], array_intersect_key(BrevoSync::status(self::EMAIL), ['state' => 1, 'attrs' => 1]));
    }

    public function test_absent_optional_values_are_left_out_so_existing_ones_stay(): void
    {
        // Single page and no page value (a form off a single page, or a page cached before this version).
        $this->auditAndVerify(false, '');
        $kept = ['COMPETITOR_URL' => 'https://kept.example', 'FORM_NAME' => 'Kept form'];
        $this->on('GET', $this->path(), self::json(200, ['listIds' => [14], 'attributes' => $kept]),
            self::stored(['NAME' => 'Asha Rao', 'KEYWORD' => 'MBA online', 'URL' => self::MINE_SUBMITTED] + $kept));
        $this->on('PUT', $this->path(), self::json(204));
        BrevoSync::runQueued();

        $sent = $this->bodies('PUT', $this->path())[0]['attributes'];
        $this->assertSame(['NAME', 'KEYWORD', 'URL'], array_keys($sent), 'No COMPETITOR_URL or FORM_NAME key at all, not even an empty one.');
        $this->assertNotContains('', $sent);
        $this->assertSame(self::MINE_SUBMITTED, $sent['URL'], 'The audited address is not reused as FORM_NAME.');

        // Not a silent success: the admin sees why FORM_NAME was not sent.
        $status = BrevoSync::status(self::EMAIL);
        $this->assertSame(['synced', 'saved', 'missing'], [$status['state'], $status['attrs'], $status['page']]);
        $this->assertSame('In list (FORM_NAME not sent: the form page was not known)', BrevoSync::label($status));
        $this->assertStringContainsString('clear the page cache and minified files', Admin::brevoAttributeNotice());
    }

    /* 5: values come from the stored report and the verified lead, not from the request */

    public function test_values_the_visitor_sends_are_not_trusted(): void
    {
        $forged = rtrim(strtr(base64_encode('https://evil.example/landing/'), '+/', '-_'), '=') . '.' . str_repeat('a', 32);
        $this->auditAndVerify(false, $forged, 'mba', ['keyword' => 'injected', 'url' => 'https://evil.example', 'competitor_url' => 'https://evil.example', 'form_page' => $this->formPage('seo')]);
        $this->on('GET', $this->path(), self::json(404, []), self::json(200, ['listIds' => [14]]));
        $this->on('POST', '/contacts', self::json(201, []));
        BrevoSync::runQueued();

        $this->assertSame(['NAME' => 'Asha Rao', 'KEYWORD' => 'mba', 'URL' => self::MINE_SUBMITTED], $this->bodies('POST', '/contacts')[0]['attributes'],
            'A forged page value is dropped (FORM_NAME left out, not guessed), and fields sent with the code are ignored.');
        $this->assertSame('invalid', BrevoSync::status(self::EMAIL)['page']);
        $this->assertStringContainsString('FORM_NAME not sent: the form page value failed its check', BrevoSync::label(BrevoSync::status(self::EMAIL)));
    }

    public function test_page_value_round_trips_and_rejects_anything_unsigned_or_off_site(): void
    {
        $token = $this->formPage('seo');
        $this->assertSame(self::seoPage(), Shortcode::pageUrl($token));
        $this->assertSame(self::competitorPage(), Shortcode::pageUrl($this->formPage('competitor')));

        [$payload, $sig] = explode('.', $token);
        $other = rtrim(strtr(base64_encode(self::competitorPage()), '+/', '-_'), '=');
        foreach ([$other . '.' . $sig, $payload . '.' . strrev($sig), '', 'x', $payload, self::seoPage()] as $bad) {
            $this->assertSame('', Shortcode::pageUrl($bad), $bad);
        }
        $this->assertSame('', Shortcode::pageUrl(Shortcode::pageToken('https://evil.example/ai-tools/seo-analysis/')), 'Signed or not, another host is refused.');
        $this->assertSame('', Shortcode::pageUrl(Shortcode::pageToken('javascript:alert(1)')));
    }

    public function test_the_same_shortcode_on_two_pages_reports_each_page(): void
    {
        $post = self::factory()->post->create(['post_name' => 'false-hope', 'post_content' => '[seo_analyzer] [seo_analyzer title="Competitor Analyzer" comparison="true"]']);
        $this->go_to(get_permalink($post));
        preg_match_all('/data-form-page="([^"]+)"/', do_shortcode((string) get_post($post)->post_content), $m);
        $this->assertCount(2, $m[1]);
        foreach ($m[1] as $token) {
            $this->assertSame(get_permalink($post), Shortcode::pageUrl(html_entity_decode($token)));
        }
        $this->assertNotSame(self::seoPage(), get_permalink($post));
    }

    public function test_off_a_single_page_the_form_has_no_page_value(): void
    {
        $this->go_to(home_url('/?s=mba'));
        $this->assertStringNotContainsString('data-form-page', do_shortcode('[seo_analyzer]'));
        $this->go_to('/');
        $this->assertSame('', Shortcode::currentPageUrl());
    }

    /* A later verified submission updates the attributes again; the same one does not */

    public function test_a_new_verified_submission_updates_a_synced_contact(): void
    {
        $first = $this->auditAndVerify(false, $this->formPage('seo'), 'mba');
        $this->on('GET', $this->path(), self::json(404, []), self::json(200, ['listIds' => [14]]));
        $this->on('POST', '/contacts', self::json(201, []));
        BrevoSync::runQueued();
        BrevoSync::run($first);
        $this->assertCount(3, $this->calls, 'The same report is not sent twice.');

        $this->calls = [];
        $this->auditAndVerify(true, $this->formPage('competitor'), 'online mba');
        $this->on('GET', $this->path(), self::json(200, ['listIds' => [14]]), self::stored(['KEYWORD' => 'online mba'] + $this->five()));
        $this->on('PUT', $this->path(), self::json(204));
        BrevoSync::runQueued();
        $this->assertSame('online mba', $this->bodies('PUT', $this->path())[0]['attributes']['KEYWORD']);
        $this->assertSame(self::competitorPage(), $this->bodies('PUT', $this->path())[0]['attributes']['FORM_NAME']);
    }

    /* 7: no sync without a verified code */

    public function test_failed_code_verification_triggers_no_sync(): void
    {
        $id = $this->rest('POST', '/analyze', ['url' => self::MINE_TYPED, 'keyword' => 'mba'])->get_data()['report_id'];
        $this->rest('POST', '/gate/send', ['email' => self::EMAIL, 'report_id' => $id]);
        $wrong = $this->code === '000000' ? '111111' : '000000';
        $verified = $this->rest('POST', '/gate/verify', ['email' => self::EMAIL, 'code' => $wrong, 'name' => 'Asha', 'report_id' => $id]);

        $this->assertNotSame(200, $verified->get_status());
        $this->assertNull(BrevoSync::status(self::EMAIL));
        $this->assertFalse(wp_next_scheduled(BrevoSync::HOOK, [$id]));
        BrevoSync::runQueued();
        BrevoSync::run($id);
        $this->assertSame([], $this->calls);
    }

    /* 8: Brevo problems never cost the visitor the report, and attributes never cost the list membership */

    public function test_brevo_failure_keeps_the_lead_and_the_unlock(): void
    {
        $id = $this->auditAndVerify(true, $this->formPage('competitor'));
        $this->on('GET', $this->path(), self::json(200, ['listIds' => []]));
        $this->on('PUT', $this->path(), self::json(500, ['message' => 'Internal error']));
        BrevoSync::runQueued();

        $this->assertSame(['pending', 'server_error'], [BrevoSync::status(self::EMAIL)['state'], BrevoSync::status(self::EMAIL)['code']], 'Retried later.');
        $row = Plugin::store()->get($id);
        $this->assertSame([self::EMAIL, 'Asha Rao'], [$row['email'], $row['name']]);
        $this->assertNotNull($row['lead_verified_at']);
        $_COOKIE[\HaloAudit\Wp\Gate::COOKIE] = Plugin::gate()->token('Asha Rao', self::EMAIL, time() + 60);
        $this->assertSame(200, $this->rest('GET', '/report/' . $id, [])->get_status());
        unset($_COOKIE[\HaloAudit\Wp\Gate::COOKIE]);
    }

    public function test_attributes_refused_for_an_existing_contact_still_add_it_to_the_list(): void
    {
        $this->auditAndVerify(false, $this->formPage('seo'));
        $this->on('GET', $this->path(), self::json(200, ['listIds' => []]), self::json(200, ['listIds' => [14]]));
        $this->on('PUT', $this->path(), self::json(400, ['code' => 'invalid_parameter', 'message' => 'Attribute FORM_NAME does not exist']));
        $this->on('POST', '/contacts/lists/14/contacts/add', self::json(201, []));
        BrevoSync::runQueued();

        $status = BrevoSync::status(self::EMAIL);
        $this->assertSame(['synced', 'rejected'], [$status['state'], $status['attrs']]);
        $this->assertSame('In list (Brevo did not save NAME, KEYWORD, URL, FORM_NAME)', BrevoSync::label($status));
        $this->assertStringContainsString('Brevo did not save NAME, KEYWORD, URL, FORM_NAME', Admin::brevoAttributeNotice());
    }

    /* FORM_NAME accepted by the API but not kept by Brevo: reported, never a silent success */

    /** @dataProvider notKept */
    public function test_form_name_that_brevo_does_not_keep_is_reported_by_name(bool $existing, array $readBack): void
    {
        $this->auditAndVerify(true, $this->formPage('competitor'));
        if ($existing) {
            $this->on('GET', $this->path(), self::json(200, ['listIds' => [14]]), self::stored($readBack));
            $this->on('PUT', $this->path(), self::json(204));
        } else {
            $this->on('GET', $this->path(), self::json(404, []), self::stored($readBack));
            $this->on('POST', '/contacts', self::json(201, []));
        }
        BrevoSync::runQueued();

        $sent = $existing ? $this->bodies('PUT', $this->path())[0]['attributes'] : $this->bodies('POST', '/contacts')[0]['attributes'];
        $this->assertSame(self::competitorPage(), $sent['FORM_NAME'], 'The request did carry FORM_NAME.');
        $status = BrevoSync::status(self::EMAIL);
        $this->assertSame(['synced', 'partial', ['FORM_NAME']], [$status['state'], $status['attrs'], $status['missing']], 'The list membership counts; the attribute does not.');
        $this->assertSame('In list (Brevo did not save FORM_NAME)', BrevoSync::label($status));
        $notice = Admin::brevoAttributeNotice();
        $this->assertStringContainsString('Brevo did not save FORM_NAME', $notice);
        $this->assertStringContainsString('type Text', $notice);
        $this->assertStringNotContainsString('competitor-analysis', $notice, 'Names only, no values.');
    }

    public function notKept(): array
    {
        $four = ['NAME' => 'Asha Rao', 'KEYWORD' => 'MBA online', 'URL' => 'https://mine.example.com/mba', 'COMPETITOR_URL' => 'https://rival.example.org/mba'];
        return [
            'existing contact, attribute not defined (dropped)' => [true, $four],
            'new contact, attribute not defined (dropped)' => [false, $four],
            'existing contact, another value kept' => [true, $four + ['FORM_NAME' => 'contact page form']],
        ];
    }

    public function test_a_later_complete_sync_clears_the_attribute_notice(): void
    {
        update_option(BrevoSync::ATTRIBUTE_OPTION, ['at' => time(), 'missing' => ['FORM_NAME'], 'page' => 'ok']);
        $this->auditAndVerify(true, $this->formPage('competitor'));
        $this->on('GET', $this->path(), self::json(200, ['listIds' => [14]]), self::stored($this->five()));
        $this->on('PUT', $this->path(), self::json(204));
        BrevoSync::runQueued();
        $this->assertFalse(get_option(BrevoSync::ATTRIBUTE_OPTION));
        $this->assertSame('', Admin::brevoAttributeNotice());
    }

    public function test_attributes_refused_for_a_new_contact_create_it_without_them(): void
    {
        $this->auditAndVerify(false, $this->formPage('seo'));
        $this->on('GET', $this->path(), self::json(404, []), self::json(200, ['listIds' => [14]]));
        $this->on('POST', '/contacts', self::json(400, ['code' => 'invalid_parameter', 'message' => 'attributes: KEYWORD is not a valid attribute']), self::json(201, []));
        BrevoSync::runQueued();

        $bodies = $this->bodies('POST', '/contacts');
        $this->assertArrayHasKey('attributes', $bodies[0]);
        $this->assertSame(['email' => self::EMAIL, 'listIds' => [14]], $bodies[1]);
        $this->assertSame(['synced', 'rejected'], [BrevoSync::status(self::EMAIL)['state'], BrevoSync::status(self::EMAIL)['attrs']]);
        $this->assertSame(['NAME', 'KEYWORD', 'URL', 'FORM_NAME'], BrevoSync::status(self::EMAIL)['missing']);
    }

    /* 9: no key, OTP or personal data is logged or stored */

    public function test_nothing_sensitive_is_logged_or_stored(): void
    {
        $this->auditAndVerify(true, $this->formPage('competitor'));
        $this->on('GET', $this->path(), self::json(200, ['listIds' => []]));
        $this->on('PUT', $this->path(), self::json(401, ['code' => 'unauthorized', 'message' => 'Key not found ' . self::KEY]));
        BrevoSync::runQueued();
        $this->assertSame('failed', BrevoSync::status(self::EMAIL)['state']);

        global $wpdb;
        $stored = implode("\n", $wpdb->get_col("SELECT CONCAT(option_name, '=', option_value) FROM {$wpdb->options} WHERE option_name LIKE 'halo\\_audit\\_brevo%'"));
        $logged = (string) @file_get_contents($this->log);
        foreach ([self::KEY, self::EMAIL, 'Asha', 'MBA online', 'mine.example.com', 'rival.example.org', 'competitor-analysis', $this->code, 'Key not found'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored, 'stored: ' . $secret);
            $this->assertStringNotContainsString($secret, $logged, 'logged: ' . $secret);
        }
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/engine/Wp/BrevoSync.php');
        $this->assertDoesNotMatchRegularExpression('/\b(error_log|print_r|var_dump|var_export|trigger_error)\s*\(/', $source);
    }
}
