<?php
namespace HaloAudit\Wp;

use HaloAudit\Analyzer;
use HaloAudit\PsiClient;
use HaloAudit\ReportView;
use HaloAudit\ScanClient;
use HaloAudit\Url;

final class RestController
{
    public const NS = 'halo-audit/v1';
    public const NONCE_ACTION = 'halo_audit';
    public const VERIFY_PER_HOUR = 30;

    private ReportStore $store;
    private Gate $gate;
    private ScanClient $scan;

    public function __construct(ReportStore $store, Gate $gate, ScanClient $scan)
    {
        $this->store = $store;
        $this->gate = $gate;
        $this->scan = $scan;
    }

    public function register(): void
    {
        $nonce = [$this, 'nonceOk'];
        $unlocked = [$this, 'unlockedOk'];
        $id = '(?P<id>[a-f0-9]{32})';

        register_rest_route(self::NS, '/nonce', ['methods' => 'GET', 'callback' => [$this, 'nonce'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/analyze', ['methods' => 'POST', 'callback' => [$this, 'analyze'], 'permission_callback' => $nonce]);
        register_rest_route(self::NS, '/speed', ['methods' => 'POST', 'callback' => [$this, 'speed'], 'permission_callback' => $nonce]);
        register_rest_route(self::NS, '/gate/send', ['methods' => 'POST', 'callback' => [$this, 'gateSend'], 'permission_callback' => $nonce]);
        register_rest_route(self::NS, '/gate/verify', ['methods' => 'POST', 'callback' => [$this, 'gateVerify'], 'permission_callback' => $nonce]);
        register_rest_route(self::NS, '/report/' . $id, ['methods' => 'GET', 'callback' => [$this, 'report'], 'permission_callback' => $unlocked]);
        register_rest_route(self::NS, '/report/' . $id . '/pdf', ['methods' => 'GET', 'callback' => [$this, 'pdf'], 'permission_callback' => $unlocked]);
        register_rest_route(self::NS, '/scan', ['methods' => 'POST', 'callback' => [$this, 'scanStart'], 'permission_callback' => $unlocked]);
        register_rest_route(self::NS, '/scan/(?P<id>[A-Za-z0-9_-]{1,64})', ['methods' => 'GET', 'callback' => [$this, 'scanStatus'], 'permission_callback' => $unlocked]);
    }

    public static function clientIp(): string
    {
        // REMOTE_ADDR only. Forwarded headers are set by the visitor and are not trusted.
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        return (string) apply_filters('halo_audit_client_ip', $ip);
    }

    /** The speed test runs only when a PageSpeed key is saved. Without one Google is not called. */
    public static function speedEnabled(): bool
    {
        return (bool) apply_filters('halo_audit_speed_enabled', (string) get_option('halo_audit_psi_key', '') !== '');
    }

    /** @return true|\WP_Error A WP_Error with an explicit 403: a bare false gives 401 to logged-out visitors. */
    public function nonceOk(\WP_REST_Request $request)
    {
        if (wp_verify_nonce((string) $request->get_header('x_halo_nonce'), self::NONCE_ACTION) === false) {
            return new \WP_Error('halo_nonce', 'Your session has expired. Try again.', ['status' => 403]);
        }
        return true;
    }

    /** @return true|\WP_Error */
    public function unlockedOk(\WP_REST_Request $request)
    {
        $nonce = $this->nonceOk($request);
        if ($nonce !== true) {
            return $nonce;
        }
        if ($this->gate->current() === null) {
            return new \WP_Error('halo_locked', 'Confirm your email to see this.', ['status' => 403]);
        }
        return true;
    }

    public function nonce(): \WP_REST_Response
    {
        $response = new \WP_REST_Response(['nonce' => wp_create_nonce(self::NONCE_ACTION)]);
        $response->header('Cache-Control', 'no-store, max-age=0');
        return $response;
    }

    /**
     * One page, or with competitor_url two pages compared on the same keyword. Each page is read by its
     * own analyzer, so the address checks, time budget and cache of one page never apply to the other.
     * A comparison counts as two audits. If either page cannot be read nothing is stored and the
     * visitor is told which page failed: a comparison is never built from one side only.
     */
    public function analyze(\WP_REST_Request $request)
    {
        $url = trim((string) $request->get_param('url'));
        $competitorUrl = trim((string) $request->get_param('competitor_url'));
        $keyword = sanitize_text_field((string) $request->get_param('keyword'));
        $compare = $competitorUrl !== '';

        if (!RateLimiter::allow('analyze', self::clientIp(), 10, HOUR_IN_SECONDS, $compare ? 2 : 1)) {
            return new \WP_Error('halo_rate', $compare
                ? 'You have run 10 audits in the last hour. A comparison counts as two. Try again later.'
                : 'You have run 10 audits in the last hour. Try again later.', ['status' => 429]);
        }
        if (strlen($url) > 2000 || strlen($competitorUrl) > 2000 || mb_strlen($keyword) > 255) {
            return new \WP_Error('halo_input', 'The address or keyword is too long.', ['status' => 400]);
        }
        if ($compare && trim($keyword) === '') {
            return new \WP_Error('halo_input', 'Enter a keyword.', ['status' => 400]);
        }
        if ($compare && Url::normalise($url) !== '' && self::isSamePage(Url::normalise($url), Url::normalise($competitorUrl))) {
            return self::samePage();
        }
        if ($compare && function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $out = Plugin::analyzer()->analyze($url, $keyword);
        if (!$out['ok']) {
            return new \WP_Error('halo_' . $out['code'], ($compare ? 'Your page: ' : '') . $out['message'], ['status' => 422]);
        }
        $report = $out['report'];

        $competitor = null;
        if ($compare) {
            $theirs = Plugin::analyzer()->analyze($competitorUrl, $keyword);
            if (!$theirs['ok']) {
                return new \WP_Error('halo_competitor_' . $theirs['code'], 'Competitor page: ' . $theirs['message'], ['status' => 422]);
            }
            $competitor = $theirs['report'];
            if (self::isSamePage($report['url'], $competitor['url'])) {
                return self::samePage(); // Two addresses that redirect to one page.
            }
        }

        if (!self::speedEnabled()) {
            // No key: Speed stays "Not measured" and the browser has no speed request to make.
            $report = Analyzer::skipSpeed($report);
            if ($competitor !== null) {
                $competitor = Analyzer::skipSpeed($competitor);
            }
        }
        // Kept with the report for the Brevo contact: the audited addresses as typed (URL, COMPETITOR_URL;
        // normalised, while the report shows the address after redirects) and, for FORM_NAME, the address of
        // the page the form was submitted on, taken only from the shortcode's signed value.
        $report['submitted_url'] = Url::normalise($url);
        $formPage = (string) $request->get_param('form_page');
        $report['form_page_url'] = Shortcode::pageUrl($formPage);
        // Why FORM_NAME may be empty, for the Reports screen: no value sent (a page cached before 2.0.1, an old
        // copy of the script, a form outside a single page) or a value that failed the signature or site check.
        $report['form_page_status'] = $report['form_page_url'] !== '' ? 'ok' : ($formPage === '' ? 'missing' : 'invalid');
        if ($competitor !== null) {
            $competitor['submitted_url'] = Url::normalise($competitorUrl);
            $report['competitor'] = $competitor;
        }

        $key = $this->store->create($report);
        $lead = $this->gate->current();
        if ($lead !== null) {
            $this->store->attachLead($key, $lead['name'], $lead['email']);
        }

        return new \WP_REST_Response(ReportView::free($report) + ['report_id' => $key, 'unlocked' => $lead !== null]);
    }

    /**
     * Measures one page per call. side=mine is your page, side=competitor the competitor page of a comparison;
     * the browser asks for each page in its own call, so a failed call for one page never stops the other.
     * One PageSpeed run can take 90 seconds, so two in one request could pass the web server's time limit.
     * A call without side (a page cached with an older script) measures the first page that has no result.
     * A page that already has its result is never measured again.
     */
    public function speed(\WP_REST_Request $request)
    {
        $row = $this->store->get((string) $request->get_param('report_id'));
        if ($row === null) {
            return self::notFound();
        }
        $report = $row['report'];
        $competitor = $report['competitor'] ?? null;
        $side = (string) $request->get_param('side');
        if ($side === '') {
            $side = $competitor === null || empty($report['speed_done']) ? 'mine' : 'competitor';
        }
        if (($side !== 'mine' && $side !== 'competitor') || ($side === 'competitor' && $competitor === null)) {
            return new \WP_Error('halo_input', 'This report has no such page to test.', ['status' => 400]);
        }

        $target = $side === 'mine' ? $report : $competitor;
        if (empty($target['speed_done'])) {
            if (!RateLimiter::allow('speed', self::clientIp(), 20, HOUR_IN_SECONDS)) {
                return new \WP_Error('halo_rate', 'Too many speed tests. Try again later.', ['status' => 429]);
            }
            if (function_exists('set_time_limit')) {
                @set_time_limit(120);
            }
            $psi = self::speedEnabled()
                ? (new PsiClient(Plugin::http(), (string) get_option('halo_audit_psi_key', '')))->run($target['url'])
                : null;
            if ($side === 'mine') {
                $report = Analyzer::applySpeed($report, $psi);
            } else {
                $report['competitor'] = Analyzer::applySpeed($competitor, $psi);
            }
            $this->store->updateSide($row['key'], $side, $report);
            // Answer with what is stored now: the other page may have been saved since this request read the row.
            $saved = $this->store->get($row['key']);
            if ($saved !== null) {
                $report = $saved['report'];
            }
        }
        return new \WP_REST_Response(ReportView::free($report) + ['report_id' => $row['key'], 'side' => $side, 'unlocked' => $this->gate->current() !== null]);
    }

    public function gateSend(\WP_REST_Request $request)
    {
        if ($this->store->get((string) $request->get_param('report_id')) === null) {
            return self::notFound();
        }
        $sent = $this->gate->send((string) $request->get_param('email'), self::clientIp());
        return is_wp_error($sent) ? $sent : new \WP_REST_Response(['sent' => true]);
    }

    public function gateVerify(\WP_REST_Request $request)
    {
        // Each code allows 5 tries and one IP may ask for 10 codes an hour; 30 tries an hour is
        // plenty for people and stops one address from guessing across many emails.
        if (!RateLimiter::allow('otp_verify', self::clientIp(), self::VERIFY_PER_HOUR, HOUR_IN_SECONDS)) {
            return new \WP_Error('halo_rate', 'Too many tries. Try again in one hour.', ['status' => 429]);
        }
        $row = $this->store->get((string) $request->get_param('report_id'));
        if ($row === null) {
            return self::notFound();
        }
        $name = sanitize_text_field((string) $request->get_param('name'));
        if ($name === '') {
            return new \WP_Error('halo_name', 'Enter your name.', ['status' => 400]);
        }
        $token = $this->gate->verify((string) $request->get_param('email'), (string) $request->get_param('code'), $name, $row['key']);
        if (is_wp_error($token)) {
            return $token;
        }
        // The lead is stored. Adding it to the Brevo list happens after this answer is sent and never
        // changes it: the unlock does not depend on Brevo and says nothing about it.
        BrevoSync::queue($row['key']);
        if (!headers_sent()) {
            setcookie(Gate::COOKIE, $token, [
                'expires' => time() + Gate::UNLOCK_SECONDS,
                'path' => '/',
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        return new \WP_REST_Response(ReportView::full($row['report']) + ['report_id' => $row['key'], 'unlocked' => true]);
    }

    public function report(\WP_REST_Request $request)
    {
        $row = $this->store->get((string) $request['id']);
        if ($row === null) {
            return self::notFound();
        }
        if ($row['lead_verified_at'] === null) {
            $lead = $this->gate->current();
            $this->store->attachLead($row['key'], $lead['name'], $lead['email']);
        }
        return new \WP_REST_Response(ReportView::full($row['report']) + ['report_id' => $row['key'], 'unlocked' => true]);
    }

    public function pdf(\WP_REST_Request $request)
    {
        $row = $this->store->get((string) $request['id']);
        if ($row === null) {
            return self::notFound();
        }
        if (!RateLimiter::allow('pdf', self::clientIp(), 20, HOUR_IN_SECONDS)) {
            return new \WP_Error('halo_rate', 'Too many downloads. Try again later.', ['status' => 429]);
        }
        // Anything printed while the PDF is drawn (a PHP notice with display_errors on) is dropped: it would sit
        // before %PDF- and break the file. A renderer failure is a plain error answer, not a half-sent file.
        ob_start();
        try {
            $bytes = Pdf::render($row['report']);
        } catch (\Throwable $e) {
            $bytes = null; // Nothing is logged: the report holds the visitor's addresses (see RepoHygieneTest).
        } finally {
            ob_end_clean();
        }
        if ($bytes === null) {
            return new \WP_Error('halo_pdf', 'The PDF could not be made. Try again.', ['status' => 500]);
        }

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . Pdf::FILENAME . '"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput -- binary PDF
        exit;
    }

    public function scanStart(\WP_REST_Request $request)
    {
        $row = $this->store->get((string) $request->get_param('report_id'));
        return $row === null ? self::notFound() : new \WP_REST_Response($this->scan->start($row['key']));
    }

    public function scanStatus(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response($this->scan->status((string) $request['id']));
    }

    /** One page under two addresses: http and https, a trailing slash or the case of the host. */
    private static function isSamePage(string $a, string $b): bool
    {
        $https = static fn(string $u): string => (string) preg_replace('#^http://#i', 'https://', $u);
        return Url::sameUrl($https($a), $https($b));
    }

    private static function samePage(): \WP_Error
    {
        return new \WP_Error('halo_input', 'Enter a competitor page that is different from your page.', ['status' => 400]);
    }

    private static function notFound(): \WP_Error
    {
        return new \WP_Error('halo_not_found', 'This report was not found. Run the audit again.', ['status' => 404]);
    }
}
