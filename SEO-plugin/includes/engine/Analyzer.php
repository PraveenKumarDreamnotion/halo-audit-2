<?php
namespace HaloAudit;

final class Analyzer
{
    public const VERSION = '2.0.1';
    private const SIDE_FILE_BUDGET_SECONDS = 10;
    /** Evidence of a speed check whose PageSpeed test has not finished: not a failed test. */
    public const SPEED_PENDING = 'The speed test had not finished yet';
    /** Evidence of a speed check on a site with no PageSpeed key: Google was never asked. */
    public const SPEED_OFF = 'Speed is not tested on this site';

    private Fetcher $fetcher;
    private CertProbe $cert;
    /** @var callable():int */
    private $clock;

    public function __construct(Fetcher $fetcher, CertProbe $cert, ?callable $clock = null)
    {
        $this->fetcher = $fetcher;
        $this->cert = $cert;
        $this->clock = $clock ?? 'time';
    }

    public function analyze(string $url, string $keyword): array
    {
        $url = Url::normalise($url);
        if ($url === '' || Url::host($url) === '') {
            return self::failure('invalid', 'Enter the full address of a web page.');
        }

        $response = $this->fetcher->get($url, 15, 3000000);
        if ($response->error !== null) {
            [$code, $message] = self::fetchError($response->error);
            return self::failure($code, $message);
        }
        // Only a 2xx answer carries the page. A 204, a 304 or a 3xx with no Location has no page to score.
        if ($response->status < 200 || $response->status >= 300) {
            return self::failure('http_error', $response->status >= 300 && $response->status < 400
                ? 'We could not read this page. The site answered with a redirect (HTTP ' . $response->status . ') but gave no new address.'
                : 'We could not read this page. The site answered with HTTP ' . $response->status . '.');
        }
        if (trim($response->body) === '') {
            return self::failure('empty', 'We could not read this page. The site sent back an empty page.');
        }
        $type = strtolower((string) $response->header('content-type'));
        if ($type === '' ? !self::looksLikeHtml($response->body) : strpos($type, 'html') === false) {
            return self::failure('not_html', 'This address is not a web page.');
        }

        $this->fetcher->setDeadline(microtime(true) + self::SIDE_FILE_BUDGET_SECONDS);

        $finalUrl = $response->finalUrl !== '' ? $response->finalUrl : $url;
        $page = PageFacts::fromHtml($response->body, $finalUrl);
        $site = SiteProbe::collect($this->fetcher, $this->cert, $page, $response);
        $context = new Context($finalUrl, trim($keyword), $page, $site, (int) ($this->clock)());

        $isEducation = Education::detect(Url::host($finalUrl), $page->jsonLd()['types']);

        $rows = [];
        foreach (CheckRegistry::all() as $check) {
            if ($check->group() === 'education' && !$isEducation) {
                continue;
            }
            $rows[] = self::row($check, $context);
        }
        // PageSpeed runs later, in its own request. Until then the speed checks say so, rather than
        // reading as if Google had failed.
        $rows = self::speedEvidence($rows, self::SPEED_PENDING);

        return ['ok' => true, 'report' => [
            'engine_version' => self::VERSION,
            'url' => $finalUrl,
            'keyword' => trim($keyword),
            'created_at' => $context->now,
            'is_education' => $isEducation,
            'speed_done' => false,
            'checks' => $rows,
            'summary' => Scorer::score($rows),
        ]];
    }

    /** @param array{score:int,lcp_ms:float,cls:float}|null $psi */
    public static function applySpeed(array $report, ?array $psi): array
    {
        $site = new SiteFacts();
        $site->page = Response::make(200);
        $site->psi = $psi;
        $context = new Context($report['url'], $report['keyword'], PageFacts::fromHtml('', $report['url']), $site, (int) $report['created_at']);

        $fresh = [];
        foreach (CheckRegistry::speed() as $check) {
            $fresh[$check->id()] = self::row($check, $context);
        }
        foreach ($report['checks'] as $i => $row) {
            if (isset($fresh[$row['id']])) {
                $report['checks'][$i] = $fresh[$row['id']];
            }
        }

        $report['speed_done'] = true;
        $report['summary'] = Scorer::score($report['checks']);
        return $report;
    }

    /** Closes the speed step without a test (no PageSpeed key): Not measured, and the evidence says why. */
    public static function skipSpeed(array $report): array
    {
        $report['checks'] = self::speedEvidence($report['checks'], self::SPEED_OFF);
        $report['speed_done'] = true;
        return $report;
    }

    /** Sets the evidence of the speed checks that have no measurement. Measured rows are left alone. */
    private static function speedEvidence(array $rows, string $evidence): array
    {
        foreach ($rows as $i => $row) {
            if ($row['group'] === 'speed' && $row['status'] === Result::NOT_MEASURED) {
                $rows[$i]['evidence'] = $evidence;
            }
        }
        return $rows;
    }

    private static function row(Check $check, Context $context): array
    {
        try {
            $result = $check->run($context);
        } catch (\Throwable $e) {
            $result = Result::notMeasured('This check could not run');
        }
        return [
            'id' => $check->id(),
            'group' => $check->group(),
            'severity' => $check->severity(),
            'title' => $check->title(),
            'why' => $check->why(),
            'status' => $result->status,
            'evidence' => $result->evidence,
            'message' => $check->fix($result->status),
        ];
    }

    /** With no Content-Type, accept the body only if it starts like an HTML document. */
    private static function looksLikeHtml(string $body): bool
    {
        $start = ltrim((string) preg_replace('/^\xEF\xBB\xBF/', '', substr($body, 0, 4096)));
        return preg_match('/^(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*<(!doctype\s+html|html|head|body)[\s>]/is', $start) === 1;
    }

    /**
     * Turns a transport error into a code and a sentence for the visitor.
     * Fetcher errors start with "blocked:", "too many redirects" or "time budget"; the rest come
     * from the WordPress HTTP API (cURL messages such as "cURL error 6: Could not resolve host").
     *
     * @return array{0:string,1:string}
     */
    public static function fetchError(string $error): array
    {
        $e = strtolower($error);
        $dns = ['dns', 'We could not find this site. Its address does not resolve. Check the spelling.'];

        if (strpos($e, 'blocked:') === 0) {
            return strpos($e, 'does not resolve') !== false ? $dns : ['blocked', 'This address cannot be checked.'];
        }
        if (strpos($e, 'unsafe transport') === 0) {
            return ['transport', 'This site cannot run audits safely: its server needs the PHP cURL extension.'];
        }
        if (strpos($e, 'a valid url was not provided') !== false) {
            // wp_safe_remote_request refused the address on its own DNS check.
            return ['blocked', 'This address cannot be checked.'];
        }
        if (strpos($e, 'too many redirects') !== false) {
            return ['redirects', 'We could not read this page. It redirects more than ' . Fetcher::MAX_REDIRECTS . ' times.'];
        }
        if (preg_match('/curl error 28\b|timed? ?out|time budget/', $e)) {
            return ['unreachable', 'We could not read this page. The site did not answer in time.'];
        }
        if (preg_match('/curl error 6\b|could not resolve|name or service not known|getaddrinfo/', $e)) {
            return $dns;
        }
        if (preg_match('/curl error (35|51|58|60|77)\b|ssl|tls|certificate/', $e)) {
            return ['tls', 'We could not read this page. Its security certificate could not be verified.'];
        }
        if (preg_match('/curl error (7|52|55|56)\b|refused|reset|failed to connect|empty reply|couldn\'t connect/', $e)) {
            return ['connect', 'We could not read this page. The site refused or dropped the connection.'];
        }
        return ['unreachable', 'We could not read this page. The connection to the site failed.'];
    }

    private static function failure(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }
}
