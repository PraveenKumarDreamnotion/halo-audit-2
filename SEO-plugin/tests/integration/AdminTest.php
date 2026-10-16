<?php
use HaloAudit\Wp\Admin\Admin;
use HaloAudit\Wp\Admin\ReportsTable;
use HaloAudit\Wp\Admin\Settings;
use HaloAudit\Wp\Installer;

class Halo_Admin_Test extends WP_UnitTestCase
{
    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
        require_once ABSPATH . 'wp-admin/includes/screen.php';
        require_once ABSPATH . 'wp-admin/includes/template.php';
    }

    public function tear_down(): void
    {
        unset($_REQUEST['_wpnonce']);
        parent::tear_down();
    }

    private function seed(int $n): void
    {
        global $wpdb;
        for ($i = 1; $i <= $n; $i++) {
            $wpdb->insert(Installer::resultsTable(), [
                'report_key' => md5('k' . $i), 'name' => 'Lead ' . $i, 'email' => "lead$i@example.com", 'url' => "https://example.com/$i",
                'keyword' => 'k', 'results' => '{}', 'overall_score' => 50, 'grade' => 'C', 'lead_verified_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }
    }

    public function test_export_is_refused_for_logged_out_visitors(): void
    {
        wp_set_current_user(0);
        $this->expectException(WPDieException::class);
        Admin::export();
    }

    public function test_export_is_refused_for_subscribers(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $_REQUEST['_wpnonce'] = wp_create_nonce('halo_audit_export');
        $this->expectException(WPDieException::class);
        Admin::export();
    }

    public function test_export_is_refused_for_admin_without_nonce(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->expectException(WPDieException::class);
        Admin::export();
    }

    public function test_export_rows_hold_only_verified_leads(): void
    {
        global $wpdb;
        $this->seed(2);
        $wpdb->insert(Installer::resultsTable(), ['report_key' => md5('anon'), 'url' => 'https://anon.example/', 'keyword' => 'k', 'results' => '{}']);
        $rows = Admin::exportRows();
        $this->assertCount(2, $rows);
        $this->assertSame(['name', 'email', 'url', 'keyword', 'overall_score', 'grade', 'is_education', 'created_at', 'competitor_url', 'competitor_overall_score'], array_keys($rows[0]));
    }

    public function test_reports_and_export_show_the_competitor_of_a_comparison(): void
    {
        global $wpdb;
        $this->seed(1);
        $wpdb->insert(Installer::resultsTable(), [
            'report_key' => md5('cmp'), 'name' => 'Asha', 'email' => 'asha@example.com', 'url' => 'https://example.com/mine', 'keyword' => 'k', 'results' => '{}',
            'overall_score' => 62, 'grade' => 'C', 'lead_verified_at' => gmdate('Y-m-d H:i:s'),
            'competitor_url' => 'https://rival.example/<b>x</b>', 'competitor_results' => '{}', 'competitor_overall_score' => 73.6,
        ]);

        $rows = Admin::exportRows();
        $this->assertSame('https://rival.example/<b>x</b>', $rows[0]['competitor_url']);
        $this->assertNull($rows[1]['competitor_url'], 'A single-page audit has no competitor.');

        set_current_screen('toplevel_page_seo-analyzer');
        $table = new ReportsTable();
        $table->prepare_items();
        $this->assertArrayHasKey('competitor_url', $table->get_columns());
        $this->assertSame('https://rival.example/&lt;b&gt;x&lt;/b&gt; (74)', $table->column_default($table->items[0], 'competitor_url'));
        $this->assertSame('', $table->column_default($table->items[1], 'competitor_url'));
    }

    public function test_csv_cells_cannot_start_a_formula(): void
    {
        $this->assertSame("'=HYPERLINK(\"http://evil\")", Admin::csvCell('=HYPERLINK("http://evil")'));
        $this->assertSame("'+1", Admin::csvCell('+1'));
        $this->assertSame("'-1", Admin::csvCell('-1'));
        $this->assertSame("'@x", Admin::csvCell('@x'));
        $this->assertSame('Asha', Admin::csvCell('Asha'));
    }

    public function test_reports_table_is_paginated(): void
    {
        $this->seed(25);
        set_current_screen('toplevel_page_seo-analyzer');
        $table = new ReportsTable();
        $table->prepare_items();
        $this->assertCount(20, $table->items);
        $this->assertSame(25, $table->get_pagination_arg('total_items'));
    }

    public function test_settings_are_registered_with_sanitisers(): void
    {
        Settings::register();
        $registered = get_registered_settings();
        foreach (['halo_audit_psi_key', 'seo_analyzer_brevo_api_key', 'seo_analyzer_sender_email', 'halo_audit_scan_url', 'halo_audit_scan_secret'] as $option) {
            $this->assertArrayHasKey($option, $registered, $option);
            $this->assertSame(Settings::GROUP, $registered[$option]['group']);
        }
        $this->assertSame('', sanitize_option('halo_audit_scan_url', 'javascript:alert(1)'));
        $this->assertSame('https://scan.example.com/', sanitize_option('halo_audit_scan_url', 'https://scan.example.com/'));
        $this->assertSame('', sanitize_option('seo_analyzer_sender_email', 'not an email'));
    }

    public function test_mail_failure_notice_shows_only_after_a_failed_send(): void
    {
        delete_option(\HaloAudit\Wp\Gate::MAIL_FAILURE_OPTION);
        $this->assertSame('', Admin::mailNotice());

        update_option(\HaloAudit\Wp\Gate::MAIL_FAILURE_OPTION, 1700000000, false);
        $html = Admin::mailNotice();
        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringContainsString('could not send', $html);
        $this->assertStringContainsString(Settings::PAGE, $html);
    }

    public function test_settings_screen_says_what_is_optional_and_how_mail_is_sent(): void
    {
        require_once ABSPATH . 'wp-admin/includes/template.php';
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        Settings::register();
        ob_start();
        Settings::page();
        $html = ob_get_clean();

        $this->assertStringContainsString('Every setting is optional', $html);
        $this->assertStringContainsString('WordPress mail', $html);
        $this->assertStringContainsString('Not used in this version', $html);
        $this->assertStringNotContainsStringIgnoringCase('openai', $html);
    }

    /** Synthetic values only. Each is long enough to be a valid key. */
    private const FAKE = [
        'halo_audit_psi_key' => 'FAKE-psi-SECRET-1111111111',
        'seo_analyzer_brevo_api_key' => 'FAKE-brevo-SECRET-2222222222',
        'halo_audit_scan_secret' => 'FAKE-scan-SECRET-3333333333',
    ];

    private function seedSecrets(): void
    {
        remove_all_filters('sanitize_option_halo_audit_psi_key');
        remove_all_filters('sanitize_option_seo_analyzer_brevo_api_key');
        remove_all_filters('sanitize_option_halo_audit_scan_secret');
        foreach (self::FAKE as $option => $value) {
            update_option($option, $value, false);
        }
        Settings::register();
    }

    private function settingsHtml(): string
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        ob_start();
        Settings::page();
        return (string) ob_get_clean();
    }

    public function test_saved_secrets_never_appear_in_the_settings_html(): void
    {
        $this->seedSecrets();
        $html = $this->settingsHtml();

        foreach (self::FAKE as $option => $secret) {
            $this->assertStringNotContainsString($secret, $html, $option);
            $this->assertStringNotContainsString(substr($secret, 0, 12), $html, $option . ' prefix');
            $this->assertStringNotContainsString(substr($secret, -10), $html, $option . ' suffix');
            $this->assertMatchesRegularExpression('/<input type="password" id="' . $option . '" name="' . $option . '" value=""/', $html);
        }
        $this->assertSame(3, substr_count($html, 'A key is saved.'));
        $this->assertSame(3, substr_count($html, 'action=' . Settings::CLEAR_ACTION));
        // Non-secret settings still show their value.
        update_option('seo_analyzer_sender_email', 'sender@example.com');
        $this->assertStringContainsString('value="sender@example.com"', $this->settingsHtml());
    }

    public function test_secrets_are_not_exposed_through_the_rest_settings_route(): void
    {
        $this->seedSecrets();
        $registered = get_registered_settings();
        foreach (Settings::SECRETS as $option) {
            $this->assertEmpty($registered[$option]['show_in_rest'], $option);
        }
    }

    public function test_empty_field_keeps_the_saved_secret(): void
    {
        $this->seedSecrets();
        foreach (self::FAKE as $option => $secret) {
            update_option($option, '');
            $this->assertSame($secret, get_option($option), $option);
            update_option($option, '   ');
            $this->assertSame($secret, get_option($option), $option);
        }
    }

    public function test_valid_new_secret_replaces_the_saved_one(): void
    {
        $this->seedSecrets();
        update_option('halo_audit_psi_key', '  FAKE-psi-REPLACED-9999999999  ');
        $this->assertSame('FAKE-psi-REPLACED-9999999999', get_option('halo_audit_psi_key'));
    }

    public function test_invalid_new_secret_keeps_the_saved_one_and_does_not_echo_values(): void
    {
        global $wp_settings_errors;
        $this->seedSecrets();
        $wp_settings_errors = [];
        foreach (['short', 'has spaces inside it', '<script>alert(1)</script>', '"unmatched-quote-key-value', str_repeat('x', 513)] as $bad) {
            update_option('seo_analyzer_brevo_api_key', $bad);
            $this->assertSame(self::FAKE['seo_analyzer_brevo_api_key'], get_option('seo_analyzer_brevo_api_key'), $bad);
        }
        $errors = get_settings_errors('seo_analyzer_brevo_api_key');
        $this->assertNotEmpty($errors);
        foreach ($errors as $error) {
            $this->assertStringContainsString('The saved key was kept', $error['message']);
            $this->assertStringContainsString('was not saved: the value you entered', $error['message']);
            $this->assertStringNotContainsString(self::FAKE['seo_analyzer_brevo_api_key'], $error['message']);
            $this->assertStringNotContainsString('script', $error['message']);
            $this->assertStringNotContainsString('has spaces', $error['message']);
        }
        ob_start();
        settings_errors('seo_analyzer_brevo_api_key');
        $notice = (string) ob_get_clean();
        $this->assertStringNotContainsString(self::FAKE['seo_analyzer_brevo_api_key'], $notice);
    }

    /** @dataProvider pastedKeys */
    public function test_copy_paste_artefacts_are_removed_before_the_key_is_checked(string $pasted): void
    {
        $this->seedSecrets();
        update_option('halo_audit_psi_key', $pasted);
        $this->assertSame('AIzaFAKEfakeFAKEfakeFAKEfake-9_12345678', get_option('halo_audit_psi_key'));
    }

    public function pastedKeys(): array
    {
        $key = 'AIzaFAKEfakeFAKEfakeFAKEfake-9_12345678';
        return [
            'trailing non-breaking space' => [$key . "\u{00A0}"],
            'leading zero-width space' => ["\u{200B}" . $key],
            'byte-order mark' => ["\u{FEFF}" . $key],
            'soft hyphen inside' => [substr($key, 0, 10) . "\u{00AD}" . substr($key, 10)],
            'line break and tab around' => ["\t" . $key . "\r\n"],
            'double quotes' => ['"' . $key . '"'],
            'single quotes with spaces' => ["  '" . $key . "'  "],
            'backticks' => ['`' . $key . '`'],
        ];
    }

    /** @dataProvider refusedKeys */
    public function test_refused_keys_say_why_without_echoing_the_value(string $value, string $reason): void
    {
        global $wp_settings_errors;
        $this->seedSecrets();
        $wp_settings_errors = [];
        update_option('halo_audit_psi_key', $value);
        $this->assertSame(self::FAKE['halo_audit_psi_key'], get_option('halo_audit_psi_key'));
        $errors = get_settings_errors('halo_audit_psi_key');
        $this->assertCount(1, $errors);
        $this->assertSame('error', $errors[0]['type']);
        $this->assertStringContainsString('Google PageSpeed API key was not saved', $errors[0]['message']);
        $this->assertStringContainsString($reason, $errors[0]['message']);
        $this->assertStringContainsString('The saved key was kept.', $errors[0]['message']);
        $this->assertStringNotContainsString(self::FAKE['halo_audit_psi_key'], $errors[0]['message']);
        if (strlen(trim($value)) >= 6) {
            $this->assertStringNotContainsString(substr(trim($value), 0, 6), $errors[0]['message']);
        }
    }

    public function refusedKeys(): array
    {
        return [
            'space inside' => ['AIzaFAKEfake FAKEfakeFAKEfake123', 'contains a space or a line break'],
            'non-breaking space inside' => ["AIzaFAKEfake\u{00A0}FAKEfakeFAKEfake123", 'contains a space or a line break'],
            'too short' => ['AIza12', 'is too short'],
            'too long' => [str_repeat('k', 513), 'is too long'],
            'markup' => ['<script>alert(1)</script>xx', 'contains characters an API key does not use'],
            'unmatched quote' => ['"AIzaFAKEfakeFAKEfakeFAKEfake123', 'contains characters an API key does not use'],
        ];
    }

    public function test_a_replaced_key_is_confirmed_once_and_blank_or_unchanged_input_is_silent(): void
    {
        global $wp_settings_errors;
        $this->seedSecrets();

        $wp_settings_errors = [];
        update_option('seo_analyzer_brevo_api_key', 'FAKE-brevo-REPLACED-7777777777');
        $this->assertSame('FAKE-brevo-REPLACED-7777777777', get_option('seo_analyzer_brevo_api_key'));
        $notes = get_settings_errors('seo_analyzer_brevo_api_key');
        $this->assertCount(1, $notes);
        $this->assertSame('success', $notes[0]['type']);
        $this->assertSame('Brevo API key: the new key was saved.', $notes[0]['message']);

        // A brand-new option runs the sanitiser twice (update_option, then add_option): still one notice.
        delete_option('halo_audit_scan_secret');
        $wp_settings_errors = [];
        update_option('halo_audit_scan_secret', 'FAKE-scan-NEW-8888888888');
        $this->assertCount(1, get_settings_errors('halo_audit_scan_secret'));

        $wp_settings_errors = [];
        update_option('seo_analyzer_brevo_api_key', '');
        update_option('seo_analyzer_brevo_api_key', 'FAKE-brevo-REPLACED-7777777777');
        $this->assertSame([], get_settings_errors('seo_analyzer_brevo_api_key'));
    }

    public function test_the_rejected_field_is_marked_and_still_shows_no_secret(): void
    {
        global $wp_settings_errors;
        $this->seedSecrets();
        $wp_settings_errors = [];
        update_option('seo_analyzer_brevo_api_key', 'bad key with spaces');
        $html = $this->settingsHtml();

        $this->assertMatchesRegularExpression('/<input type="password" id="seo_analyzer_brevo_api_key" name="seo_analyzer_brevo_api_key" value=""[^>]*aria-invalid="true"/', $html);
        $this->assertStringContainsString('The key you entered was not saved.', $html);
        $this->assertDoesNotMatchRegularExpression('/id="halo_audit_psi_key"[^>]*aria-invalid/', $html);
        $this->assertStringContainsString('Brevo API key was not saved: the value you entered contains a space or a line break.', $html);
        foreach (self::FAKE as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }
        $this->assertStringNotContainsString('bad key with spaces', $html);
    }

    public function test_only_administrators_may_save_this_settings_group(): void
    {
        Settings::register();
        // options.php checks this capability for the posted option group before saving anything.
        $capability = apply_filters('option_page_capability_' . Settings::GROUP, 'manage_options');
        $this->assertSame('manage_options', $capability);
        $this->assertFalse(user_can(self::factory()->user->create(['role' => 'editor']), $capability));
        $this->assertFalse(user_can(self::factory()->user->create(['role' => 'subscriber']), $capability));
        $this->assertTrue(user_can(self::factory()->user->create(['role' => 'administrator']), $capability));
        $allowed = apply_filters('allowed_options', []);
        $this->assertEqualsCanonicalizing(['halo_audit_psi_key', 'seo_analyzer_brevo_api_key', 'halo_audit_scan_secret', 'seo_analyzer_sender_email', 'halo_audit_scan_url', 'halo_audit_brevo_list_id'], $allowed[Settings::GROUP]);
    }

    /** Runs the clear handler and returns where it redirected, or the wp_die message. */
    private function clear(string $option, ?string $nonceAction): string
    {
        $_GET['option'] = $option;
        $_REQUEST['_wpnonce'] = $nonceAction === null ? '' : wp_create_nonce($nonceAction);
        add_filter('wp_redirect', static function ($location) {
            throw new RuntimeException('redirect:' . $location);
        });
        try {
            Settings::clearSecret();
        } catch (RuntimeException $e) {
            return $e->getMessage();
        } catch (WPDieException $e) {
            return 'die:' . $e->getMessage();
        } finally {
            unset($_GET['option']);
        }
        return 'no redirect';
    }

    public function test_admin_can_clear_one_saved_secret_with_the_nonce(): void
    {
        $this->seedSecrets();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $out = $this->clear('seo_analyzer_brevo_api_key', Settings::CLEAR_ACTION . '_seo_analyzer_brevo_api_key');
        $this->assertStringStartsWith('redirect:', $out);
        $this->assertStringContainsString('halo-cleared=seo_analyzer_brevo_api_key', $out);
        global $wpdb;
        $this->assertNull($wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", 'seo_analyzer_brevo_api_key')), 'row deleted');
        $this->assertSame('', get_option('seo_analyzer_brevo_api_key'), 'reads as the registered empty default');
        $this->assertSame(self::FAKE['halo_audit_psi_key'], get_option('halo_audit_psi_key'), 'other keys untouched');
        $this->assertStringNotContainsString('FAKE-', $out);
    }

    public function test_clearing_is_refused_without_capability_nonce_or_for_other_options(): void
    {
        $this->seedSecrets();
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->assertStringStartsWith('die:', $this->clear('halo_audit_psi_key', Settings::CLEAR_ACTION . '_halo_audit_psi_key'));

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->assertStringStartsWith('die:', $this->clear('halo_audit_psi_key', null));
        $this->assertStringStartsWith('die:', $this->clear('halo_audit_psi_key', Settings::CLEAR_ACTION . '_seo_analyzer_brevo_api_key'));
        $this->assertStringStartsWith('die:', $this->clear('blogname', Settings::CLEAR_ACTION . '_blogname'));
        $this->assertStringStartsWith('die:', $this->clear('seo_analyzer_sender_email', Settings::CLEAR_ACTION . '_seo_analyzer_sender_email'));

        foreach (self::FAKE as $option => $secret) {
            $this->assertSame($secret, get_option($option), $option);
        }
    }

    public function test_old_admin_class_is_gone(): void
    {
        $this->assertFalse(class_exists('Seo_Analyzer_Admin'));
    }
}
