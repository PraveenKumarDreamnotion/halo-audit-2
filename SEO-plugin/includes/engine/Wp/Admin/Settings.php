<?php
namespace HaloAudit\Wp\Admin;

final class Settings
{
    public const GROUP = 'halo_audit_settings';
    public const PAGE = 'seo-analyzer-settings';
    public const CLEAR_ACTION = 'halo_audit_clear_secret';

    /** option => [label, input type, help] */
    private const FIELDS = [
        'halo_audit_psi_key' => ['Google PageSpeed API key (optional)', 'password', 'Turns on the Speed group. Without a key the speed test is skipped, Speed shows "Not measured" and the score is worked out from the other groups. The key stays on the server.'],
        'seo_analyzer_brevo_api_key' => ['Brevo API key (optional)', 'password', 'Sends the 6-digit code through Brevo, and adds confirmed visitors to the Brevo list below. Without a key the code is sent with WordPress mail, so the mail setup of this site is used.'],
        'halo_audit_brevo_list_id' => ['Brevo list ID (optional)', 'number', 'When a visitor confirms their email with the code, they are added to this Brevo contact list. The contact gets the attributes NAME, KEYWORD, URL (the audited page), COMPETITOR_URL and FORM_NAME (the address of the page the form was submitted on), only those with a value, so an existing value is never blanked; nothing else about an existing contact changes. Empty means no list sync. Needs the Brevo API key. If Brevo uses Authorised IPs, allow this server\'s IP address there.'],
        'seo_analyzer_sender_email' => ['Sender email (optional)', 'email', 'The From address when the code is sent through Brevo. Empty means the site admin address.'],
        'halo_audit_scan_url' => ['Scan server URL', 'url', 'Not used in this version. Reserved for the live AI visibility scan.'],
        'halo_audit_scan_secret' => ['Scan server secret', 'password', 'Not used in this version.'],
    ];

    /** Saved values that are never sent to the browser. */
    public const SECRETS = ['halo_audit_psi_key', 'seo_analyzer_brevo_api_key', 'halo_audit_scan_secret'];

    public static function register(): void
    {
        foreach (self::SECRETS as $option) {
            register_setting(self::GROUP, $option, [
                'type' => 'string',
                'default' => '',
                'show_in_rest' => false,
                'sanitize_callback' => static fn($value) => self::sanitizeSecret($option, $value),
            ]);
        }
        register_setting(self::GROUP, 'seo_analyzer_sender_email', ['type' => 'string', 'sanitize_callback' => 'sanitize_email', 'default' => '']);
        register_setting(self::GROUP, 'halo_audit_brevo_list_id', ['type' => 'string', 'sanitize_callback' => [self::class, 'sanitizeListId'], 'default' => '']);
        register_setting(self::GROUP, 'halo_audit_scan_url', ['type' => 'string', 'sanitize_callback' => [self::class, 'sanitizeUrl'], 'default' => '']);

        add_settings_section('halo_audit_main', '', '__return_false', self::PAGE);
        foreach (self::FIELDS as $option => $field) {
            add_settings_field($option, $field[0], [self::class, 'field'], self::PAGE, 'halo_audit_main', ['option' => $option, 'label_for' => $option]);
        }
    }

    /** A Brevo list ID is a positive whole number. Anything else means no list. */
    public static function sanitizeListId($value): string
    {
        $value = trim((string) $value);
        return preg_match('/^[1-9][0-9]{0,9}$/', $value) === 1 ? $value : '';
    }

    public static function sanitizeUrl($value): string
    {
        return esc_url_raw(trim((string) $value), ['https']);
    }

    /** The characters API keys use. A stored key always matches this. */
    private const KEY_PATTERN = '/^[A-Za-z0-9._~+\/=:-]{10,512}$/';

    /**
     * An empty field keeps the saved secret (the form never shows it, so empty means "no change").
     * A new value must be 10 to 512 characters from the set API keys use (letters, digits and . _ ~ + / = : -);
     * otherwise the saved one is kept and the admin is told why. Nothing that looks like markup can be stored.
     * Removing a secret is a separate action (clearSecret), never an empty field.
     */
    public static function sanitizeSecret(string $option, $value): string
    {
        $saved = (string) get_option($option, '');
        $value = self::normaliseSecret(is_scalar($value) ? (string) $value : '');
        if ($value === '') {
            return $saved;
        }
        if (preg_match(self::KEY_PATTERN, $value) !== 1) {
            self::notice(
                $option,
                'invalid',
                sprintf('%s was not saved: the value you entered %s.%s', self::label($option), self::whyInvalid($value), $saved !== '' ? ' The saved key was kept.' : ''),
                'error'
            );
            return $saved;
        }
        if ($value !== $saved) {
            self::notice($option, 'updated', sprintf('%s: the new key was saved.', self::label($option)), 'success');
        }
        return $value;
    }

    /**
     * Removes what copy and paste adds around a key and that no key contains: invisible format characters
     * (zero-width spaces, byte-order marks, soft hyphens) anywhere, whitespace of any kind at the ends
     * (including non-breaking spaces and line breaks), and one pair of matching quotes or backticks.
     * The result still has to pass KEY_PATTERN, so the accepted keys are exactly as strict as before.
     */
    public static function normaliseSecret(string $value): string
    {
        $clean = preg_replace('/\p{Cf}+/u', '', $value);
        if ($clean === null) {
            return trim($value); // Not valid UTF-8: leave it to KEY_PATTERN to refuse.
        }
        $clean = (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $clean);
        if (preg_match('/^(["\'`])(.*)\1$/s', $clean, $m) === 1) {
            $clean = (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $m[2]);
        }
        return $clean;
    }

    /** A reason a person can act on. Never contains the value itself. */
    private static function whyInvalid(string $value): string
    {
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if (preg_match('/[\s\p{Z}]/u', $value) === 1) {
            return 'contains a space or a line break';
        }
        if ($length < 10) {
            return 'is too short (a key has at least 10 characters)';
        }
        if ($length > 512) {
            return 'is too long (a key has at most 512 characters)';
        }
        return 'contains characters an API key does not use (allowed: letters, digits and . _ ~ + / = : -)';
    }

    /** One notice per option and kind, even when WordPress runs the sanitiser twice for a new option. */
    private static function notice(string $option, string $kind, string $message, string $type): void
    {
        foreach (get_settings_errors($option) as $existing) {
            if ($existing['code'] === $option . '_' . $kind) {
                return;
            }
        }
        add_settings_error($option, $option . '_' . $kind, $message, $type);
    }

    public static function field(array $args): void
    {
        $option = $args['option'];
        [, $type, $help] = self::FIELDS[$option];

        if (in_array($option, self::SECRETS, true)) {
            $saved = (string) get_option($option, '') !== '';
            $rejected = false;
            foreach (get_settings_errors($option) as $error) {
                $rejected = $rejected || $error['code'] === $option . '_invalid';
            }
            printf(
                '<input type="password" id="%1$s" name="%1$s" value="" class="regular-text" autocomplete="new-password" spellcheck="false"%2$s%3$s>',
                esc_attr($option),
                $saved ? ' placeholder="' . esc_attr__('A key is saved', 'seo-analyzer') . '"' : '',
                $rejected ? ' aria-invalid="true" aria-describedby="' . esc_attr($option) . '-rejected"' : ''
            );
            if ($rejected) {
                printf(
                    '<p class="description" id="%s-rejected" style="color:#b32d2e"><strong>The key you entered was not saved.</strong> See the message at the top of the page.</p>',
                    esc_attr($option)
                );
            }
            if ($saved) {
                $clear = wp_nonce_url(admin_url('admin-post.php?action=' . self::CLEAR_ACTION . '&option=' . rawurlencode($option)), self::CLEAR_ACTION . '_' . $option);
                printf(
                    '<p class="description"><strong>A key is saved.</strong> Leave the field blank to keep it, or type a new key to replace it. <a href="%s">Remove the saved key</a></p>',
                    esc_url($clear)
                );
            }
            printf('<p class="description">%s</p>', esc_html($help));
            return;
        }

        printf(
            '<input type="%1$s" id="%2$s" name="%2$s" value="%3$s" class="regular-text" autocomplete="off"><p class="description">%4$s</p>',
            esc_attr($type),
            esc_attr($option),
            esc_attr((string) get_option($option, '')),
            esc_html($help)
        );
    }

    /** admin-post handler: removes one saved secret. Needs manage_options and the per-option nonce. */
    public static function clearSecret(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'seo-analyzer'), '', ['response' => 403]);
        }
        $option = isset($_GET['option']) ? sanitize_key(wp_unslash((string) $_GET['option'])) : '';
        if (!in_array($option, self::SECRETS, true)) {
            wp_die(esc_html__('Unknown setting.', 'seo-analyzer'), '', ['response' => 400]);
        }
        check_admin_referer(self::CLEAR_ACTION . '_' . $option);

        delete_option($option);
        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'halo-cleared' => $option], admin_url('admin.php')));
        exit;
    }

    public static function page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="wrap"><h1>' . esc_html(get_admin_page_title()) . '</h1>';
        // A top-level menu page does not print the "Settings saved" and error notices on its own.
        settings_errors();
        $cleared = isset($_GET['halo-cleared']) ? sanitize_key(wp_unslash((string) $_GET['halo-cleared'])) : '';
        if (in_array($cleared, self::SECRETS, true)) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(self::label($cleared) . ': the saved key was removed.') . '</p></div>';
        }
        echo '<p>Every setting is optional. The audit works as soon as the plugin is active and <code>[seo_analyzer]</code> is on a page.</p>';
        echo '<p>To unlock the full findings a visitor confirms an email address with a 6-digit code. '
            . 'That code is sent through Brevo when a Brevo key is saved below, and with WordPress mail otherwise. '
            . 'Make sure this site can send email, or visitors cannot unlock their report.</p>';
        echo '<p>Saved keys are never shown again. Leave a key field blank to keep the saved key.</p>';
        echo '<form action="options.php" method="post">';
        settings_fields(self::GROUP);
        do_settings_sections(self::PAGE);
        submit_button();
        echo '</form></div>';
    }

    private static function label(string $option): string
    {
        return preg_replace('/ \(optional\)$/', '', self::FIELDS[$option][0] ?? $option);
    }
}
