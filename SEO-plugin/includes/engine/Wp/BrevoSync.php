<?php
namespace HaloAudit\Wp;

/**
 * Adds a visitor who confirmed their email with the 6-digit code to a Brevo contact list.
 *
 * Off unless both a Brevo API key and a list ID are saved in Settings. It runs after the unlock answer has
 * been sent, so a slow or failing Brevo never delays or blocks the report, and the lead is already stored
 * locally either way. A transient failure (timeout, rate limit, Brevo server error) is retried by WP-Cron.
 *
 * Steps (Brevo API v3):
 *   1. GET /contacts/{email}. Not found (404): create it with POST /contacts, listIds and the attributes.
 *   2. Found: PUT /contacts/{email} with the attributes below, then, if not yet in the list,
 *      POST /contacts/lists/{id}/contacts/add. Nothing else about an existing contact changes: no other
 *      attribute, no other list, nothing unsubscribed or blacklisted.
 *   3. GET /contacts/{email} again. Only a contact whose listIds hold the list counts as synced, and only
 *      attributes that read back with the value sent count as saved. Any other attribute is reported by name.
 *
 * Attributes: NAME, KEYWORD, URL (audited page), COMPETITOR_URL and FORM_NAME (the address of the page the form
 * was submitted on), taken from the stored report and the verified lead, never from the request. One without a value is left out, so it never blanks a value Brevo holds.
 * An attribute Brevo refuses (for example one the account has not defined) never stops the list membership.
 *
 * Status is kept per contact (option name holds a hash of the email, never the email) with the outcome, the
 * HTTP status and a short code. Response bodies, the API key, the email and the attribute values are never
 * stored or logged.
 */
final class BrevoSync
{
    public const LIST_OPTION = 'halo_audit_brevo_list_id';
    public const FAILURE_OPTION = 'halo_audit_brevo_failed';
    /** The last sync whose contact attributes did not all reach Brevo: attribute names and the reason, no values. */
    public const ATTRIBUTE_OPTION = 'halo_audit_brevo_attributes_failed';
    public const STATUS_PREFIX = 'halo_audit_brevo_s_';
    public const LOCK_PREFIX = 'halo_audit_brevo_l_';
    public const HOOK = 'halo_audit_brevo_sync';
    public const MAX_ATTEMPTS = 4;
    private const API = 'https://api.brevo.com/v3';
    private const TIMEOUT = 10;
    /** Brevo contact attributes this form sets, in this exact spelling. */
    public const ATTRIBUTES = ['NAME', 'KEYWORD', 'URL', 'COMPETITOR_URL', 'FORM_NAME'];
    /** Seconds to wait before retry 1, 2 and 3. */
    private const BACKOFF = [300, 1800, 7200];

    /** Why a sync is not done yet, in words for the Reports screen and the notice. */
    public const REASONS = [
        'invalid_key' => 'Brevo refused the API key',
        'ip_not_authorised' => 'Brevo blocked this server\'s IP address (Authorised IPs)',
        'forbidden' => 'Brevo refused the request (permissions)',
        'invalid_list' => 'the list ID does not exist in Brevo',
        'rejected' => 'Brevo rejected the contact',
        'rate_limited' => 'Brevo rate limit',
        'timeout' => 'Brevo did not answer in time',
        'network' => 'Brevo could not be reached',
        'server_error' => 'Brevo server error',
        'not_confirmed' => 'Brevo did not confirm the list membership',
        'no_lead' => 'the report has no confirmed email',
        'unexpected' => 'unexpected answer from Brevo',
    ];

    /** @var array<string,true> report keys to sync when this request ends */
    private static array $queued = [];

    public static function register(): void
    {
        add_action(self::HOOK, [self::class, 'run']);
    }

    public static function listId(): int
    {
        $id = (int) get_option(self::LIST_OPTION, 0);
        return $id > 0 ? $id : 0;
    }

    public static function enabled(): bool
    {
        return self::listId() > 0 && (string) get_option('seo_analyzer_brevo_api_key', '') !== '';
    }

    public static function statusKey(string $email): string
    {
        return self::STATUS_PREFIX . md5(strtolower(trim($email)));
    }

    /** @return array{state:string,code:string,http:int,attempts:int,at:int}|null */
    public static function status(string $email): ?array
    {
        $status = get_option(self::statusKey($email), null);
        return is_array($status) ? $status : null;
    }

    /**
     * Called once the code is confirmed. Records "pending" (the unlock itself says nothing about Brevo),
     * runs the sync after the response has been sent, and schedules a backstop run in case this request
     * is cut short.
     */
    public static function queue(string $reportKey): void
    {
        if (!self::enabled() || !ReportStore::isKey($reportKey)) {
            return;
        }
        $row = Plugin::store()->get($reportKey);
        if ($row === null || empty($row['email'])) {
            return;
        }
        $current = self::status((string) $row['email']);
        if (self::done($current, $reportKey)) {
            return;
        }
        // A new confirmation after a failed sync starts a fresh set of attempts; one still being retried keeps its count.
        $attempts = $current !== null && $current['state'] === 'pending' ? (int) $current['attempts'] : 0;
        self::save((string) $row['email'], 'pending', $current !== null && $current['state'] === 'pending' ? $current['code'] : '', 0, $attempts);
        if (!wp_next_scheduled(self::HOOK, [$reportKey])) {
            wp_schedule_single_event(time() + 120, self::HOOK, [$reportKey]);
        }
        if (!self::$queued) {
            add_action('shutdown', [self::class, 'runQueued'], 20);
        }
        self::$queued[$reportKey] = true;
    }

    /** shutdown: finish the HTTP response first, so the visitor never waits for Brevo. */
    public static function runQueued(): void
    {
        $keys = array_keys(self::$queued);
        self::$queued = [];
        if ($keys && function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        foreach ($keys as $key) {
            self::run($key);
        }
    }

    /** One sync attempt for the lead on this report. Safe to call more than once. */
    public static function run(string $reportKey): void
    {
        if (!self::enabled() || !ReportStore::isKey($reportKey)) {
            return;
        }
        $row = Plugin::store()->get($reportKey);
        if ($row === null || empty($row['email']) || $row['lead_verified_at'] === null) {
            return;
        }
        $email = strtolower((string) $row['email']);
        $current = self::status($email);
        if (self::done($current, $reportKey)) {
            return;
        }
        // One run per contact at a time: add_option is atomic (unique option_name).
        $lock = self::LOCK_PREFIX . md5($email);
        if (!add_option($lock, (string) time(), '', 'no')) {
            if (time() - (int) get_option($lock, 0) < 120) {
                return;
            }
            delete_option($lock);
            if (!add_option($lock, (string) time(), '', 'no')) {
                return;
            }
        }
        try {
            $attempts = (int) ($current['attempts'] ?? 0) + 1;
            $outcome = self::sync($email, self::listId(), self::attributes($row));
            if ($outcome['state'] === 'synced') {
                $missing = $outcome['missing'] ?? [];
                $page = (string) ($row['report']['form_page_status'] ?? '');
                self::save($email, 'synced', '', $outcome['http'], $attempts, ['attrs' => $outcome['attrs'] ?? 'none', 'missing' => $missing, 'page' => $page, 'r' => md5($reportKey)]);
                delete_option(self::FAILURE_OPTION);
                if ($missing || in_array($page, ['missing', 'invalid'], true)) {
                    update_option(self::ATTRIBUTE_OPTION, ['at' => time(), 'missing' => $missing, 'page' => $page], false);
                } else {
                    delete_option(self::ATTRIBUTE_OPTION);
                }
                return;
            }
            if ($outcome['state'] === 'retry' && $attempts < self::MAX_ATTEMPTS) {
                $delay = max(self::BACKOFF[$attempts - 1] ?? 7200, (int) ($outcome['retry_after'] ?? 0));
                wp_clear_scheduled_hook(self::HOOK, [$reportKey]);
                wp_schedule_single_event(time() + $delay, self::HOOK, [$reportKey]);
                self::save($email, 'pending', $outcome['code'], $outcome['http'], $attempts);
                return;
            }
            self::save($email, 'failed', $outcome['code'], $outcome['http'], $attempts);
            update_option(self::FAILURE_OPTION, ['at' => time(), 'code' => $outcome['code'], 'http' => $outcome['http']], false);
        } finally {
            delete_option($lock);
        }
    }

    /**
     * The five attributes for this lead and report, from stored data only. Values without content are left out.
     *
     * @param array{key:string,report:array,name:?string} $row ReportStore::get()
     * @return array<string,string>
     */
    public static function attributes(array $row): array
    {
        $report = $row['report'];
        $competitor = $report['competitor'] ?? null;
        $values = [
            'NAME' => (string) ($row['name'] ?? ''),
            'KEYWORD' => (string) ($report['keyword'] ?? ''),
            // The address the visitor typed (normalised). Reports saved before 2.0.1 have only the audited one.
            'URL' => (string) ($report['submitted_url'] ?? $report['url'] ?? ''),
            'COMPETITOR_URL' => is_array($competitor) ? (string) ($competitor['submitted_url'] ?? $competitor['url'] ?? '') : '',
            // The page the form was submitted on (not the audited address). '' when unknown: left out, never guessed.
            'FORM_NAME' => (string) ($report['form_page_url'] ?? ''),
        ];
        $values = apply_filters('halo_audit_brevo_attributes', $values, $row['key']);
        $out = [];
        foreach (self::ATTRIBUTES as $name) {
            $value = is_array($values) && is_scalar($values[$name] ?? null) ? (string) $values[$name] : '';
            $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
            $value = mb_substr($value, 0, in_array($name, ['URL', 'COMPETITOR_URL', 'FORM_NAME'], true) ? 1000 : 255);
            if ($value !== '') {
                $out[$name] = $value;
            }
        }
        return $out;
    }

    /** Synced, and for this report: a new verified submission (another report) is synced again. */
    private static function done(?array $status, string $reportKey): bool
    {
        return $status !== null && $status['state'] === 'synced' && ($status['r'] ?? '') === md5($reportKey);
    }

    /** @return array{state:string,code:string,http:int,retry_after?:int,attrs?:string} state is synced, retry or failed */
    private static function sync(string $email, int $listId, array $attributes): array
    {
        $path = '/contacts/' . rawurlencode($email);
        $attrs = $attributes ? 'saved' : 'none';
        $found = self::request('GET', $path);
        if ($found['http'] === 404) {
            $body = ['email' => $email, 'listIds' => [$listId]];
            $created = self::request('POST', '/contacts', $body + ($attributes ? ['attributes' => $attributes] : []));
            if ($created['http'] === 400 && $attributes && stripos((string) ($created['json']['message'] ?? ''), 'attribute') !== false) {
                // An attribute this Brevo account does not accept: the list membership still goes ahead.
                $attrs = 'rejected';
                $created = self::request('POST', '/contacts', $body);
            }
            if ($created['http'] === 400 && ($created['json']['code'] ?? '') === 'duplicate_parameter') {
                // Created by someone else a moment ago: continue as an existing contact.
                $found = ['http' => 200, 'json' => ['listIds' => []]];
            } elseif ($created['http'] === 400 || $created['http'] === 404) {
                return self::fail(self::mentionsList($created) ? 'invalid_list' : 'rejected', $created['http']);
            } elseif ($created['http'] !== 201 && $created['http'] !== 204) {
                return self::classify($created);
            } else {
                return self::confirm($path, $listId, $attrs, $attributes);
            }
        }
        if ($found['http'] !== 200) {
            return self::classify($found);
        }

        // Existing contact: only these attributes, and only the ones with a value, are updated.
        if ($attributes) {
            $updated = self::request('PUT', $path, ['attributes' => $attributes]);
            if ($updated['http'] === 400 || $updated['http'] === 404) {
                $attrs = 'rejected';
            } elseif ($updated['http'] !== 204 && $updated['http'] !== 200) {
                return self::classify($updated);
            }
        }
        if (in_array($listId, self::listIds($found), true) && !$attributes) {
            return ['state' => 'synced', 'code' => '', 'http' => 200, 'attrs' => 'none', 'missing' => []];
        }
        if (!in_array($listId, self::listIds($found), true)) {
            $added = self::addToList($email, $listId);
            if ($added !== null) {
                return $added;
            }
        }
        return self::confirm($path, $listId, $attrs, $attributes);
    }

    /**
     * Only Brevo's own record counts: the list membership, and each attribute read back with the value sent.
     * An HTTP 2xx on the write alone does not prove an attribute was kept (for example one the account lacks).
     */
    private static function confirm(string $path, int $listId, string $attrs, array $attributes): array
    {
        $check = self::request('GET', $path);
        if ($check['http'] === 404) {
            // Just created or added, and Brevo's record is not readable yet: check again later.
            return ['state' => 'retry', 'code' => 'not_confirmed', 'http' => 404];
        }
        if ($check['http'] !== 200) {
            return self::classify($check);
        }
        if (!in_array($listId, self::listIds($check), true)) {
            return ['state' => 'retry', 'code' => 'not_confirmed', 'http' => 200];
        }
        $stored = is_array($check['json']['attributes'] ?? null) ? $check['json']['attributes'] : [];
        $missing = [];
        foreach ($attributes as $name => $value) {
            if ($attrs === 'rejected' || !is_scalar($stored[$name] ?? null) || trim((string) $stored[$name]) !== $value) {
                $missing[] = $name;
            }
        }
        if ($attributes) {
            $attrs = $missing === [] ? 'saved' : (count($missing) === count($attributes) ? 'rejected' : 'partial');
        }
        return ['state' => 'synced', 'code' => '', 'http' => 200, 'attrs' => $attrs, 'missing' => $missing];
    }

    /** @return array|null null when the add call says nothing final, so the caller confirms with a GET */
    private static function addToList(string $email, int $listId): ?array
    {
        $added = self::request('POST', '/contacts/lists/' . $listId . '/contacts/add', ['emails' => [$email]]);
        if ($added['http'] === 404) {
            return self::fail('invalid_list', 404);
        }
        if ($added['http'] === 201 || $added['http'] === 400) {
            return null; // 400 is also what Brevo answers for "already in the list": the GET decides.
        }
        return self::classify($added);
    }

    private static function classify(array $r): array
    {
        $http = $r['http'];
        if ($http === 0) {
            $timeout = preg_match('/curl error 28\b|timed? ?out/i', $r['error']) === 1;
            return ['state' => 'retry', 'code' => $timeout ? 'timeout' : 'network', 'http' => 0];
        }
        if ($http === 401) {
            $message = strtolower((string) ($r['json']['message'] ?? ''));
            return self::fail(strpos($message, 'ip address') !== false ? 'ip_not_authorised' : 'invalid_key', 401);
        }
        if ($http === 403) {
            return self::fail('forbidden', 403);
        }
        if ($http === 429) {
            $after = (int) ($r['retry_after'] ?? 0);
            return ['state' => 'retry', 'code' => 'rate_limited', 'http' => 429, 'retry_after' => $after];
        }
        if ($http >= 500) {
            return ['state' => 'retry', 'code' => 'server_error', 'http' => $http];
        }
        return self::fail('unexpected', $http);
    }

    private static function fail(string $code, int $http): array
    {
        return ['state' => 'failed', 'code' => $code, 'http' => $http];
    }

    private static function mentionsList(array $r): bool
    {
        return stripos((string) ($r['json']['message'] ?? ''), 'list') !== false;
    }

    /** @return int[] */
    private static function listIds(array $r): array
    {
        $ids = $r['json']['listIds'] ?? [];
        return is_array($ids) ? array_map('intval', $ids) : [];
    }

    /** @return array{http:int,json:array,error:string,retry_after:int} */
    private static function request(string $method, string $path, ?array $body = null): array
    {
        $args = [
            'method' => $method,
            'timeout' => self::TIMEOUT,
            'redirection' => 0,
            'headers' => ['accept' => 'application/json', 'api-key' => (string) get_option('seo_analyzer_brevo_api_key', '')],
        ];
        if ($body !== null) {
            $args['headers']['content-type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }
        $response = wp_remote_request(self::API . $path, $args);
        if (is_wp_error($response)) {
            return ['http' => 0, 'json' => [], 'error' => $response->get_error_message(), 'retry_after' => 0];
        }
        $json = json_decode((string) wp_remote_retrieve_body($response), true);
        return [
            'http' => (int) wp_remote_retrieve_response_code($response),
            'json' => is_array($json) ? $json : [],
            'error' => '',
            'retry_after' => (int) wp_remote_retrieve_header($response, 'retry-after'),
        ];
    }

    private static function save(string $email, string $state, string $code, int $http, int $attempts, array $extra = []): void
    {
        update_option(self::statusKey($email), ['state' => $state, 'code' => $code, 'http' => $http, 'attempts' => $attempts, 'at' => time()] + $extra, false);
    }

    /** Words for the Reports screen. */
    public static function label(?array $status): string
    {
        if ($status === null) {
            return self::enabled() ? 'Not synced' : 'Off';
        }
        $reason = self::REASONS[$status['code']] ?? $status['code'];
        if ($status['state'] === 'synced') {
            $notes = [];
            $missing = array_values(array_filter((array) ($status['missing'] ?? []), 'is_string'));
            if ($missing) {
                $notes[] = 'Brevo did not save ' . implode(', ', $missing);
            } elseif (($status['attrs'] ?? '') === 'rejected') {
                $notes[] = 'Brevo refused the contact details';
            }
            if (($status['page'] ?? '') === 'missing') {
                $notes[] = 'FORM_NAME not sent: the form page was not known';
            } elseif (($status['page'] ?? '') === 'invalid') {
                $notes[] = 'FORM_NAME not sent: the form page value failed its check';
            }
            return $notes ? 'In list (' . implode('; ', $notes) . ')' : 'In list';
        }
        if ($status['state'] === 'pending') {
            return $status['attempts'] > 0 && $status['code'] !== '' ? 'Retrying (' . $reason . ')' : 'Pending';
        }
        return 'Failed: ' . $reason . ($status['http'] > 0 ? ' (HTTP ' . $status['http'] . ')' : '');
    }
}
