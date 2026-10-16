<?php
namespace HaloAudit\Wp;

use HaloAudit\HttpClient;
use HaloAudit\Response;

final class WpHttpClient implements HttpClient
{
    public const UNSAFE_TRANSPORT = 'unsafe transport';

    /** Names the tool and the site that runs it, so a site owner can see who fetched the page. */
    public static function userAgent(): string
    {
        return (string) apply_filters('halo_audit_user_agent', 'Mozilla/5.0 (compatible; HaloAudit/2.0; +' . home_url('/') . ')');
    }

    /**
     * A cURL CURLOPT_RESOLVE entry ("host:port:ip") that makes cURL connect to an address UrlGuard
     * already checked, instead of asking DNS again (DNS rebinding). The host name is kept, so
     * TLS still verifies the certificate for that name. Null when there is nothing to pin.
     *
     * @param string[] $ips
     */
    public static function resolveEntry(string $url, array $ips): ?string
    {
        $p = parse_url($url);
        if ($p === false || empty($p['host']) || $ips === []) {
            return null;
        }
        $host = strtolower(trim($p['host'], '[]'));
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return null; // An IP literal is not resolved, so there is nothing to rebind.
        }
        $port = isset($p['port']) ? (int) $p['port'] : (strtolower((string) ($p['scheme'] ?? '')) === 'https' ? 443 : 80);
        // Prefer IPv4: it works on every server. The guard has checked every address in the list.
        $v4 = array_values(array_filter($ips, static fn(string $ip): bool => (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));
        $ip = $v4[0] ?? $ips[0];
        return $host . ':' . $port . ':' . (strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip);
    }

    /**
     * Whether WordPress will send this request through its cURL transport, the only one that can be
     * pinned. Requests picks cURL first and falls back to sockets only when cURL is unusable.
     */
    public static function curlUsable(string $url): bool
    {
        $usable = function_exists('curl_init') && defined('CURLOPT_RESOLVE')
            && class_exists('\WpOrg\Requests\Transport\Curl')
            && \WpOrg\Requests\Transport\Curl::test(['ssl' => stripos($url, 'https://') === 0]);
        return (bool) apply_filters('halo_audit_curl_usable', $usable, $url);
    }

    public function request(string $method, string $url, int $timeout, int $maxBytes, array $headers = [], array $pinnedIps = []): Response
    {
        $entry = self::resolveEntry($url, $pinnedIps);
        $pin = null;
        $watch = null;
        $pinned = false;
        $mocked = false;
        if ($entry !== null) {
            // Fail closed: a page fetch must go to the checked address, and only cURL can be pinned.
            if (!self::curlUsable($url)) {
                return Response::error(self::UNSAFE_TRANSPORT . ': the PHP cURL extension (with SSL) is needed to read pages safely');
            }
            $pin = static function ($handle) use ($entry, &$pinned): void {
                curl_setopt($handle, CURLOPT_RESOLVE, [$entry]);
                $pinned = true;
            };
            // Sees the final pre_http_request value: a short-circuited (mocked) answer opens no connection.
            $watch = static function ($pre) use (&$mocked) {
                $mocked = $pre !== false;
                return $pre;
            };
            add_action('http_api_curl', $pin, 10, 1);
            add_filter('pre_http_request', $watch, PHP_INT_MAX, 1);
        }

        try {
            $result = wp_safe_remote_request($url, [
                'method' => $method,
                'timeout' => $timeout,
                'redirection' => 0,
                'limit_response_size' => $maxBytes,
                'sslverify' => true,
                'reject_unsafe_urls' => true,
                'user-agent' => self::userAgent(),
                'headers' => $headers,
            ]);
        } finally {
            if ($pin !== null) {
                remove_action('http_api_curl', $pin, 10);
                remove_filter('pre_http_request', $watch, PHP_INT_MAX);
            }
        }

        // A real answer that did not pass through the pin came over another transport: do not use it.
        if ($entry !== null && !$pinned && !$mocked && !is_wp_error($result)) {
            return Response::error(self::UNSAFE_TRANSPORT . ': the request did not go through the pinned cURL transport');
        }

        if (is_wp_error($result)) {
            return Response::error($result->get_error_message());
        }

        $flat = [];
        foreach (wp_remote_retrieve_headers($result) as $name => $value) {
            $flat[$name] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        return Response::make((int) wp_remote_retrieve_response_code($result), (string) wp_remote_retrieve_body($result), $flat);
    }
}
