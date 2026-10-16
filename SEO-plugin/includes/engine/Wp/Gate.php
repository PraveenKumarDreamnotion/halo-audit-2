<?php
namespace HaloAudit\Wp;

final class Gate
{
    public const COOKIE = 'halo_audit_unlock';
    public const UNLOCK_SECONDS = 30 * DAY_IN_SECONDS;
    public const CODE_SECONDS = 15 * MINUTE_IN_SECONDS;
    public const MAX_ATTEMPTS = 5;
    public const MAIL_FAILURE_OPTION = 'halo_audit_mail_failed_at';

    private ReportStore $store;

    public function __construct(ReportStore $store)
    {
        $this->store = $store;
    }

    /** @return true|\WP_Error */
    public function send(string $email, string $ip)
    {
        global $wpdb;
        $email = strtolower(sanitize_email($email));
        if (!is_email($email)) {
            return new \WP_Error('halo_email', 'Enter a valid email address.', ['status' => 400]);
        }
        if (!RateLimiter::allow('otp_email', $email, 3, HOUR_IN_SECONDS) || !RateLimiter::allow('otp_ip', $ip, 10, HOUR_IN_SECONDS)) {
            return new \WP_Error('halo_rate', 'Too many codes were requested. Try again in one hour.', ['status' => 429]);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $table = Installer::otpTable();
        $wpdb->delete($table, ['email' => $email]);
        $wpdb->insert($table, [
            'email' => $email,
            'code_hash' => wp_hash_password($code),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::CODE_SECONDS),
            'attempts' => 0,
        ]);

        $sent = apply_filters('halo_audit_send_code', null, $email, $code);
        if ($sent === null) {
            $sent = \Seo_Analyzer_Mailer::send_email($email, 'Your Halo Audit code', "Your Halo Audit code is: $code\n\nThe code is valid for 15 minutes.");
        }
        if (!$sent) {
            // Only the time is kept. The message holds the code and the address, so neither is stored or logged.
            update_option(self::MAIL_FAILURE_OPTION, time(), false);
            return new \WP_Error('halo_mail', 'We could not send the code. Try again.', ['status' => 502]);
        }
        if (get_option(self::MAIL_FAILURE_OPTION) !== false) {
            delete_option(self::MAIL_FAILURE_OPTION);
        }
        return true;
    }

    /** @return string|\WP_Error the unlock cookie token */
    public function verify(string $email, string $code, string $name, string $reportKey)
    {
        global $wpdb;
        $email = strtolower(sanitize_email($email));
        $name = sanitize_text_field($name);
        $table = Installer::otpTable();
        $bad = new \WP_Error('halo_code', 'That code is not correct or has expired.', ['status' => 400]);

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE email = %s ORDER BY id DESC LIMIT 1", $email), ARRAY_A);
        if (!$row || strtotime($row['expires_at'] . ' UTC') < time()) {
            return $bad;
        }

        // Claim one attempt before checking the code. The database applies this UPDATE to the row
        // one request at a time, so at most MAX_ATTEMPTS requests can ever get past this line for
        // one code, however many arrive at once. The attempts value read above is not trusted.
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE $table SET attempts = attempts + 1 WHERE id = %d AND attempts < %d",
            $row['id'],
            self::MAX_ATTEMPTS
        ));
        if ($claimed !== 1) {
            $wpdb->delete($table, ['email' => $email]);
            return new \WP_Error('halo_code_void', 'Too many wrong tries. Request a new code.', ['status' => 400]);
        }
        if (!preg_match('/^\d{6}$/', $code) || !wp_check_password($code, $row['code_hash'])) {
            return $bad;
        }

        // Use the code up. Only the request whose DELETE removes the row wins, so two requests
        // with the right code at the same moment cannot both unlock.
        if ($wpdb->query($wpdb->prepare("DELETE FROM $table WHERE id = %d", $row['id'])) !== 1) {
            return $bad;
        }
        $wpdb->delete($table, ['email' => $email]);
        $this->store->attachLead($reportKey, $name, $email);
        return $this->token($name, $email, time() + self::UNLOCK_SECONDS);
    }

    public function token(string $name, string $email, int $expires): string
    {
        $payload = rtrim(strtr(base64_encode((string) wp_json_encode(['n' => $name, 'e' => $email, 'x' => $expires])), '+/', '-_'), '=');
        return $payload . '.' . hash_hmac('sha256', $payload, wp_salt('auth'));
    }

    /** @return array{name:string,email:string}|null */
    public function parse(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', $parts[0], wp_salt('auth')), $parts[1])) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true);
        if (!is_array($data) || empty($data['e']) || empty($data['x']) || (int) $data['x'] < time()) {
            return null;
        }
        return ['name' => (string) ($data['n'] ?? ''), 'email' => (string) $data['e']];
    }

    /** @return array{name:string,email:string}|null */
    public function current(): ?array
    {
        if (empty($_COOKIE[self::COOKIE]) || !is_string($_COOKIE[self::COOKIE])) {
            return null;
        }
        return $this->parse(wp_unslash($_COOKIE[self::COOKIE]));
    }
}
