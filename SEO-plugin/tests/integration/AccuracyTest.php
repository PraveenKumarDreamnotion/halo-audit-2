<?php
use HaloAudit\Analyzer;
use HaloAudit\CertProbe;
use HaloAudit\PsiClient;
use HaloAudit\Text;
use HaloAudit\Wp\Gate;
use HaloAudit\Wp\Pdf;
use HaloAudit\Wp\Plugin;
use HaloAudit\Wp\RestController;

/**
 * Report accuracy against two controlled sites with known content. Every expected status, evidence string and
 * score below was worked out by hand from the fixture and the rules in DESIGN.md 3.2 and 3.3; nothing on the
 * expected side is computed by the engine. The two sites differ on almost every check, and each has its own
 * robots.txt, sitemap, llms.txt, feed, headers, certificate and share image, so a swapped or shared result fails.
 */
class Halo_Accuracy_Test extends WP_UnitTestCase
{
    private const MINE_IN = 'https://www.mine-fixture.com/courses/online-mba';
    private const MINE = 'https://www.mine-fixture.com/courses/online-mba/';
    private const MINE_ORIGIN = 'https://www.mine-fixture.com';
    private const THEIRS = 'http://rival-fixture.org/programmes/mba/';
    private const THEIRS_ORIGIN = 'http://rival-fixture.org';
    private const KEYWORD = 'online mba';

    /** @var array<string,mixed> page URL => PageSpeed answer */
    private array $psi = [];
    private array $requested = [];

    public function set_up(): void
    {
        parent::set_up();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action('rest_api_init');
        add_filter('halo_audit_dns_resolver', static fn() => static fn(string $host): array => ['93.184.216.34']);
        add_filter('halo_audit_cert_probe', static fn() => new class implements CertProbe {
            public function daysLeft(string $host): ?int { return $host === 'www.mine-fixture.com' ? 90 : null; }
        });
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
        add_filter('halo_audit_speed_enabled', '__return_true');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand(40, 250);
        // Mine: Lighthouse 0.72, LCP 3100 ms, CLS 0.05. Competitor: 0.95, 1800 ms, 0.31.
        $this->psi[self::MINE] = self::lighthouse(0.72, 3100, 0.05);
        $this->psi[self::THEIRS] = self::lighthouse(0.95, 1800, 0.31);
    }

    public function tear_down(): void
    {
        unset($_COOKIE[Gate::COOKIE]);
        parent::tear_down();
    }

    /* Fixtures */

    private static function answer(int $code, string $body = '', array $headers = []): array
    {
        return ['headers' => $headers, 'body' => $body, 'response' => ['code' => $code, 'message' => ''], 'cookies' => []];
    }

    private static function lighthouse($score, $lcp, $cls): array
    {
        $audits = [];
        if ($lcp !== null) { $audits['largest-contentful-paint'] = ['numericValue' => $lcp]; }
        if ($cls !== null) { $audits['cumulative-layout-shift'] = ['numericValue' => $cls]; }
        return self::answer(200, json_encode(['lighthouseResult' => ['categories' => ['performance' => ['score' => $score]], 'audits' => $audits]]), ['content-type' => 'application/json']);
    }

    /** A PNG header that says 1200 x 630 (getimagesize reads only the IHDR chunk). */
    private static function png(int $w, int $h): string
    {
        return "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', $w, $h) . "\x08\x02\x00\x00\x00" . "\x00\x00\x00\x00";
    }

    /**
     * Your page. Visible words: nav 9 + H1 5 + H2 2 + 350 filler + H3 1 + H2 3 + H4 4 + footer 2 = 376.
     * Headings H1 H2 H3 H2 H4: one skipped level (H2 to H4). Images: 4 of 5 have alt (alt="" counts).
     * Internal links: /about/, /courses/, absolute /contact/ = 3 (mailto, #top and other.example are not).
     */
    private static function mineHtml(): string
    {
        return '<!doctype html><html lang="en"><head>'
            . '<title>Online MBA Programme for Working Professionals in India</title>'
            . '<meta name="description" content="Study an online MBA built for working professionals in India with weekend classes, live faculty sessions, case projects and a recognised degree. Apply">'
            . '<meta name="robots" content="index, follow">'
            . '<link rel="canonical" href="https://www.mine-fixture.com/courses/online-mba/">'
            . '<meta property="og:title" content="Online MBA"><meta property="og:description" content="For working professionals">'
            . '<meta property="og:image" content="/og.png"><meta property="og:url" content="https://www.mine-fixture.com/courses/online-mba/">'
            . '<meta name="twitter:card" content="summary_large_image">'
            . '<link rel="alternate" type="application/rss+xml" href="/feed/">'
            . '<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Organization","name":"Mine Business School"},{"@type":"Course","name":"Online MBA"}]}</script>'
            . '</head><body>'
            . '<header><nav><a href="/about/">About us</a> <a href="/courses/">All courses</a> <a href="https://www.mine-fixture.com/contact/">Contact</a> '
            . '<a href="https://other.example/partner">Partner site</a> <a href="mailto:hello@mine-fixture.com">Email</a> <a href="#top">Top</a></nav></header>'
            . '<main><article><h1>Online MBA for working professionals</h1><h2>Programme overview</h2>'
            . '<p>' . trim(str_repeat('lorem ', 350)) . '</p><h3>Modules</h3><h2>Frequently asked questions</h2><h4>Is the degree recognised</h4>'
            . '<img src="/i1.jpg" alt="Campus"><img src="/i2.jpg" alt="Students"><img src="/i3.jpg" alt=""><img src="/i4.jpg" alt="Library"><img src="/i5.jpg">'
            . '</article></main><footer><p>Copyright Mine</p></footer></body></html>';
    }

    /**
     * The competitor page: a JavaScript-style shell. Visible words: H1 4 + H1 2 + H3 4 + 79 filler + fee 6
     * + 5 FAQ items x 4 + link 2 + link 3 = 120. Headings H1 H1 H3: one skip. 0 of 2 images have alt.
     * Internal links: /apply/ only (blog.rival-fixture.org is another host). A staging script address.
     */
    private static function theirsHtml(): string
    {
        return '<html><head><title>MBA Online | Rival</title>'
            . '<meta name="description" content="Rival MBA for students who want a flexible programme with online classes and a campus option. Apply.">'
            . '<link rel="canonical" href="https://rival-fixture.org/mba-online/">'
            . '<meta property="og:title" content="Rival MBA">'
            . '<script type="application/ld+json">{"@context":"https://schema.org","@type":"CollegeOrUniversity","name":"Rival"}</script>'
            . '<script type="application/ld+json">{"@context": "https://schema.org", "@type": </script>'
            . '</head><body><div id="app"><h1>Start your online MBA</h1><h1>Apply now</h1><h3>Why study with us</h3>'
            . '<p>' . trim(str_repeat('text ', 79)) . '</p><p>Tuition fee INR 250000 per year</p>'
            . str_repeat('<details><summary>Question one</summary>Answer one</details>', 5)
            . '<a href="http://rival-fixture.org/apply/">Apply online</a> <a href="https://blog.rival-fixture.org/">Read our blog</a>'
            . '<img src="/a.jpg"><img src="/b.jpg"><script src="https://rival.kinsta.cloud/app.js"></script></div></body></html>';
    }

    public function fake_http($pre, $args, $url)
    {
        $this->requested[] = $url;
        if (strpos($url, 'googleapis.com/pagespeedonline') !== false) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            return $this->psi[$q['url']] ?? new WP_Error('http_request_failed', 'no PageSpeed fixture');
        }
        $html = ['content-type' => 'text/html; charset=utf-8'];
        $m = self::MINE_ORIGIN;
        $t = self::THEIRS_ORIGIN;
        switch ($url) {
            case self::MINE_IN:
                return self::answer(301, '', ['location' => self::MINE]);
            case self::MINE:
                return self::answer(200, self::mineHtml(), $html + ['strict-transport-security' => 'max-age=31536000', 'x-frame-options' => 'SAMEORIGIN',
                    'x-content-type-options' => 'nosniff', 'referrer-policy' => 'strict-origin-when-cross-origin']);
            case "$m/robots.txt":
                return self::answer(200, "User-agent: *\nAllow: /\n\nUser-agent: GPTBot\nDisallow: /courses/\n\nSitemap: $m/sitemap_index.xml\n", ['content-type' => 'text/plain']);
            case "$m/sitemap_index.xml":
                return self::answer(200, '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>' . $m . '/page-sitemap.xml</loc><lastmod>2026-10-01</lastmod></sitemap></sitemapindex>', ['content-type' => 'application/xml']);
            case "$m/llms.txt":
                return self::answer(200, "# Mine Business School\n> Online MBA for working professionals\n", ['content-type' => 'text/plain']);
            case "$m/og.png":
                return self::answer(200, self::png(1200, 630), ['content-type' => 'image/png']);
            case self::THEIRS:
                return self::answer(200, self::theirsHtml(), $html + ['x-robots-tag' => 'noindex']);
            case "$t/robots.txt":
                return self::answer(503, 'busy');
            case "$t/sitemap.xml":
                return self::answer(429, 'slow down');
            case "$t/llms.txt":
                return self::answer(403, 'forbidden');
            case "$t/feed/":
                return new WP_Error('http_request_failed', 'cURL error 28: Operation timed out');
        }
        return self::answer(404, 'not found', ['content-type' => 'text/plain']);
    }

    private function call(string $method, string $route, array $params = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/' . RestController::NS . $route);
        $request->set_header('X-Halo-Nonce', wp_create_nonce(RestController::NONCE_ACTION));
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        return rest_get_server()->dispatch($request);
    }

    private function unlock(): void
    {
        $_COOKIE[Gate::COOKIE] = Plugin::gate()->token('Asha', 'asha@example.com', time() + 60);
    }

    /** @return array{0:string,1:array} report id and the unlocked view after both speed steps */
    private function compare(): array
    {
        $id = $this->call('POST', '/analyze', ['url' => self::MINE_IN, 'competitor_url' => self::THEIRS, 'keyword' => self::KEYWORD])->get_data()['report_id'];
        $this->call('POST', '/speed', ['report_id' => $id]);
        $this->call('POST', '/speed', ['report_id' => $id]);
        $this->unlock();
        return [$id, $this->call('GET', '/report/' . $id)->get_data()];
    }

    /* Expected results, by hand */

    /** id => [status, evidence] for your page, after the speed test */
    private const MINE_EXPECTED = [
        'title_length' => ['pass', '55 characters'],
        'keyword_in_title' => ['pass', 'Found in the title'],
        'meta_description' => ['pass', '150 characters'],
        'single_h1' => ['pass', '1 H1 heading'],
        'keyword_in_h1' => ['pass', 'Found in the H1'],
        'heading_order' => ['warn', '1 skipped level(s)'],
        'keyword_in_url' => ['pass', 'All keyword words are in the address'],
        'canonical' => ['pass', 'https://www.mine-fixture.com/courses/online-mba/'],
        'noindex' => ['pass', 'No noindex instruction'],
        'word_count' => ['pass', '376 words'],
        'image_alt' => ['warn', '4 of 5 images have an alt attribute'],
        'internal_links' => ['pass', '3 internal link(s)'],
        'https' => ['pass', 'Served over HTTPS'],
        'certificate' => ['pass', '90 day(s) left'],
        'redirect_chain' => ['pass', '1 redirect(s): 301'],
        'robots_reachable' => ['pass', 'Found'],
        'sitemap' => ['pass', 'A valid XML sitemap was found'],
        'security_headers' => ['warn', '4 of 6 present. Missing: content-security-policy, permissions-policy'],
        'staging_leaks' => ['pass', 'No test-site addresses found'],
        'js_rendering' => ['pass', '376 words in the first HTML response'],
        'ai_crawlers' => ['warn', 'Blocked: GPTBot'],
        'llms_txt' => ['pass', 'Found and well formed'],
        'json_ld' => ['pass', '1 valid block(s)'],
        'schema_types' => ['pass', 'Found: Organization, Course'],
        'faq_schema' => ['warn', 'An FAQ heading and no FAQPage schema'],
        'semantic_html' => ['pass', '5 of 5 present: main, article, nav, header, footer'],
        'open_graph' => ['pass', 'All four tags present'],
        'twitter_card' => ['pass', 'summary_large_image'],
        'og_image' => ['pass', '1200 by 630 pixels'],
        'feed' => ['pass', 'A feed was found'],
        'psi_score' => ['warn', '72 out of 100 (Google PageSpeed, mobile)'],
        'lcp' => ['warn', '3.1 seconds'],
        'cls' => ['pass', '0.05'],
    ];

    private const THEIRS_EXPECTED = [
        'title_length' => ['fail', '18 characters'],
        'keyword_in_title' => ['fail', 'Not found in the title'],
        'meta_description' => ['warn', '100 characters'],
        'single_h1' => ['fail', '2 H1 headings'],
        'keyword_in_h1' => ['pass', 'Found in the H1'],
        'heading_order' => ['warn', '1 skipped level(s)'],
        'keyword_in_url' => ['fail', '"online" is not in the address'],
        'canonical' => ['warn', 'Points to https://rival-fixture.org/mba-online/'],
        'noindex' => ['fail', 'noindex in the X-Robots-Tag header'],
        'word_count' => ['fail', '120 words'],
        'image_alt' => ['fail', '0 of 2 images have an alt attribute'],
        'internal_links' => ['warn', '1 internal link(s)'],
        'https' => ['fail', 'Served over HTTP'],
        'certificate' => ['not_measured', 'The certificate could not be read'],
        'redirect_chain' => ['pass', 'No redirect'],
        'robots_reachable' => ['not_measured', 'The site did not let us check robots.txt (HTTP 503)'],
        'sitemap' => ['not_measured', 'The sitemap could not be checked: at least one address failed or was refused'],
        'security_headers' => ['fail', '0 of 6 present. Missing: strict-transport-security, content-security-policy, x-frame-options, x-content-type-options, referrer-policy, permissions-policy'],
        'staging_leaks' => ['fail', 'Found: rival.kinsta.cloud'],
        'js_rendering' => ['warn', '120 words in the first HTML response'],
        'ai_crawlers' => ['not_measured', 'The robots.txt rules could not be read (HTTP 503)'],
        'llms_txt' => ['not_measured', 'The site did not let us check llms.txt (HTTP 403)'],
        'json_ld' => ['fail', '1 of 2 block(s) cannot be read'],
        'schema_types' => ['warn', 'Found: CollegeOrUniversity'],
        'faq_schema' => ['fail', '5 questions and no FAQPage schema'],
        'semantic_html' => ['fail', '0 of 5 present'],
        'open_graph' => ['fail', 'Missing: og:description, og:image, og:url'],
        'twitter_card' => ['warn', 'No twitter:card tag'],
        'og_image' => ['fail', 'No og:image tag'],
        'feed' => ['not_measured', 'The feed addresses could not all be checked'],
        'psi_score' => ['pass', '95 out of 100 (Google PageSpeed, mobile)'],
        'lcp' => ['pass', '1.8 seconds'],
        'cls' => ['fail', '0.31'],
        // Education extras: shown (CollegeOrUniversity schema) but never scored.
        'apply_link' => ['pass', 'Found: "Apply online"'],
        'fee_visible' => ['pass', 'A fee amount is shown'],
        'education_schema' => ['pass', 'Found: CollegeOrUniversity'],
        'sitemap_freshness' => ['not_measured', 'UNKNOWN: the sitemap has no last-modified dates'],
    ];

    /*
     * Group score = sum(points x severity) / sum(severity) over measured checks, rounded (pass 100, warn 50, fail 0).
     * Overall = (onpage x 30 + technical x 20 + ai x 30 + speed x 20) / sum of the weights of measured groups.
     *
     * Your page
     *   On-page   (24 severity): pass 3+3+2+3+2+1+2+3+2+1 = 22 -> 2200; warn heading 1 + alt 1 -> 100. 2300/24 = 95.8 -> 96
     *   Technical (15): pass 3+3+1+1+2+2+2 = 14 -> 1400; warn headers 1 -> 50. 1450/15 = 96.7 -> 97
     *   AI        (15): pass 1+2+2+1+2+1+1+1 = 11 -> 1100; warn crawlers 3 + faq 1 -> 200. 1300/15 = 86.7 -> 87
     *   Speed      (7): warn 3 -> 150, warn 2 -> 100, pass 2 -> 200. 450/7 = 64.3 -> 64
     *   Overall: (96x30 + 97x20 + 87x30 + 64x20)/100 = 8710/100 = 87 -> B. Before speed: 7430/80 = 92.9 -> 93 -> A.
     * Competitor page
     *   On-page   (24): pass h1-keyword 2 -> 200; warn meta 2 + order 1 + canonical 2 + links 1 = 6 -> 300. 500/24 = 20.8 -> 21
     *   Technical (measured 9: https 3, redirect 1, headers 1, staging 2, js 2): 100 + 2x50 = 200. 200/9 = 22.2 -> 22
     *   AI        (measured 10: json 2, types 2, faq 1, semantic 1, og 2, twitter 1, og image 1): 2x50 + 50 = 150. 150/10 = 15
     *   Speed      (7): pass 3 -> 300, pass 2 -> 200, fail 2 -> 0. 500/7 = 71.4 -> 71
     *   Overall: (21x30 + 22x20 + 15x30 + 71x20)/100 = 2940/100 = 29 -> F. Before speed: 1520/80 = 19 -> F.
     */
    private const MINE_GROUPS = ['onpage' => [96, 10, 2, 0, 0], 'technical' => [97, 7, 1, 0, 0], 'ai' => [87, 8, 2, 0, 0], 'speed' => [64, 1, 2, 0, 0]];
    private const THEIRS_GROUPS = ['onpage' => [21, 1, 4, 7, 0], 'technical' => [22, 1, 1, 3, 3], 'ai' => [15, 0, 2, 5, 3], 'speed' => [71, 2, 0, 1, 0]];

    private static function byId(array $checks): array
    {
        $out = [];
        foreach ($checks as $row) {
            $out[$row['id']] = [$row['status'], $row['evidence']];
        }
        return $out;
    }

    private static function groups(array $summary): array
    {
        $out = [];
        foreach ($summary['groups'] as $g => $d) {
            $out[$g] = [$d['score'], $d['pass'], $d['warn'], $d['fail'], $d['not_measured']];
        }
        return $out;
    }

    /* A. Findings and scores */

    public function test_every_finding_matches_the_hand_worked_result_for_its_own_page(): void
    {
        [, $full] = $this->compare();

        $this->assertSame(self::MINE, $full['url'], 'The redirect is followed and the final address is reported.');
        $this->assertSame(self::THEIRS, $full['competitor']['url']);
        $this->assertSame(self::MINE_EXPECTED, self::byId($full['checks']));
        $this->assertSame(self::THEIRS_EXPECTED, self::byId($full['competitor']['checks']));
        $this->assertFalse($full['is_education']);
        $this->assertTrue($full['competitor']['is_education']);
    }

    public function test_group_scores_overall_and_grade_follow_the_documented_rules(): void
    {
        [, $full] = $this->compare();

        $this->assertSame(self::MINE_GROUPS, self::groups($full['summary']));
        $this->assertSame([87, 'B'], [$full['summary']['overall'], $full['summary']['grade']]);
        $this->assertSame(self::THEIRS_GROUPS, self::groups($full['competitor']['summary']));
        $this->assertSame([29, 'F'], [$full['competitor']['summary']['overall'], $full['competitor']['summary']['grade']]);
        $this->assertArrayNotHasKey('education', $full['competitor']['summary']['groups'], 'Education extras never enter the score.');
    }

    public function test_before_the_speed_test_speed_is_left_out_and_the_overall_is_renormalised(): void
    {
        $data = $this->call('POST', '/analyze', ['url' => self::MINE_IN, 'competitor_url' => self::THEIRS, 'keyword' => self::KEYWORD])->get_data();

        $this->assertNull($data['summary']['groups']['speed']['score']);
        $this->assertSame(3, $data['summary']['groups']['speed']['not_measured']);
        $this->assertSame([93, 'A'], [$data['summary']['overall'], $data['summary']['grade']]);
        $this->assertSame([19, 'F'], [$data['competitor']['summary']['overall'], $data['competitor']['summary']['grade']]);
    }

    public function test_unavailable_answers_are_not_measured_and_never_counted_as_failures(): void
    {
        [, $full] = $this->compare();
        $theirs = self::byId($full['competitor']['checks']);

        // robots 503, sitemap 429, llms.txt 403, feed timeout, no certificate on http: all not measured.
        foreach (['certificate', 'robots_reachable', 'sitemap', 'ai_crawlers', 'llms_txt', 'feed'] as $id) {
            $this->assertSame('not_measured', $theirs[$id][0], $id);
        }
        // Counted as fails they would add 0 points: technical 200/(9+3+1+2) = 13 and AI 150/(10+3+1+1) = 10, not 22 and 15.
        $this->assertSame(22, $full['competitor']['summary']['groups']['technical']['score']);
        $this->assertSame(15, $full['competitor']['summary']['groups']['ai']['score']);
    }

    public function test_each_page_is_read_from_its_own_site_and_matches_a_separate_single_page_audit(): void
    {
        [, $full] = $this->compare();

        $sideFiles = array_values(array_filter($this->requested, static fn($u) => strpos($u, 'pagespeedonline') === false));
        foreach (['/robots.txt', '/llms.txt'] as $file) {
            $this->assertContains(self::MINE_ORIGIN . $file, $sideFiles);
            $this->assertContains(self::THEIRS_ORIGIN . $file, $sideFiles);
        }
        $this->assertContains(self::MINE_ORIGIN . '/og.png', $sideFiles);
        $this->assertNotContains(self::THEIRS_ORIGIN . '/og.png', $sideFiles, 'The competitor has no og:image, so none is fetched for it.');
        $this->assertNotContains(self::MINE_ORIGIN . '/feed/', $sideFiles, 'Your page declares its feed, so no feed path is probed.');
        $this->assertContains(self::THEIRS_ORIGIN . '/feed/', $sideFiles);
        foreach ($sideFiles as $u) {
            $this->assertMatchesRegularExpression('#^(https://www\.mine-fixture\.com|http://rival-fixture\.org)/#', $u, 'Only the two audited sites are read.');
        }

        // The same page audited alone gives the same findings: the other page has no influence.
        $this->requested = [];
        $mineAlone = $this->call('POST', '/analyze', ['url' => self::MINE_IN, 'keyword' => self::KEYWORD])->get_data()['report_id'];
        $theirsAlone = $this->call('POST', '/analyze', ['url' => self::THEIRS, 'keyword' => self::KEYWORD])->get_data()['report_id'];
        $stored = static fn(string $id): array => Plugin::store()->get($id)['report']['checks'];
        $withoutSpeed = static fn(array $c): array => array_values(array_filter($c, static fn($r) => $r['group'] !== 'speed'));
        $this->assertSame($withoutSpeed($full['checks']), $withoutSpeed($stored($mineAlone)));
        $this->assertSame($withoutSpeed($full['competitor']['checks']), $withoutSpeed($stored($theirsAlone)));
    }

    /* PDF = the unlocked report */

    public function test_comparison_pdf_shows_the_same_status_evidence_and_scores_as_the_unlocked_report(): void
    {
        [$id, $full] = $this->compare();
        $html = Pdf::html(Plugin::store()->get($id)['report']);
        $labels = ['pass' => 'Pass', 'warn' => 'Improve', 'fail' => 'Fix', 'not_measured' => 'Not measured'];
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        preg_match_all('#<tr><td><strong>([^<]*)</strong></td><td class="mine">(.*?)</td><td class="theirs">(.*?)</td></tr>#s', $html, $rows, PREG_SET_ORDER);
        $this->assertCount(count($full['pairs']), $rows, 'One PDF row per finding pair.');
        foreach ($full['pairs'] as $i => $pair) {
            [, $title, $mineCell, $theirCell] = $rows[$i];
            $this->assertSame($e($pair['title']), $title, $pair['id']);
            foreach (['mine' => $mineCell, 'theirs' => $theirCell] as $side => $cell) {
                if ($pair[$side] === null) {
                    $this->assertStringContainsString('Not checked', $cell, $pair['id']);
                    continue;
                }
                $this->assertMatchesRegularExpression('#>' . preg_quote($labels[$pair[$side]['status']], '#') . '</span><br>' . preg_quote($e($pair[$side]['evidence']), '#') . '(<br>|$)#', $cell, $side . ' ' . $pair['id']);
            }
            $fix = $pair['mine']['message'] ?? '';
            $fix === '' ? $this->assertStringNotContainsString('What to do', $mineCell) : $this->assertStringContainsString($e($fix), $mineCell, $pair['id']);
        }
        $this->assertStringContainsString('<td><strong>Overall</strong></td><td><strong>87</strong></td><td><strong>29</strong></td>', $html);
        foreach (self::MINE_GROUPS as $g => [$score]) {
            $this->assertStringContainsString('Your page: ' . $score . ' / 100 &nbsp; Competitor page: ' . self::THEIRS_GROUPS[$g][0] . ' / 100', $html, $g);
        }
        $this->assertStringContainsString('87 / 100 &nbsp; Grade B', $html);
    }

    public function test_single_page_pdf_shows_the_same_findings_as_the_unlocked_report(): void
    {
        $id = $this->call('POST', '/analyze', ['url' => self::MINE_IN, 'keyword' => self::KEYWORD])->get_data()['report_id'];
        $this->call('POST', '/speed', ['report_id' => $id]);
        $this->unlock();
        $full = $this->call('GET', '/report/' . $id)->get_data();
        $html = Pdf::html(Plugin::store()->get($id)['report']);
        $labels = ['pass' => 'Pass', 'warn' => 'Improve', 'fail' => 'Fix', 'not_measured' => 'Not measured'];
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $this->assertSame(self::MINE_EXPECTED, self::byId($full['checks']));
        preg_match_all('#<tr><td class="status"[^>]*>(.*?)</td><td><strong>(.*?)</strong><br>(.*?)(?:<br><em>What to do:</em> (.*?))?</td></tr>#s', $html, $rows, PREG_SET_ORDER);
        $this->assertCount(count($full['checks']), $rows);
        foreach ($full['checks'] as $i => $row) {
            $this->assertSame([$labels[$row['status']], $e($row['title']), $e($row['evidence']), $e($row['message'])], [$rows[$i][1], $rows[$i][2], $rows[$i][3], $rows[$i][4] ?? ''], $row['id']);
        }
        $this->assertStringContainsString('87 / 100 &nbsp; Grade B', $html);
    }

    /* Speed: thresholds and rounding, worked out by hand */

    /** @dataProvider speedCases */
    public function test_speed_statuses_and_group_score_follow_the_thresholds($lighthouse, float $lcp, float $cls, array $statuses, int $group): void
    {
        $psi = PsiClient::parse((string) json_encode(['lighthouseResult' => ['categories' => ['performance' => ['score' => $lighthouse]],
            'audits' => ['largest-contentful-paint' => ['numericValue' => $lcp], 'cumulative-layout-shift' => ['numericValue' => $cls]]]]));
        $report = Analyzer::applySpeed(['url' => self::MINE, 'keyword' => '', 'created_at' => time(), 'checks' => [
            ['id' => 'psi_score', 'group' => 'speed'], ['id' => 'lcp', 'group' => 'speed'], ['id' => 'cls', 'group' => 'speed'],
        ]], $psi);
        $this->assertSame($statuses, array_column($report['checks'], 'status'));
        $this->assertSame($group, $report['summary']['groups']['speed']['score']);
        $this->assertSame($group, $report['summary']['overall'], 'Speed alone: the overall equals the speed score.');
    }

    public function speedCases(): array
    {
        // Points x severity (score 3, LCP 2, CLS 2) / 7.
        return [
            'all good' => [0.95, 1800, 0.01, ['pass', 'pass', 'pass'], 100],
            '0.895 rounds to 90 (as Lighthouse shows it)' => [0.895, 2499, 0.099, ['pass', 'pass', 'pass'], 100],
            '0.894 rounds to 89' => [0.894, 2500, 0.1, ['warn', 'warn', 'warn'], 50],
            'upper warn edges' => [0.5, 4000, 0.25, ['warn', 'warn', 'warn'], 50],
            '0.495 rounds to 50, LCP just over 4 s, CLS just over 0.25' => [0.495, 4001, 0.251, ['warn', 'fail', 'fail'], 21], // 150/7 = 21.4
            '0.494 rounds to 49' => [0.494, 1000, 0.0, ['fail', 'pass', 'pass'], 57], // 400/7 = 57.1
            'measured zero' => [0, 9000, 0.6, ['fail', 'fail', 'fail'], 0],
            'brief example' => [0.70, 3000, 0.05, ['warn', 'warn', 'pass'], 64], // 450/7 = 64.3
        ];
    }

    public function test_a_failed_or_partial_pagespeed_answer_is_not_measured_on_that_page_only(): void
    {
        $this->psi[self::MINE] = self::lighthouse(0.72, null, 0.05); // no LCP audit
        $this->psi[self::THEIRS] = self::answer(500, '{"error":{"code":500,"message":"Lighthouse returned error"}}', ['content-type' => 'application/json']);
        [, $full] = $this->compare();

        foreach ([$full, $full['competitor']] as $side) {
            $this->assertSame(['not_measured', 'not_measured', 'not_measured'], array_column(array_values(array_filter($side['checks'], static fn($r) => $r['group'] === 'speed')), 'status'));
            $this->assertNull($side['summary']['groups']['speed']['score']);
        }
        // Back to the before-speed overall: 93 and 19, not dragged down by a zero.
        $this->assertSame([93, 19], [$full['summary']['overall'], $full['competitor']['summary']['overall']]);
    }

    /* Known limits of static HTML checks, pinned so that a change is noticed (see the accuracy notes) */

    public function test_limits_of_the_static_checks(): void
    {
        $this->assertTrue(Text::has('Restart your career', 'art'), 'Keyword match is a substring match, not a whole-word match.');

        $page = \HaloAudit\PageFacts::fromHtml('<html><head><meta name="googlebot" content="noindex"><meta name="description" content="first"><meta name="description" content="second"></head>'
            . '<body><picture><source srcset="/a.webp"></picture><div style="background:url(/b.jpg)"></div><a href="https://blog.example.com/">Blog</a></body></html>', 'https://example.com/');
        $this->assertNull($page->meta('robots'), 'A googlebot-only noindex is not read.');
        $this->assertSame('first', $page->meta('description'), 'Only the first meta description is read.');
        $this->assertSame([], $page->images(), 'Images without <img> (picture sources, CSS backgrounds) are not counted.');
        $this->assertFalse($page->links()[0]['internal'], 'A subdomain counts as another site.');
        $this->assertNull($page->canonical(), 'A canonical sent only in the HTTP Link header is not read.');
    }
}
