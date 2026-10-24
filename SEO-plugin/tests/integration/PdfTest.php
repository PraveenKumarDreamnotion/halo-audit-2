<?php
use HaloAudit\Scorer;
use HaloAudit\Wp\Gate;
use HaloAudit\Wp\Pdf;
use HaloAudit\Wp\Plugin;
use HaloAudit\Wp\RestController;

class Halo_Pdf_Test extends WP_UnitTestCase
{
    private function report(): array
    {
        return [
            'engine_version' => '2.0.0', 'url' => 'https://example.com/a?x=<script>', 'keyword' => '<b>mba</b>', 'created_at' => 1760000000,
            'is_education' => false, 'speed_done' => true,
            'checks' => [
                ['id' => 'title_length', 'group' => 'onpage', 'severity' => 3, 'title' => 'Title length', 'why' => 'Why.', 'status' => 'fail', 'evidence' => '<img src="http://evil.example/x.png">', 'message' => 'Fix it.'],
                ['id' => 'https', 'group' => 'technical', 'severity' => 3, 'title' => 'Secure connection', 'why' => 'Why.', 'status' => 'pass', 'evidence' => 'Served over HTTPS', 'message' => ''],
            ],
            'summary' => ['groups' => [
                'onpage' => ['score' => 0, 'pass' => 0, 'warn' => 0, 'fail' => 1, 'not_measured' => 0],
                'technical' => ['score' => 100, 'pass' => 1, 'warn' => 0, 'fail' => 0, 'not_measured' => 0],
                'ai' => ['score' => null, 'pass' => 0, 'warn' => 0, 'fail' => 0, 'not_measured' => 0],
                'speed' => ['score' => null, 'pass' => 0, 'warn' => 0, 'fail' => 0, 'not_measured' => 3],
            ], 'overall' => 40, 'grade' => 'D'],
        ];
    }

    private static function row(string $id, string $status, int $severity, string $group, string $evidence = ''): array
    {
        return ['id' => $id, 'group' => $group, 'severity' => $severity, 'title' => 'Title ' . $id, 'why' => 'Why ' . $id,
            'status' => $status, 'evidence' => $evidence !== '' ? $evidence : 'Evidence ' . $id, 'message' => $status === 'pass' || $status === 'not_measured' ? '' : 'Do ' . $id];
    }

    /** A comparison scored by the real Scorer: every area on both pages, speed included. */
    private function comparison(): array
    {
        $rows = static fn(string $who, array $statuses): array => [
            self::row('title_length', $statuses[0], 3, 'onpage', $who . ' title'),
            self::row('https', $statuses[1], 3, 'technical', $who . ' https'),
            self::row('llms_txt', $statuses[2], 2, 'ai', $who . ' llms'),
            self::row('psi_score', $statuses[3], 3, 'speed', $who . ' performance'),
            self::row('lcp', $statuses[4], 2, 'speed', $who . ' lcp'),
            self::row('cls', $statuses[5], 2, 'speed', $who . ' cls'),
        ];
        $report = $this->report();
        $report['checks'] = $rows('MINE', ['pass', 'pass', 'warn', 'warn', 'fail', 'pass']);
        $report['summary'] = Scorer::score($report['checks']);
        $theirs = $rows('THEIRS', ['fail', 'warn', 'fail', 'fail', 'fail', 'fail']);
        $report['competitor'] = ['url' => 'https://rival.example/', 'keyword' => 'mba', 'created_at' => 1760000000, 'is_education' => false,
            'speed_done' => true, 'checks' => $theirs, 'summary' => Scorer::score($theirs)];
        return $report;
    }

    private static function pageCount(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $pdf);
    }

    /**
     * A section heading as Pdf::heading() writes it, first letter in its own span: kept with its table, or ($kept
     * false) not, for a heading that opens a keep-first block with its first finding card (see withCard()).
     */
    private static function heading(string $text, string $tag = 'h2', string $meta = '', bool $kept = true): string
    {
        return '<' . $tag . ($kept ? ' keep-with-table="1"' : '') . '><span class="' . $tag . '">' . mb_substr($text, 0, 1) . '</span>' . mb_substr($text, 1)
            . ($meta !== '' ? '<span class="band-meta">&nbsp;&nbsp;&middot;&nbsp;&nbsp;' . $meta . '</span>' : '') . '</' . $tag . '>';
    }

    /** A section title and its first finding card, one block never split (Pdf::headingWithCard()). */
    private static function withCard(string $text, string $meta): string
    {
        return '<div class="keep-first">' . self::heading($text, 'h2', $meta, false) . '<table class="fc"';
    }

    /** The start of a section heading's text, whatever scores follow it and however it is kept. */
    private static function title(string $text): string
    {
        return '<span class="h2">' . mb_substr($text, 0, 1) . '</span>' . mb_substr($text, 1);
    }

    /**
     * The report's own graphics are the score rings and bars: SVG drawn by Pdf from integers, as data: URIs.
     * Checks each one holds nothing that could load anything, and returns the HTML without them.
     */
    private function withoutOwnGraphics(string $html): string
    {
        $pattern = '#<img src="data:image/svg\+xml;base64,([A-Za-z0-9+/=]+)" width="\d+mm" />#';
        preg_match_all($pattern, $html, $m);
        $this->assertNotEmpty($m[1], 'The report draws its rings and bars.');
        foreach ($m[1] as $b64) {
            $svg = (string) base64_decode($b64, true);
            $this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', $svg);
            foreach (['href', 'url(', '<image', '<script', '<use', '<foreignObject', '@import', 'file:', 'data:'] as $risky) {
                $this->assertStringNotContainsString($risky, $svg, $risky);
            }
        }
        return (string) preg_replace($pattern, '', $html);
    }

    public function test_html_escapes_everything_that_came_from_the_page(): void
    {
        // Without the zero-width break points of long runs (see test_long_addresses_break_only_at_safe_characters).
        $html = str_replace(["\u{200B}", '<br />'], '', Pdf::html($this->report()));
        $this->assertStringNotContainsString('<img src="http://evil.example', $html);
        $this->assertStringContainsString('&lt;img src=&quot;http://evil.example/x.png&quot;&gt;', $html);
        $this->assertStringNotContainsString('<b>mba</b>', $html);
        $this->assertStringContainsString('&ldquo;&lt;b&gt;mba&lt;/b&gt;&rdquo;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_html_shows_score_grade_groups_and_not_measured(): void
    {
        $html = Pdf::html($this->report());
        $this->assertSame(['40'], Halo_Pdf_Rows::rings($html));
        $this->assertStringContainsString('<div class="grade grade-d">Grade D</div>', $html);
        $this->assertStringContainsString('On-page for your keyword', $html);
        $this->assertStringContainsString('Not measured', $html);
        $this->assertStringContainsString('Fix it.', $html);
        $this->assertStringNotContainsString('—', $html);
    }

    public function test_area_cards_come_two_to_a_row_with_counts_in_words_and_the_top_issue(): void
    {
        $html = Pdf::html($this->report());
        $this->assertSame(2, substr_count($html, '<table class="area" autosize="1">'));
        // As in the reference: every count in plain text, zeros included, in the order fix, improve, passed;
        // "not measured" only when there is one. The names are the section names (Scorer::LABELS).
        $this->assertStringContainsString('<div class="c-name">On-page for your keyword</div><div style="font-size:3pt;line-height:1">&nbsp;</div><div class="c-counts">1 to fix &middot; 0 to improve &middot; 0 passed</div>', $html);
        $this->assertStringContainsString('<div class="c-name">Technical</div><div style="font-size:3pt;line-height:1">&nbsp;</div><div class="c-counts">0 to fix &middot; 0 to improve &middot; 1 passed</div>', $html);
        $this->assertStringContainsString('<div class="c-name">AI readiness</div><div style="font-size:3pt;line-height:1">&nbsp;</div><div class="c-counts">0 to fix &middot; 0 to improve &middot; 0 passed</div>', $html);
        $this->assertStringContainsString('<div class="c-name">Speed</div><div style="font-size:3pt;line-height:1">&nbsp;</div><div class="c-counts">0 to fix &middot; 0 to improve &middot; 0 passed &middot; 3 not measured</div>', $html);
        foreach (['On-page SEO', 'Technical SEO', 'Speed (Halo Audit)'] as $old) {
            $this->assertStringNotContainsString($old, $html, 'One name per area throughout the PDF.');
        }
        $this->assertStringContainsString('<div class="k-num k-warn">0</div>', $html);
        $this->assertStringContainsString('<div class="c-top"><span class="c-top-label">Top issue&nbsp;&nbsp;</span>Title length</div>', $html);
        $this->assertStringContainsString('<div class="c-top">Every check passed.</div>', $html);
        $this->assertSame(2, substr_count($html, '<div class="c-top">Not measured on this page.</div>'), 'AI readiness and Speed.');
        $this->assertStringNotContainsString('ca-empty', $html);
        // The heading and the first row of cards are one unbreakable block (no keep-with-table: see areaCards()),
        // and the second row follows outside it.
        $this->assertSame(1, preg_match('#<div class="keep-first"><h2><span class="h2">S</span>cores by area<span class="band-meta">[^<]*</span></h2>'
            . '<table class="area" autosize="1">.*?</table></div><table class="area" autosize="1">#s', $html));

        // A fifth card sits alone in its row, beside an empty slot that has no outline.
        $report = $this->report();
        $report['summary']['groups']['education'] = ['score' => null, 'pass' => 0, 'warn' => 0, 'fail' => 0, 'not_measured' => 0];
        $html = Pdf::html($report);
        $this->assertSame(3, substr_count($html, '<table class="area" autosize="1">'));
        $this->assertSame(3, substr_count($html, '<td class="ca-empty" colspan="2"></td>'));
        $this->assertStringContainsString('<div class="c-name">Education extras</div>', $html);
    }

    public function test_summary_page_comes_first_and_the_details_start_on_their_own_page(): void
    {
        $html = Pdf::html($this->comparison());
        $order = ['<div class="hero">', 'SEO comparison report', '<div class="panel">', 'What the results mean', '<td class="kpi kpi-fail">', '<div class="keep-first"><h2><span class="h2">S</span>cores by area',
            self::title('Priority fixes for your page'), '<pagebreak />',
            'How to read the results', self::title('Speed'), self::title('On-page for your keyword'), self::title('Technical'), self::title('AI readiness')];
        $last = -1;
        foreach ($order as $part) {
            $at = strpos($html, $part, $last + 1);
            $this->assertNotFalse($at, $part);
            $this->assertGreaterThan($last, $at, $part);
            $last = $at;
        }
        $this->assertSame(1, substr_count($html, '<pagebreak />'));
        // The report date is printed once on page 1 (the running header carries it on later pages).
        $this->assertSame(1, substr_count($html, gmdate('j F Y', 1760000000)));
    }

    public function test_the_meaning_box_reads_only_the_stored_scores(): void
    {
        $report = $this->comparison();
        $mine = $report['summary']['overall'];
        $theirs = $report['competitor']['summary']['overall'];
        $html = Pdf::html($report);
        $this->assertStringContainsString('Your page scores ' . $mine . ' out of 100 (grade ' . $report['summary']['grade'] . ').', $html);
        $this->assertStringContainsString('The competitor page scores ' . $theirs . ' (grade ' . $report['competitor']['summary']['grade'] . '), so your page is '
            . ($mine - $theirs) . ' points ahead.', $html);
        $this->assertStringContainsString('It is ahead in all four areas.', $html);
        $this->assertStringContainsString('On your page, 1 check needs fixing and 2 checks could be improved;', $html);

        // An area measured on one page only is not compared, and the sentence says so.
        $report['competitor']['checks'] = array_values(array_filter($report['competitor']['checks'], static fn($r) => in_array($r['group'], ['onpage', 'technical'], true)));
        $report['competitor']['summary'] = Scorer::score($report['competitor']['checks']);
        $this->assertStringContainsString('It is ahead in both areas that could be compared.', Pdf::html($report));

        $single = $this->report();
        $this->assertStringContainsString('This page scores 40 out of 100 (grade D).', Pdf::html($single));
        $this->assertStringContainsString('Its strongest area is technical SEO (100); the one that needs most work is on-page SEO (0).', Pdf::html($single));
    }

    /** Ahead, behind and level each get a short sentence of their own, named in plain words, from the stored scores. */
    public function test_the_meaning_box_says_ahead_and_behind_in_separate_sentences(): void
    {
        $report = $this->comparison();
        // Your page: on-page pass, technical pass, AI warn, speed warn/fail/pass. Competitor: all worse except AI, made better.
        $report['competitor']['checks'][2]['status'] = 'pass';
        $report['competitor']['summary'] = Scorer::score($report['competitor']['checks']);
        $html = Pdf::html($report);
        $this->assertStringContainsString('It is ahead in on-page SEO, technical SEO and speed. It is behind in AI readiness.', $html);
        $this->assertStringNotContainsString('and behind', $html);

        // Level: the same score on both pages for one area.
        $report['competitor']['checks'][2]['status'] = 'warn';
        $report['competitor']['summary'] = Scorer::score($report['competitor']['checks']);
        $this->assertStringContainsString('It is ahead in on-page SEO, technical SEO and speed. It is level in AI readiness.', Pdf::html($report));
    }

    public function test_the_grade_scale_matches_the_scorer(): void
    {
        $this->assertStringContainsString('Grades: A 90&ndash;100 &middot; B 70&ndash;89 &middot; C 50&ndash;69 &middot; D 30&ndash;49 &middot; F below 30', Pdf::html($this->report()));
        foreach ([100 => 'A', 90 => 'A', 89 => 'B', 70 => 'B', 69 => 'C', 50 => 'C', 49 => 'D', 30 => 'D', 29 => 'F', 0 => 'F'] as $score => $grade) {
            $this->assertSame($grade, Scorer::grade($score), (string) $score);
        }
    }

    /** The default credit is Thrivemattic's on every site (owner decision, 23 Oct 2026), whatever the site is called. */
    public function test_default_credit_is_thrivemattic_on_every_site(): void
    {
        update_option('blogname', 'Example School');
        $expected = 'Report generated by thrivemattic.com with Halo Audit';
        $this->assertSame($expected, Pdf::defaultFooter());
        $this->assertSame($expected, Pdf::credit($this->report()));
        // The credit is in the running footer of every page, not a paragraph after the last table.
        $this->assertStringNotContainsString('Report generated by', Pdf::html($this->report()));
    }

    /**
     * "Halo Audit" in the footer is a link to the product page on every page: a URI annotation in the PDF itself,
     * one per page, to exactly that address. Only those words are linked, and the link makes mPDF fetch nothing.
     */
    public function test_footer_links_halo_audit_to_the_product_page_on_every_page(): void
    {
        $pdf = Pdf::render($this->comparison());
        $pages = self::pageCount($pdf);
        $this->assertGreaterThan(1, $pages);
        $this->assertSame($pages, substr_count($pdf, '/URI (' . Pdf::PRODUCT_URL . ')'));
        $this->assertSame($pages + 1, preg_match_all('#/URI \(#', $pdf), 'No other link than the CTA button.');
        $this->assertSame(1, substr_count($pdf, '/URI (https://thrivemattic.com/contact/)'));
        $this->assertSame([], Pdf::refusedFetches());

        // A credit without the words has no link; the rest of the footer is never a link.
        add_filter('halo_audit_pdf_footer', static fn() => 'Report by Example School');
        $this->assertStringNotContainsString('/URI (' . Pdf::PRODUCT_URL . ')', Pdf::render($this->report()));
    }

    /** No address of this site in the default credit: a staging or local site must not print its internal URL. */
    public function test_default_footer_never_prints_the_site_address(): void
    {
        foreach (['https://stg-example-staging.kinsta.cloud', 'https://thrivemattic.local'] as $home) {
            update_option('home', $home);
            update_option('blogname', '');
            $this->assertStringNotContainsString('://', Pdf::defaultFooter());
            $this->assertStringNotContainsString(\HaloAudit\Url::host($home), Pdf::defaultFooter());
        }
    }

    public function test_footer_filter_receives_the_default_and_the_report_and_its_text_is_escaped(): void
    {
        $seen = [];
        add_filter('halo_audit_pdf_footer', static function ($footer, $report) use (&$seen) {
            $seen = [$footer, $report['url']];
            return 'Report by <Example> & School';
        }, 10, 2);
        $this->assertSame('Report by <Example> & School', Pdf::credit($this->report()));
        $this->assertSame([Pdf::defaultFooter(), 'https://example.com/a?x=<script>'], $seen);

        $footer = (new ReflectionMethod(Pdf::class, 'runningFooter'));
        $footer->setAccessible(true);
        $html = $footer->invoke(null, $this->report());
        $this->assertStringContainsString('Report by &lt;Example&gt; &amp; School', $html);
        $this->assertStringContainsString('Page {PAGENO} of {nbpg}', $html);

        remove_all_filters('halo_audit_pdf_footer');
        add_filter('halo_audit_pdf_footer', '__return_empty_string');
        $this->assertSame('', Pdf::credit($this->report()));
        $this->assertStringContainsString('<td class="rf-credit"></td>', $footer->invoke(null, $this->report()));
    }

    public function test_render_returns_pdf_bytes_without_a_blank_page(): void
    {
        $pdf = Pdf::render($this->report());
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(2, self::pageCount($pdf), 'dashboard page + details page');
        // A comparison's dashboard (two score rings, the area cards) fills page 1, so its priority fixes open page 2
        // and the details follow them there: three pages, none of them blank.
        $this->assertSame(3, self::pageCount(Pdf::render($this->comparison())));
    }

    public function test_top_fixes_put_fix_first_then_severity_then_area_weight_and_stop_at_five(): void
    {
        $report = $this->report();
        $report['checks'] = [
            self::row('a', 'warn', 3, 'ai'),
            self::row('b', 'fail', 1, 'onpage'),
            self::row('c', 'fail', 3, 'technical'),
            self::row('d', 'fail', 3, 'onpage'),
            self::row('e', 'not_measured', 3, 'speed'),
            self::row('f', 'warn', 2, 'onpage'),
            self::row('g', 'warn', 3, 'technical'),
            self::row('h', 'pass', 3, 'onpage'),
        ];
        $html = Pdf::html($report);
        $this->assertSame(5, preg_match_all('#<table class="top".*?</table>#s', $html, $tables));
        preg_match_all('#<tr><td class="t-n">(\d)</td><td class="t-result"><span class="chip chip-([a-z_]+)">(?:<span class="fb">[^<]*</span>)?[^<]*</span></td><td class="check"><strong>Title ([a-z])</strong>.*?<td class="t-todo">Do \3</td></tr>#s', implode('', $tables[0]), $rows, PREG_SET_ORDER);
        $this->assertSame(['1d fail', '2c fail', '3b fail', '4a warn', '5g warn'], array_map(static fn($r) => $r[1] . $r[3] . ' ' . $r[2], $rows));
        // The heading, the column heads of the reference and the first fix are one block that is never split
        // (no keep-with-table heading inside it); each later fix follows straight after, a one-row table.
        $head = '<tr><th class="t-n">#</th><th class="t-result">Status</th><th class="check">Check</th><th class="t-todo">What to do</th></tr>';
        $this->assertSame(1, preg_match('#' . preg_quote('<div class="keep-first">' . self::heading('Priority fixes', 'h2', '5 of 6 shown, every one is in the findings', false) . '<table class="top" autosize="1"><tbody>' . $head, '#')
            . '(<tr><td.*?</tr>){1}</tbody></table></div>(<table class="top" autosize="1"><tbody><tr><td.*?</tr></tbody></table>){4}<pagebreak />#s', $html));
        $this->assertSame(1, substr_count($html, $head), 'html() has no continuation: render() adds it only when a fix moves to the next page.');
        $this->assertStringNotContainsString('(continued)', $html);

        // Two fixes or fewer: one table, inside the block.
        $report['checks'] = [self::row('b', 'fail', 1, 'onpage'), self::row('h', 'pass', 3, 'onpage')];
        $html = Pdf::html($report);
        $this->assertSame(1, substr_count($html, '<table class="top"'));
        $this->assertStringContainsString('</tbody></table></div>', $html);

        $report['checks'] = [self::row('h', 'pass', 3, 'onpage'), self::row('e', 'not_measured', 3, 'speed')];
        $html = Pdf::html($report);
        $this->assertStringNotContainsString('class="top"', $html);
        $this->assertStringContainsString('<h2>Priority fixes</h2><p class="note">Nothing to fix: every check that ran passed.</p>', $html);
    }

    public function test_speed_is_one_section_with_googles_measures_and_halo_audits_score(): void
    {
        $html = Pdf::html($this->comparison());
        // The section: its heading (with both pages' Halo Audit Speed score), one card per measure, the score row, the note.
        $this->assertSame(1, preg_match('#' . preg_quote(self::title('Speed'), '#') . '(.*?)<p class="note">#s', $html, $table));
        $this->assertMatchesRegularExpression('#^<span class="band-meta">&nbsp;&nbsp;&middot;&nbsp;&nbsp;Halo Audit Speed score: your page \d+ / 100 &middot; competitor 0 / 100</span></h2><table class="fc"#', $table[1]);
        // Each measure says once whose it is and its unit; each page's cell, labelled, holds the value.
        $metrics = ['Google Lighthouse Performance, 0 to 100' => 'performance', 'Google metric: Largest Contentful Paint (LCP), in seconds' => 'lcp',
            'Google metric: Cumulative Layout Shift (CLS), no unit' => 'cls'];
        foreach ($metrics as $label => $what) {
            $this->assertStringContainsString('<div class="metric">' . $label . '</div>', $table[1]);
            $this->assertMatchesRegularExpression('#<td class="res2"><div class="fc-lbl">Your page</div><div><span class="chip chip-[a-z]+">(?:<span class="fb">[^<]*</span>)?[^<]*</span> <span class="evidence">MINE ' . $what . '</span></div></td>'
                . '<td class="res2"><div class="fc-lbl">Competitor</div><div><span class="chip chip-fail"><span class="fb">✗</span> Fix</span> <span class="evidence">THEIRS ' . $what . '</span></div>#', $table[1]);
        }
        $this->assertSame(3, substr_count($table[1], '<table class="fc"'), 'One card per Google measure.');
        $this->assertStringNotContainsString('<div class="why">Why psi_score</div>', $table[1], 'No second explanation under a speed measure.');
        $this->assertMatchesRegularExpression('#<strong>Halo Audit Speed score</strong><div class="metric">From the results above. Halo Audit&rsquo;s own score, not Google&rsquo;s</div></td><td class="halo halo-score">\d+ / 100</td><td class="halo halo-score">0 / 100</td>#', $table[1]);
        $this->assertSame(1, substr_count($html, 'not field data from real visitors'), 'One note on where the figures come from.');
        $this->assertStringContainsString('weighted 3 for the Performance score, 2 for LCP and 2 for CLS. It is not Google&rsquo;s Performance score.', $html);

        // Printed once: no second Speed table among the findings.
        $this->assertSame(1, substr_count($html, 'MINE lcp'));
        $this->assertSame(1, substr_count($html, self::title('Speed')), 'One Speed section.');

        // The stored Lighthouse sentence is printed as its number out of 100; anything else as stored.
        $this->assertSame('82 / 100', Pdf::lighthouseValue('82 out of 100 (Google Lighthouse, mobile lab test)'));
        $this->assertSame('0 / 100', Pdf::lighthouseValue('0 out of 100 (Google Lighthouse, mobile lab test)'));
        $this->assertSame('Google PageSpeed did not return a result', Pdf::lighthouseValue('Google PageSpeed did not return a result'));

        // A single page has one result column; a page with no speed rows has no speed section.
        $single = $this->comparison();
        unset($single['competitor']);
        $this->assertSame(1, preg_match('#' . preg_quote(self::title('Speed'), '#') . '(.*?)<p class="note">#s', Pdf::html($single), $one));
        $this->assertStringNotContainsString('Competitor', $one[1]);
        $this->assertMatchesRegularExpression('#<td class="res"><span class="chip chip-warn">! Improve</span> <span class="evidence">MINE performance</span></td>#', $one[1]);
        $this->assertStringNotContainsString(self::title('Speed'), Pdf::html($this->report()));
    }

    public function test_what_to_do_is_a_row_joined_to_its_check(): void
    {
        $html = Pdf::html($this->comparison());
        // A check with a fix: one card (a table mPDF never splits) holds the check, both results and the fix. Plain
        // rows as in the reference: no coloured stripe, the status is in the badge.
        $this->assertMatchesRegularExpression('#<table class="fc" autosize="1"><tr><td class="check"><strong>Title llms_txt</strong>[^\n]*?</tr>'
            . '<tr><td class="fix" colspan="3"><strong class="fix-label">What to do on your page:</strong> Do llms_txt</td></tr></table>#', $html);
        $this->assertStringNotContainsString('fcb-', $html);
        // The competitor's fix is never printed: the advice is for your page.
        $this->assertStringNotContainsString('Do https', $html);
        // A check your page passes has no fix row.
        $this->assertMatchesRegularExpression('#<table class="fc" autosize="1"><tr><td class="check"><strong>Title https</strong>.*?</tr></table>#', $html);
        // One page: the fix spans the check and its result.
        $single = $this->report();
        $this->assertStringContainsString('<td class="fix" colspan="2"><strong class="fix-label">What to do:</strong> Fix it.</td></tr></table>', Pdf::html($single));
    }

    /**
     * Every section title stays with the table right after it: a heading kept with its table, or, before a finding
     * card, a block of heading and card that is never split. Only the column heads (with each page's score) repeat on
     * the pages a table runs onto, so the title is never repeated or left alone at a page foot.
     */
    public function test_section_headings_are_kept_with_their_tables_and_only_column_heads_repeat(): void
    {
        foreach ([$this->report(), $this->comparison()] as $report) {
            $html = Pdf::html($report);
            // The comparison fixture has none left: every area opens with a card and no check passed on both pages.
            preg_match_all('#<(h[23]) keep-with-table="1">(.*?)</\1>(.{0,6})#s', $html, $headings, PREG_SET_ORDER);
            $this->assertSame(isset($report['competitor']), $headings === []);
            foreach ($headings as [, $tag, $inner, $after]) {
                // The first letter alone in a span (see Pdf::heading()), the rest plain text, then the scores if any.
                $this->assertMatchesRegularExpression('#^<span class="' . $tag . '">[^<]</span>[^<]*(<span class="band-meta">[^<]*</span>)?$#u', $inner);
                $this->assertSame('<table', $after, 'A kept heading is followed by its table.');
            }
            // A section heading that is not kept opens a keep-first block, straight before the table it stays with.
            $plain = preg_match_all('#<h2>#', $html);
            $this->assertSame($plain - substr_count($html, '<h2>Priority fixes</h2>'),
                preg_match_all('#<div class="keep-first"><h2><span class="h2">[^<]</span>[^<]*(<span class="band-meta">[^<]*</span>)?</h2><table class="(fc|area|top)"#u', $html));
            // No thead holds a section title or a nested table (a nested table stops mPDF repeating it).
            preg_match_all('#<thead>(.*?)</thead>#s', $html, $heads);
            foreach ($heads[1] as $head) {
                $this->assertStringNotContainsString('<table', $head);
                $this->assertStringNotContainsString('h2', $head);
            }
        }
        $html = Pdf::html($this->report());
        $this->assertStringContainsString(self::withCard('On-page for your keyword', 'Halo Audit score 0 / 100'), $html);
        // An area where everything passed: its heading goes straight onto the compact list.
        $this->assertStringContainsString(self::heading('Technical', 'h2', 'Halo Audit score 100 / 100') . '<table class="passed"><thead><tr><th class="p-check">Passed check</th><th>Result</th></tr></thead>', $html);
        $compare = Pdf::html($this->comparison());
        $this->assertStringContainsString(self::withCard('AI readiness', 'Your page 50 / 100 &middot; competitor 0 / 100'), $compare);
    }

    /**
     * Each area lists the checks that need a look first (your Fix, then Improve, then not measured, then the rest,
     * keeping the report's order within each), then the checks that passed in a compact list. Every check appears
     * exactly once, with its own status, evidence and fix.
     */
    public function test_problems_come_first_and_every_check_is_printed_once_with_its_own_result(): void
    {
        $report = $this->report();
        $report['checks'] = [
            self::row('a', 'pass', 3, 'onpage'),
            self::row('b', 'warn', 2, 'onpage'),
            self::row('c', 'not_measured', 2, 'onpage'),
            self::row('d', 'fail', 1, 'onpage'),
            self::row('e', 'pass', 2, 'onpage'),
            self::row('f', 'fail', 3, 'onpage'),
        ];
        $report['summary'] = Scorer::score($report['checks']);
        $html = Pdf::html($report);

        $rows = Halo_Pdf_Rows::single($html);
        $this->assertSame(['Title d', 'Title f', 'Title b', 'Title c', 'Title a', 'Title e'], array_keys($rows));
        // Once among the findings (a Fix or Improve is also named in the priority list, with its area).
        $findings = (string) preg_replace('#<table class="top".*?</table>#s', '', $html);
        foreach ($report['checks'] as $check) {
            $this->assertSame(1, substr_count($findings, '<td class="check"><strong>' . $check['title'] . '</strong>'), $check['id']);
            [$label, $evidence, $fix] = $rows[$check['title']];
            $this->assertSame(self::STATUS_TEXT[$check['status']], $label, $check['id']);
            $this->assertSame($check['evidence'], $evidence, $check['id']);
            $this->assertSame($check['message'], $fix, $check['id']);
        }
        // The passed checks are in their own compact table, after the ones that need a look; no "why" there.
        $this->assertSame(1, preg_match('#' . preg_quote(self::heading('Passed: 2 checks', 'h3'), '#') . '<table class="passed">(.*?)</table>#s', $html, $passed));
        $this->assertStringContainsString('Title a', $passed[1]);
        $this->assertStringContainsString('Title e', $passed[1]);
        $this->assertStringNotContainsString('class="why"', $passed[1]);
        $this->assertStringNotContainsString('chip-fail', $passed[1]);

        // A comparison: a check passed on both pages goes to the compact list; passed on one page only stays in full.
        $compare = $this->comparison();
        $compare['competitor']['checks'][1]['status'] = 'pass'; // https: pass on both now
        $compare['competitor']['summary'] = Scorer::score($compare['competitor']['checks']);
        $html = Pdf::html($compare);
        $this->assertSame(1, preg_match('#' . preg_quote(self::heading('Technical', 'h2', 'Your page 100 / 100 &middot; competitor 100 / 100'), '#') . '<table class="passed">(.*?)</table>#s', $html, $both));
        $this->assertStringContainsString('<strong>Title https</strong></td><td class="res"><span class="chip chip-pass"><span class="fb">✓</span> Pass</span> <span class="evidence">MINE https</span></td><td class="res"><span class="chip chip-pass"><span class="fb">✓</span> Pass</span> <span class="evidence">THEIRS https</span>', $both[1]);
        $this->assertCount(count(\HaloAudit\ReportView::pairs($compare['checks'], $compare['competitor']['checks'])), Halo_Pdf_Rows::comparison($html));
        // Your page passes, the competitor does not: printed in full, with the reason.
        $this->assertStringContainsString('<strong>Title title_length</strong><div class="why">Why title_length</div>', $html);
    }

    private const STATUS_TEXT = ['pass' => '✓ Pass', 'warn' => '! Improve', 'fail' => '✗ Fix', 'not_measured' => '○ Not measured'];

    /**
     * A long address gets line breaks where a line is full (D4) and nothing else: no invisible break characters
     * (zero-width space, soft hyphen) and no <wbr> (mPDF prints it as a hyphen). Without the breaks it is exactly
     * as stored, escaped.
     */
    public function test_addresses_are_written_as_they_are(): void
    {
        $report = $this->report();
        $report['url'] = 'https://www.example.com/admissions/postgraduate/2026/international-applicants/master-of-business-administration-full-time/?ref=a&lang=en-GB';
        $report['checks'][1]['evidence'] = 'Canonical points to ' . $report['url'];
        $raw = Pdf::html($report);
        $this->assertStringContainsString('<br />', $raw);
        foreach (["\u{200B}", "\u{00AD}", '<wbr', "\u{E000}"] as $invisible) {
            $this->assertStringNotContainsString($invisible, $raw);
        }
        $html = str_replace('<br />', '', $raw);
        $this->assertStringContainsString('<td class="meta-value">' . esc_html($report['url']) . '</td>', $html);
        $this->assertStringContainsString(' <span class="evidence">Canonical points to ' . esc_html($report['url']) . '</span>', $html);
    }

    public function test_long_text_unicode_and_a_large_report_render(): void
    {
        $report = $this->comparison();
        $report['url'] = 'https://www.example.com/' . str_repeat('a-very-long-path-segment/', 60) . '?q=' . str_repeat('x', 300);
        $report['keyword'] = 'café “MBA” & <ü> ✓ प्रवेश 商学院 😀';
        $report['checks'][2]['message'] = str_repeat('A long recommendation that has to wrap over many lines. ', 40);
        $checks = [];
        foreach (range(1, 40) as $n) {
            foreach ($report['checks'] as $row) {
                $checks[] = ['id' => $row['id'] . '_' . $n] + $row;
            }
        }
        $report['checks'] = $checks;
        $report['summary'] = Scorer::score($checks);
        $pdf = Pdf::render($report);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(10, self::pageCount($pdf));
        $this->assertStringContainsString('café “MBA” &amp; &lt;ü&gt;', Pdf::html($report));
    }

    public function test_missing_optional_fields_render(): void
    {
        // One page: the optional keys are absent.
        $single = $this->report();
        foreach ($single['checks'] as $i => $row) {
            unset($single['checks'][$i]['why'], $single['checks'][$i]['message'], $single['checks'][$i]['evidence']);
        }
        $this->assertStringStartsWith('%PDF-', Pdf::render($single));

        // A comparison (paired by ReportView, which reads every key): the optional values are empty.
        $report = $this->comparison();
        foreach ($report['checks'] as $i => $row) {
            $report['checks'][$i]['why'] = $report['checks'][$i]['message'] = $report['checks'][$i]['evidence'] = '';
        }
        $report['keyword'] = '';
        $report['competitor']['checks'] = [$report['competitor']['checks'][0]];
        $report['competitor']['summary'] = Scorer::score($report['competitor']['checks']);
        $report['competitor']['speed_done'] = false;
        $report['summary']['grade'] = null;

        $html = Pdf::html($report);
        $this->assertStringContainsString('<h1>SEO comparison report</h1>', $html, 'No keyword: the title is the report type.');
        $this->assertStringNotContainsString('>Keyword<', $html);
        $this->assertStringContainsString('&ndash; Not checked', $html);
        $this->assertStringContainsString('Competitor page: the speed test had not finished when this PDF was made', $html);
        $this->assertStringStartsWith('%PDF-', Pdf::render($report));
    }

    public function test_devanagari_runs_are_tagged_for_their_font_and_latin_text_is_not(): void
    {
        $report = $this->report();
        $report['keyword'] = 'हिंदी SEO ऑडिट रिपोर्ट <b>';
        $report['checks'][0]['message'] = 'कीवर्ड रखें, अङ्ग। Put the keyword first.';
        $html = Pdf::html($report);
        // Escaped first, then tagged: the markup from the page stays text.
        $this->assertStringContainsString('&ldquo;<span lang="hi">हिंदी</span> SEO <span lang="hi">ऑडिट रिपोर्ट</span> &lt;b&gt;&rdquo;', $html);
        // The danda belongs to the Devanagari run, so it is not left to DejaVu (which has no glyph for it).
        $this->assertStringContainsString('<span lang="hi">कीवर्ड रखें</span>, <span lang="hi">अङ्ग।</span> Put the keyword first.', $html);
        $this->assertStringNotContainsString('lang=', Pdf::html($this->report()));
        $this->assertSame('café “MBA” &amp; 2026', \HaloAudit\Wp\PdfFonts::tag('café “MBA” &amp; 2026'));
    }

    public function test_only_devanagari_gets_the_second_font_and_a_missing_file_falls_back(): void
    {
        $fontDir = dirname(__DIR__, 2) . '/vendor/mpdf/mpdf/ttfonts';
        $fonts = new \HaloAudit\Wp\PdfFonts($fontDir);
        $this->assertSame([false, 'halodeva'], $fonts->getLanguageOptions('hi', false));
        foreach (['und-Latn', 'zh-CN', 'ta', 'bn', 'ar', 'ru', ''] as $lang) {
            $this->assertSame([false, ''], $fonts->getLanguageOptions($lang, false), $lang);
        }
        $this->assertSame([false, ''], (new \HaloAudit\Wp\PdfFonts(sys_get_temp_dir() . '/no-fonts-here'))->getLanguageOptions('hi', false));
        $this->assertSame(['halodeva' => ['R' => 'FreeSans.ttf', 'B' => 'FreeSans.ttf', 'useOTL' => 0xFF]], \HaloAudit\Wp\PdfFonts::fontdata());
    }

    public function test_a_hindi_report_embeds_freesans_and_a_latin_one_does_not(): void
    {
        $report = $this->comparison();
        $report['keyword'] = 'हिंदी SEO ऑडिट रिपोर्ट';
        $report['url'] = 'https://उदाहरण.भारत/प्रवेश/';
        $pdf = Pdf::render($report);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(3, self::pageCount($pdf), 'As the Latin comparison: dashboard, then priority fixes and details.');
        $this->assertMatchesRegularExpression('#/BaseFont /[A-Z]+\+FreeSans\b#', $pdf);
        $this->assertDoesNotMatchRegularExpression('#/BaseFont /[A-Z]+\+FreeSans\b#', Pdf::render($this->comparison()));
    }

    public function test_a_section_measured_on_neither_page_says_so_once(): void
    {
        $report = $this->comparison();
        $notMeasured = static fn(array $rows): array => array_map(static fn(array $r): array => $r['group'] === 'ai'
            ? ['status' => 'not_measured', 'message' => ''] + $r : $r, $rows);
        $report['checks'] = $notMeasured($report['checks']);
        $report['competitor']['checks'] = $notMeasured($report['competitor']['checks']);
        $report['summary'] = Scorer::score($report['checks']);
        $report['competitor']['summary'] = Scorer::score($report['competitor']['checks']);
        $html = Pdf::html($report);
        $this->assertStringContainsString(self::withCard('AI readiness', 'Not measured on either page'), $html);
        $this->assertStringNotContainsString('Your page not measured &middot; competitor not measured', $html);

        // Speed measured on neither page: no number anywhere in its table, "Not measured" for both pages' Speed score.
        $speed = $this->comparison();
        $noSpeed = static fn(array $rows): array => array_map(static fn(array $r): array => $r['group'] === 'speed'
            ? ['status' => 'not_measured', 'message' => '', 'evidence' => 'Google PageSpeed did not return a result'] + $r : $r, $rows);
        $speed['checks'] = $noSpeed($speed['checks']);
        $speed['competitor']['checks'] = $noSpeed($speed['competitor']['checks']);
        $speed['summary'] = Scorer::score($speed['checks']);
        $speed['competitor']['summary'] = Scorer::score($speed['competitor']['checks']);
        $html = Pdf::html($speed);
        $this->assertSame(1, preg_match('#' . preg_quote(self::title('Speed'), '#') . '(.*?)<p class="note">#s', $html, $table));
        $this->assertStringContainsString('<td class="halo halo-score">Not measured</td><td class="halo halo-score">Not measured</td>', $table[1]);
        $this->assertSame(0, preg_match('#\d+ / 100#', $table[1]), 'No number when nothing was measured.');
        $this->assertSame(6, substr_count($table[1], '<span class="chip chip-not_measured"><span class="fb">○</span> Not measured</span>'));
        // Page 1's Speed card says so too, and its ring-free card shows no number.
        $this->assertMatchesRegularExpression('#<div class="c-name">Speed</div><div style="font-size:3pt;line-height:1">&nbsp;</div><div class="c-counts">.*?</div></td><td class="ca-score"><div class="c-none">Not measured</div>#s', $html);
    }

    public function test_html_loads_nothing_from_outside(): void
    {
        // Brand off: the logos and CTA link have their own test (test_branded_pdf_embeds_only_the_bundled_logos).
        add_filter('halo_audit_pdf_brand', '__return_false');
        // The only images are the report's own score rings and bars, inline SVG with no reference to anything.
        $html = $this->withoutOwnGraphics(Pdf::html($this->comparison()));
        foreach (['<img', '<link', 'src="', 'href="', 'url(', '@import'] as $remote) {
            $this->assertStringNotContainsString($remote, $html);
        }
    }

    /** A 1x1 PNG and a stylesheet on disk, so a test can tell whether mPDF read them. */
    private static function localAssets(): array
    {
        $dir = trailingslashit(get_temp_dir()) . 'halo-pdf-test-' . wp_generate_password(8, false);
        wp_mkdir_p($dir);
        $png = $dir . '/dot.png';
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        $css = $dir . '/leak.css';
        file_put_contents($css, 'p{font-size:40pt}');
        return [$dir, $png, $css];
    }

    /**
     * The renderer itself refuses every file and URL, whatever the markup says: no image, stylesheet or @import is
     * read, by path, file://, http(s) or phar://. The report's own markup never holds such a reference (every value
     * is escaped), so this is the second lock. Nothing goes to the network: a URL is refused before any request.
     */
    public function test_the_renderer_reads_no_file_or_url_whatever_the_markup_says(): void
    {
        [$dir, $png, $css] = self::localAssets();
        $markup = '<p>x</p><img src="' . $png . '"><img src="file://' . $png . '"><img src="http://127.0.0.1:9/a.png"><img src="https://example.invalid/a.png">'
            . '<img src="phar://' . $png . '/x.png"><link rel="stylesheet" href="' . $css . '"><link rel="stylesheet" href="http://127.0.0.1:9/a.css">'
            . '<style>@import url(' . $css . '); @import url(http://127.0.0.1:9/b.css);</style>'
            . '<div style="background-image:url(' . $png . ')">y</div><p style="background:url(file:///etc/hostname)">z</p>';

        $engine = new ReflectionMethod(Pdf::class, 'engine');
        $engine->setAccessible(true);
        $mpdf = $engine->invoke(null, $this->report());
        $mpdf->WriteHTML($markup);
        $pdf = $mpdf->Output('', 'S');

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringNotContainsString('/Subtype /Image', $pdf, 'No image was embedded.');
        // The plain paths reached mPDF's loader and were refused there; the URLs never got that far.
        $this->assertContains($png, Pdf::refusedFetches());
        foreach (Pdf::refusedFetches() as $path) {
            $this->assertStringNotContainsString('127.0.0.1', $path, 'No URL reached the HTTP client.');
        }

        // Control: stock mPDF reads the same file, so the assertions above can fail.
        $stock = new \Mpdf\Mpdf(['tempDir' => trailingslashit(get_temp_dir()) . 'halo-audit-mpdf']);
        $stock->WriteHTML('<img src="' . $png . '">');
        $this->assertStringContainsString('/Subtype /Image', $stock->Output('', 'S'));

        unlink($png);
        unlink($css);
        rmdir($dir);
    }

    /** Values from the audited page that look like markup, CSS or addresses stay text: nothing is fetched. */
    public function test_report_values_cannot_make_the_pdf_fetch_anything(): void
    {
        [$dir, $png, $css] = self::localAssets();
        add_filter('halo_audit_pdf_brand', '__return_false'); // Brand off: the bundled logos are tested on their own.
        $report = $this->comparison();
        $report['keyword'] = '<img src="' . $png . '">';
        $report['url'] = 'https://example.com/"><img src="http://127.0.0.1:9/u.png">';
        $report['checks'][0]['evidence'] = '<link rel="stylesheet" href="' . $css . '"><style>@import url(' . $css . ')</style>';
        $report['checks'][2]['message'] = '<div style="background:url(file://' . $png . ')">x</div> url(' . $png . ')';
        $report['competitor']['checks'][1]['evidence'] = 'file:///etc/passwd phar://' . $png . ' <img src=x onerror=alert(1)>';

        $html = $this->withoutOwnGraphics(Pdf::html($report));
        foreach (['<img', '<link', '<style', 'src="', 'href="', 'style="background'] as $markup) {
            $this->assertStringNotContainsString($markup, $html, $markup);
        }
        $pdf = Pdf::render($report);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame([], Pdf::refusedFetches(), 'Nothing was even asked for.');
        $this->assertStringNotContainsString('/Subtype /Image', $pdf);

        unlink($png);
        unlink($css);
        rmdir($dir);
    }

    /** The download name: the audited host(s) and the report date (UTC), safe for a header whatever the report holds. */
    public function test_filename_names_the_site_and_the_report_date(): void
    {
        $day = gmmktime(23, 30, 0, 10, 22, 2026); // 22 Oct 2026 23:30 UTC
        $single = ['url' => 'https://www.Example.com/a/b?x=1', 'created_at' => $day];
        $this->assertSame('halo-audit-example.com-2026-10-22.pdf', Pdf::filename($single));

        $pair = $single + ['competitor' => ['url' => 'http://rival.example.org/']];
        $this->assertSame('halo-audit-example.com-vs-rival.example.org-2026-10-22.pdf', Pdf::filename($pair));

        // Characters a host cannot normally hold still never reach the header.
        foreach (['https://a"b;c.com/', "https://evil.com%0d%0aSet-Cookie:x/", 'https://ex ample..com/', 'https://-x-.com/'] as $url) {
            $name = Pdf::filename(['url' => $url, 'created_at' => $day]);
            $this->assertMatchesRegularExpression('/^halo-audit(-[a-z0-9.-]+)*\.pdf$/', $name, $url);
            $this->assertDoesNotMatchRegularExpression('/["; \r\n]/', $name);
        }
        $this->assertSame(function_exists('idn_to_ascii') ? 'halo-audit-xn--bcher-kva.example-2026-10-22.pdf' : 'halo-audit-2026-10-22.pdf',
            Pdf::filename(['url' => 'https://bücher.example/', 'created_at' => $day]));

        // Each host is cut to 60 characters; the whole name stays short.
        $long = Pdf::filename(['url' => 'https://' . str_repeat('a', 63) . '.' . str_repeat('b', 63) . '.com/', 'created_at' => $day,
            'competitor' => ['url' => 'https://' . str_repeat('c', 63) . '.example/']]);
        $this->assertLessThanOrEqual(160, strlen($long));
        $this->assertStringEndsWith('-2026-10-22.pdf', $long);

        // Missing data falls back step by step, to the constant name at the end.
        $this->assertSame('halo-audit-example.com.pdf', Pdf::filename(['url' => 'https://example.com/']));
        $this->assertSame('halo-audit-2026-10-22.pdf', Pdf::filename(['url' => 'not a url', 'created_at' => $day]));
        $this->assertSame(Pdf::FILENAME, Pdf::filename([]));
        $this->assertSame('halo-audit.pdf', Pdf::FILENAME);
    }

    /**
     * Regression (23 Oct 2026, owner decision): with no filter at all, every PDF, single and comparison, carries the
     * Thrivemattic logo (cover and running header), Author, credit and the approved CTA with its contact link. Only a
     * filter returning false gives the neutral PDF (HALO AUDIT text mark, no image, no CTA, no Author).
     */
    public function test_thrivemattic_brand_is_on_by_default_and_a_filter_can_switch_it_off(): void
    {
        foreach ([$this->report(), $this->comparison()] as $report) {
            $brand = Pdf::brand($report);
            $this->assertTrue($brand['logo']);
            $this->assertSame('Thrivemattic', $brand['author']);
            $this->assertSame(Pdf::DEFAULT_BRAND['cta'], $brand['cta']);
            $html = Pdf::html($report);
            $this->assertStringContainsString('data:image/png;base64,' . base64_encode((string) file_get_contents(SEO_ANALYZER_PLUGIN_DIR . 'assets/images/logo-white.png')), $html);
            $this->assertSame(1, substr_count($html, '<a href="https://thrivemattic.com/contact/"'));
            $this->assertStringContainsString('>Book a Discovery Call</a>', $html);
            $pdf = Pdf::render($report);
            $this->assertSame(4, substr_count($pdf, '/Subtype /Image'), 'Two logos, each with its soft mask.');
            $this->assertSame(1, substr_count($pdf, '/URI (https://thrivemattic.com/contact/)'));
            $this->assertSame('Thrivemattic', self::info($pdf, 'Author'));
            $this->assertSame('Report generated by thrivemattic.com with Halo Audit', Pdf::credit($report));
        }

        add_filter('halo_audit_pdf_brand', '__return_false');
        $report = $this->comparison();
        $this->assertSame(['logo' => false, 'cta' => null, 'author' => ''], Pdf::brand($report));
        $html = Pdf::html($report);
        $this->assertStringNotContainsString('data:image/png', $html);
        $this->assertStringNotContainsString('class="cta"', $html);
        $this->assertStringContainsString('<td class="brand">HALO AUDIT</td>', $html);
        $pdf = Pdf::render($report);
        $this->assertStringNotContainsString('/Subtype /Image', $pdf, 'A neutral PDF embeds no image.');
        $this->assertStringNotContainsString('/Author', $pdf);
        remove_filter('halo_audit_pdf_brand', '__return_false');
    }

    /** Only true switches the logo on, only the bundled files are used, and CTA values are plain, capped and checked. */
    public function test_brand_filter_accepts_only_safe_values(): void
    {
        $report = $this->report();
        foreach (['/etc/passwd', 'https://evil.example/logo.png', 'data:image/png;base64,AAAA', 1, 'yes', ['x']] as $junk) {
            $filter = static fn() => ['logo' => $junk, 'author' => ['not text']];
            add_filter('halo_audit_pdf_brand', $filter);
            $this->assertSame(['logo' => false, 'cta' => null, 'author' => ''], Pdf::brand($report), var_export($junk, true));
            remove_filter('halo_audit_pdf_brand', $filter);
        }

        $filter = static fn() => ['logo' => true, 'author' => ' <b>Site</b> owner ', 'cta' => [
            'eyebrow' => 'Halo', 'title' => '<script>x</script>Fix the site ' . str_repeat('a', 200), 'text' => "Two\n lines",
            'button' => 'Book', 'url' => 'javascript:alert(1)',
        ]];
        add_filter('halo_audit_pdf_brand', $filter);
        $brand = Pdf::brand($report);
        $html = Pdf::html($report);
        remove_filter('halo_audit_pdf_brand', $filter);

        $this->assertTrue($brand['logo']);
        $this->assertSame('Site owner', $brand['author']);
        $this->assertSame(80, mb_strlen($brand['cta']['title']));
        $this->assertStringNotContainsString('<script>', $brand['cta']['title']);
        $this->assertSame('Two lines', $brand['cta']['text']);
        $this->assertSame('', $brand['cta']['url'], 'A javascript: address gives no link.');
        $this->assertStringContainsString('class="cta"', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<script>', $html);

        $filter = static fn() => ['cta' => ['title' => 'Talk', 'button' => 'Book', 'url' => 'https://example.com/contact/']];
        add_filter('halo_audit_pdf_brand', $filter);
        $html = Pdf::html($report);
        remove_filter('halo_audit_pdf_brand', $filter);
        $this->assertSame(1, substr_count($html, '<a href="https://example.com/contact/"'));
        $this->assertStringNotContainsString('data:image/png', $html, 'A CTA alone does not switch the logo on.');
    }

    /** A value of the PDF's Info dictionary (mPDF writes it as a UTF-16BE string with a byte order mark), or null. */
    private static function info(string $pdf, string $key): ?string
    {
        if (!preg_match('#/' . $key . ' \((.*?)(?<!\\\\)\)\s*/#s', $pdf, $m)) {
            return null;
        }
        $raw = (string) preg_replace_callback('#\\\\([\\\\()])#', static fn($x) => $x[1], $m[1]);
        return strncmp($raw, "\xFE\xFF", 2) === 0 ? mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE') : $raw;
    }

    /** Title metadata names the report type on both render paths; the author is Thrivemattic unless a filter changes it. */
    public function test_pdf_title_and_author_metadata(): void
    {
        $this->assertSame('Halo Audit: SEO audit report', self::info(Pdf::render($this->report()), 'Title'));
        $this->assertSame('Halo Audit: SEO comparison report', self::info(Pdf::render($this->comparison()), 'Title'));
        $this->assertSame('Halo Audit: SEO audit report', Pdf::title($this->report()));
        $this->assertSame('Thrivemattic', self::info(Pdf::render($this->report()), 'Author'));
        add_filter('halo_audit_pdf_brand', '__return_false');
        $this->assertNull(self::info(Pdf::render($this->report()), 'Author'), 'No author when the brand is switched off.');
        remove_filter('halo_audit_pdf_brand', '__return_false');

        $filter = static fn() => ['author' => 'Thrivemattic'];
        add_filter('halo_audit_pdf_brand', $filter);
        $single = Pdf::render($this->report());
        $comparison = Pdf::render($this->comparison());
        remove_filter('halo_audit_pdf_brand', $filter);
        foreach ([$single, $comparison] as $pdf) {
            $this->assertSame('Thrivemattic', self::info($pdf, 'Author'));
            $this->assertSame('Halo Audit', self::info($pdf, 'Creator'));
            $this->assertStringNotContainsString(ABSPATH, $pdf, 'No server path in the file.');
            $this->assertStringNotContainsString('FAKE', $pdf);
        }
    }

    /**
     * The closing call to action a site supplies (here the copy Thrivemattic's site plugin sends) is the last block
     * after the findings, has one link to its address, and is never split across pages.
     */
    public function test_closing_cta_is_the_last_block_with_one_link(): void
    {
        $cta = [
            'eyebrow' => 'Halo · Thrivemattic', 'title' => 'Fix this page, then the rest of the site',
            'text' => 'This report checks one page. Halo audits up to 2,000 pages of your site every night, tracks your rankings, and checks what ChatGPT, Perplexity, Gemini, Claude and AI Overviews say about you. In a 30-minute call we walk through these findings and what to fix first.',
            'button' => 'Book a Discovery Call', 'url' => 'https://thrivemattic.com/contact/',
        ];
        $filter = static fn() => ['cta' => $cta];
        add_filter('halo_audit_pdf_brand', $filter);
        foreach ([$this->report(), $this->comparison()] as $report) {
            $html = Pdf::html($report);
            $pdf = Pdf::render($report);
            $this->assertSame(1, preg_match('#<table class="cta" autosize="1"[^>]*page-break-inside:avoid.*</table>$#s', $html), 'The CTA ends the report.');
            $this->assertSame(1, substr_count($html, '<a href="https://thrivemattic.com/contact/"'));
            $this->assertStringContainsString('>Book a Discovery Call</a>', $html);
            $this->assertStringContainsString('Fix this page, then the rest of the site', $html);
            $this->assertStringContainsString('HALO · THRIVEMATTIC', $html);
            // D2: spacer lines between eyebrow, title and text (mPDF ignores margins inside a cell); 7 pt under the heading.
            $this->assertMatchesRegularExpression('#HALO · THRIVEMATTIC</div><div style="font-size:4pt;line-height:1">&nbsp;</div><div style="font-family:[a-z]+;[^"]*">Fix this page, then the rest of the site</div>'
                . '<div style="font-size:7pt;line-height:1">&nbsp;</div><div style="font-size:9pt;#u', $html);
            $this->assertSame(1, substr_count($pdf, '/URI (https://thrivemattic.com/contact/)'), 'One link annotation to the contact page.');
        }
        remove_filter('halo_audit_pdf_brand', $filter);
    }

    /** The cover as in the reference: the report type in the eyebrow, the keyword in the title, plain labels. */
    public function test_cover_title_names_the_keyword(): void
    {
        $html = Pdf::html($this->report());
        $this->assertStringContainsString('<h1>How your page scores for &ldquo;&lt;b&gt;mba&lt;/b&gt;&rdquo;</h1>', $html);
        // With the logo (the default) the eyebrow sits under it; without one it takes the logo's place in the top row.
        $this->assertStringContainsString('<div class="eyebrow eyebrow-below">SEO audit report</div>', $html);
        add_filter('halo_audit_pdf_brand', '__return_false');
        $this->assertStringContainsString('<td class="eyebrow">SEO audit report</td>', Pdf::html($this->report()));
        remove_filter('halo_audit_pdf_brand', '__return_false');
        $this->assertStringNotContainsString('Executive summary', $html);
        $this->assertStringNotContainsString('class="lede"', $html, 'The title already says what the lede said.');
        $this->assertStringContainsString('<td class="meta-label">Date</td>', $html);
        $this->assertStringContainsString('<h1>How your page compares for &ldquo;', Pdf::html($this->comparison()));
        $report = $this->report();
        $report['keyword'] = '';
        $this->assertStringContainsString('<h1>SEO audit report</h1>', Pdf::html($report));
    }

    /** D1: a spacer line parts "out of 100" and the grade badge (mPDF ignores margins inside a cell). */
    public function test_grade_badge_has_space_above_it(): void
    {
        $html = Pdf::html($this->comparison());
        $this->assertSame(2, preg_match_all('#<div class="sp-of">[^<]*</div><div style="font-size:3pt;line-height:1">&nbsp;</div><div class="grade grade-[a-f]">#', $html));
    }

    /**
     * D4: a long address breaks only after "/", "?", "&", "=", "." or "-", never inside a word, and only where a line
     * is full: an address that fits keeps no break. Hosts in the narrow score panel too. A part wider than a whole
     * line is left to overflow:wrap, and the layout always ends (a 300-character run with no break point).
     */
    public function test_long_addresses_break_only_at_safe_characters(): void
    {
        $report = $this->comparison();
        $report['url'] = 'https://www.zelleducation-long.example/admissions/postgraduate/2026/international-applicants/master-of-business-administration-full-time/?ref=newsletter&lang=en-GB';
        $report['competitor']['url'] = 'https://www.a-very-long-competitor-hostname.example-school.edu/';
        $report['checks'][0]['evidence'] = 'Canonical points to ' . $report['url'];
        $report['competitor']['checks'][0]['evidence'] = 'https://short.example/a';
        $html = Pdf::html($report);
        preg_match_all('#(.)<br />(.)#u', $html, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m);
        foreach ($m as [$all, $before, $after]) {
            $this->assertMatchesRegularExpression('#[/?&;=.\-]#', $before, 'Break after a safe character: ' . $all); // "&" is escaped as "&amp;".
        }
        $this->assertStringContainsString('<span class="evidence">https://short.example/a</span>', $html, 'An address that fits keeps no break.');
        $this->assertSame(1, preg_match('#<div class="sp-host">(www\.a-very[^<]*(?:<br />[^<]*)+)</div>#', $html, $host), 'The long host breaks in the score panel.');
        $this->assertSame('www.a-very-long-competitor-hostname.example-school.edu', str_replace('<br />', '', $host[1]));
        foreach (["\u{200B}", "\u{00AD}", "\u{E000}"] as $invisible) {
            $this->assertStringNotContainsString($invisible, $html);
        }
        $this->assertStringNotContainsString('<br />', Pdf::filename($report) . Pdf::title($report));
        $this->assertDoesNotMatchRegularExpression('#href="[^"]*<br#', $html);

        // Report data cannot bring its own break: the marker character is removed before layout.
        $report['checks'][0]['evidence'] = "Look \u{E000}<b>here</b>";
        $this->assertStringContainsString('Look &lt;b&gt;here&lt;/b&gt;', Pdf::html($report));

        // A run with no break point that is wider than a line: written as it is, and html() returns.
        $report['checks'][0]['evidence'] = 'https://x.example/' . str_repeat('a', 300);
        $this->assertStringContainsString(str_repeat('a', 300), Pdf::html($report));
    }

    /** A long keyword makes the cover title smaller (21 or 19 pt) instead of wrapping it; a short one keeps 24 pt. */
    public function test_cover_title_size_follows_its_length(): void
    {
        $this->assertMatchesRegularExpression('#<h1>How your page scores for#', Pdf::html($this->report()));
        $report = $this->report();
        $report['keyword'] = 'online mba programmes for working professionals';
        $this->assertMatchesRegularExpression('#<h1 style="font-size:(21|19)pt">How your page scores for#', Pdf::html($report));
    }

    /**
     * When the standard CTA would take the last page on its own, render() uses the largest smaller layout that fits
     * the space left; when the CTA fits, the standard layout stays. Same copy and one link in every layout.
     */
    public function test_cta_takes_a_smaller_layout_rather_than_a_page_of_its_own(): void
    {
        $cta = ['eyebrow' => 'Halo · Thrivemattic', 'title' => 'Fix this page, then the rest of the site',
            'text' => 'This report checks one page. Halo audits up to 2,000 pages of your site every night, tracks your rankings, and checks what ChatGPT, Perplexity, Gemini, Claude and AI Overviews say about you. In a 30-minute call we walk through these findings and what to fix first.',
            'button' => 'Book a Discovery Call', 'url' => 'https://thrivemattic.com/contact/'];
        $filter = static fn() => ['cta' => $cta];
        add_filter('halo_audit_pdf_brand', $filter);
        $call = static function (string $name, ...$args) {
            $m = new ReflectionMethod(Pdf::class, $name);
            $m->setAccessible(true);
            return $m->invoke(null, ...$args);
        };
        $heights = [];
        foreach (['standard', 'compact', 'side'] as $layout) {
            $html = $call('cta', Pdf::brand($this->report())['cta'], $layout);
            $this->assertSame(1, substr_count($html, '<a href="https://thrivemattic.com/contact/"'), $layout);
            foreach (['HALO · THRIVEMATTIC', 'Fix this page, then the rest of the site', 'Book a Discovery Call', 'In a 30-minute call we walk through these findings and what to fix first.'] as $copy) {
                $this->assertStringContainsString($copy, $html, $layout);
            }
            $heights[$layout] = $call('ctaHeight', Pdf::brand($this->report())['cta'], $layout);
        }
        $this->assertGreaterThan($heights['compact'], $heights['standard']);
        $this->assertGreaterThan($heights['side'], $heights['compact']);

        // Find a report whose standard CTA would start a page of its own: add passed checks until it does.
        $report = $this->report();
        $layouts = [];
        for ($n = 0; $n < 40; $n++) {
            $report['checks'][] = self::row('p' . $n, 'pass', 1, 'technical', 'Passed evidence ' . $n);
            $report['summary'] = Scorer::score($report['checks']);
            Pdf::render($report);
            $layouts[] = Pdf::ctaLayoutUsed();
        }
        remove_filter('halo_audit_pdf_brand', $filter);
        $this->assertContains('standard', $layouts);
        $this->assertTrue(in_array('compact', $layouts, true) || in_array('side', $layouts, true), 'A smaller layout is used when the standard one would be alone: ' . implode(',', $layouts));
        add_filter('halo_audit_pdf_brand', '__return_false');
        Pdf::render($this->report());
        $this->assertNull(Pdf::ctaLayoutUsed(), 'No CTA when the brand is switched off.');
        remove_filter('halo_audit_pdf_brand', '__return_false');
    }

    /**
     * D5: when a priority fix does not fit under the area cards, render() draws it on the next page under
     * "Priority fixes (continued)" and the column heads. A list that fits, and a comparison (whose list starts at
     * the top of page 2), have no continuation.
     */
    public function test_priority_fixes_continue_under_a_heading_on_the_next_page(): void
    {
        $report = $this->report();
        // Two sentences each: the heading block fits under the area cards, the later fixes do not (measured).
        $long = str_repeat('Rewrite this part of the page so that the keyword appears where readers look first. ', 2);
        $report['checks'] = [];
        foreach (['a' => 'onpage', 'b' => 'onpage', 'c' => 'technical', 'd' => 'ai', 'e' => 'onpage', 'f' => 'technical'] as $id => $group) {
            $row = self::row($id, 'fail', 3, $group);
            $row['message'] = $long;
            $report['checks'][] = $row;
        }
        $report['summary'] = Scorer::score($report['checks']);
        $this->assertStringStartsWith('%PDF-', Pdf::render($report));
        $this->assertIsInt(Pdf::continuedAt());
        $this->assertGreaterThanOrEqual(1, Pdf::continuedAt(), 'The first fix always stays with the heading.');
        $this->assertSame([], Pdf::refusedFetches());

        $short = $this->report(); // Two checks: one fix, which is in the heading block.
        Pdf::render($short);
        $this->assertNull(Pdf::continuedAt());
        Pdf::render($this->comparison());
        $this->assertNull(Pdf::continuedAt(), 'A comparison starts its list at the top of page 2.');
    }

    /** The brand Thrivemattic's site plugin sends (copy and address approved on 23 Oct 2026). */
    private const SITE_BRAND = ['logo' => true, 'author' => 'Thrivemattic', 'cta' => [
        'eyebrow' => 'Halo · Thrivemattic',
        'title' => 'Fix this page, then the rest of the site',
        'text' => 'This report checks one page. Halo audits up to 2,000 pages of your site every night, tracks your rankings, and checks what ChatGPT, Perplexity, Gemini, Claude and AI Overviews say about you. In a 30-minute call we walk through these findings and what to fix first.',
        'button' => 'Book a Discovery Call',
        'url' => 'https://thrivemattic.com/contact/',
    ]];

    /**
     * Regression (23 Oct): with the site's whole brand switched on, both report types carry the white logo on the
     * cover, the dark logo in the running header, the author and the approved CTA, word for word, as the last block,
     * with one link to the contact page. Logo and CTA come from the same filter, so neither can drop out alone.
     */
    public function test_full_site_brand_puts_logo_author_and_cta_in_both_report_types(): void
    {
        $filter = static fn() => self::SITE_BRAND;
        add_filter('halo_audit_pdf_brand', $filter);
        foreach (['single' => $this->report(), 'comparison' => $this->comparison()] as $type => $report) {
            $html = Pdf::html($report);
            $header = (new ReflectionMethod(Pdf::class, 'runningHeader'))->invoke(null, $report);
            $white = base64_encode((string) file_get_contents(SEO_ANALYZER_PLUGIN_DIR . 'assets/images/logo-white.png'));
            $dark = base64_encode((string) file_get_contents(SEO_ANALYZER_PLUGIN_DIR . 'assets/images/logo-dark.png'));
            $this->assertSame(1, substr_count($html, 'data:image/png;base64,' . $white), $type . ': white logo on the cover');
            $this->assertSame(1, substr_count($header, 'data:image/png;base64,' . $dark), $type . ': dark logo in the running header');
            $this->assertStringNotContainsString('<td class="eyebrow">', $html, $type . ': the logo, not the text mark, leads the cover');
            foreach (['HALO · THRIVEMATTIC', self::SITE_BRAND['cta']['title'], self::SITE_BRAND['cta']['text'], '>Book a Discovery Call</a>'] as $copy) {
                $this->assertSame(1, substr_count($html, $copy), $type . ': ' . $copy);
            }
            $this->assertGreaterThan(max(strrpos($html, 'class="passed"'), strrpos($html, 'class="fc"')), strrpos($html, 'class="cta"'), $type . ': CTA after the findings');

            $pdf = Pdf::render($report);
            $this->assertSame(4, substr_count($pdf, '/Subtype /Image'), $type . ': two logos, each with its soft mask');
            $this->assertSame(1, substr_count($pdf, '/URI (https://thrivemattic.com/contact/)'), $type . ': one link to the contact page');
            $this->assertSame('Thrivemattic', self::info($pdf, 'Author'));
            $this->assertNotNull(Pdf::ctaLayoutUsed());
            $this->assertFalse(Pdf::ctaAlone(), $type);
            $this->assertSame([], Pdf::refusedFetches());
        }
        remove_filter('halo_audit_pdf_brand', $filter);
    }

    /**
     * Regression (23 Oct): the CTA never starts a page with nothing above it. As passed checks are added to the last
     * area, the end of the findings moves down the page; wherever no CTA layout fits the space left, render() carries
     * the area's last items to the next page with the standard CTA. Both report types.
     */
    public function test_cta_never_starts_a_page_alone(): void
    {
        $filter = static fn() => ['cta' => self::SITE_BRAND['cta']];
        add_filter('halo_audit_pdf_brand', $filter);
        $seen = [];
        foreach (['single' => $this->report(), 'comparison' => $this->comparison()] as $type => $report) {
            for ($n = 0; $n < 40; $n++) {
                $row = self::row('p' . $n, 'pass', 1, 'ai', 'Passed evidence ' . $n);
                $report['checks'][] = $row;
                $report['summary'] = Scorer::score($report['checks']);
                if (isset($report['competitor'])) {
                    $report['competitor']['checks'][] = $row;
                    $report['competitor']['summary'] = Scorer::score($report['competitor']['checks']);
                }
                $this->assertStringStartsWith('%PDF-', Pdf::render($report));
                $this->assertFalse(Pdf::ctaAlone(), $type . ' with ' . $n . ' added rows: ' . Pdf::ctaLayoutUsed());
                $seen[$type][] = Pdf::ctaCarried() > 0 ? 'carried' : Pdf::ctaLayoutUsed();
            }
            $this->assertContains('standard', $seen[$type], $type);
            $this->assertContains('carried', $seen[$type], $type . ': the carry pass ran: ' . implode(',', $seen[$type]));
        }
        remove_filter('halo_audit_pdf_brand', $filter);
    }

    /**
     * The carried part of the last area: a new page, the area title marked "(continued)", the column heads again,
     * and every check still printed once in the same order. A passed list never leaves a single row behind; an area
     * with no passed list carries its last finding card.
     */
    public function test_carried_items_continue_under_the_area_title_and_print_once(): void
    {
        $parts = new ReflectionMethod(Pdf::class, 'parts');
        $parts->setAccessible(true);
        $details = static fn(array $report, int $carry): string => $parts->invoke(null, $report, null, 'standard', $carry)[2];
        $titles = static function (string $html): array {
            preg_match_all('#Title (a\d|p\d+)#', $html, $m);
            return $m[0];
        };

        $report = $this->report();
        foreach (['a1', 'a2'] as $id) {
            $report['checks'][] = self::row($id, 'warn', 2, 'ai');
        }
        for ($n = 0; $n < 7; $n++) {
            $report['checks'][] = self::row('p' . $n, 'pass', 1, 'ai');
        }
        $report['summary'] = Scorer::score($report['checks']);
        $plain = $details($report, 0);
        $carried = $details($report, 3);
        $this->assertSame($titles($plain), $titles($carried), 'Same checks, same order, each once.');
        $this->assertSame(substr_count($plain, '<pagebreak />') + 1, substr_count($carried, '<pagebreak />'));
        $tail = substr($carried, strrpos($carried, '<pagebreak />'));
        $this->assertStringContainsString(self::heading('AI readiness (continued)', 'h2', 'Halo Audit score ' . $report['summary']['groups']['ai']['score'] . ' / 100', false), $tail);
        $this->assertSame(['Title p4', 'Title p5', 'Title p6'], $titles($tail));
        $this->assertStringContainsString('<thead><tr><th class="p-check">Passed check</th>', $tail, 'Column heads again.');

        // Four passed rows: carrying three would leave one behind, so the whole list moves, with its own title.
        $short = $this->report();
        $short['checks'][] = self::row('a1', 'warn', 2, 'ai');
        for ($n = 0; $n < 4; $n++) {
            $short['checks'][] = self::row('p' . $n, 'pass', 1, 'ai');
        }
        $short['summary'] = Scorer::score($short['checks']);
        $tail = substr($details($short, 3), strrpos($details($short, 3), '<pagebreak />'));
        $this->assertSame(['Title p0', 'Title p1', 'Title p2', 'Title p3'], $titles($tail));
        $this->assertStringContainsString(self::heading('Passed: 4 checks', 'h3', '', false), $tail);

        // No passed list in the last area: its last card moves under the continued title.
        $cards = $this->report();
        foreach (['a1', 'a2', 'a3'] as $id) {
            $cards['checks'][] = self::row($id, 'fail', 2, 'ai');
        }
        $cards['summary'] = Scorer::score($cards['checks']);
        $tail = substr($details($cards, 1), strrpos($details($cards, 1), '<pagebreak />'));
        $this->assertSame(['Title a3'], $titles($tail));
        $this->assertStringContainsString(self::withCard('AI readiness (continued)', 'Halo Audit score ' . $cards['summary']['groups']['ai']['score'] . ' / 100'), $tail);
    }

    /** Oswald's descender (hhea, 289 of 1000 units): how far g, p, y and q reach below the baseline, in ems. */
    private const OSWALD_DESCENDER = 0.289;

    /** The content stream of page 1: mPDF compresses each page with FlateDecode and writes the pages first. */
    private static function pageOneContent(string $pdf): string
    {
        preg_match_all('#stream\r?\n(.*?)\r?\nendstream#s', $pdf, $m);
        foreach ($m[1] as $raw) {
            $data = @gzuncompress($raw);
            if (is_string($data) && strpos($data, ' Td') !== false && strpos($data, ' re f') !== false) {
                return $data;
            }
        }
        return '';
    }

    /**
     * The cover title as drawn on page 1: its size, the baseline of its last line (PDF points, from the bottom), the
     * charcoal fills painted after it, and the first divider stroke under it. The title is the first text of 19 pt or
     * more; the fill colour is followed through q/Q, as the PDF viewer does.
     *
     * @return array{size:float,baseline:float,later:array<array{float,float}>,divider:?float}
     */
    private static function coverTitle(string $pdf): array
    {
        $ink = vsprintf('%.3f %.3f %.3f', array_map(static fn($h) => hexdec($h) / 255, str_split(ltrim(\HaloAudit\Wp\BrandPdf::CHARCOAL, '#'), 2)));
        preg_match_all('#(?<q>(?<![\w.])q(?![\w]))|(?<Q>(?<![\w.])Q(?![\w]))|(?<rg>[\d.]+ [\d.]+ [\d.]+) rg|(?<re>-?[\d.]+ -?[\d.]+ -?[\d.]+ -?[\d.]+) re f'
            . '|/F\d+ (?<tf>[\d.]+) Tf|(?<td>-?[\d.]+ -?[\d.]+) Td|(?<line>-?[\d.]+ -?[\d.]+ m -?[\d.]+ -?[\d.]+ l)#', self::pageOneContent($pdf), $ops, PREG_SET_ORDER);
        $colour = '';
        $stack = [];
        $size = 0.0;
        $title = ['size' => 0.0, 'baseline' => INF, 'later' => [], 'divider' => null];
        foreach ($ops as $op) {
            if (($op['q'] ?? '') !== '') {
                $stack[] = $colour;
            } elseif (($op['Q'] ?? '') !== '') {
                $colour = (string) array_pop($stack);
            } elseif (($op['rg'] ?? '') !== '') {
                $colour = $op['rg'];
            } elseif (($op['tf'] ?? '') !== '') {
                $size = (float) $op['tf'];
            } elseif (($op['td'] ?? '') !== '' && $size >= 19 && ($title['size'] === 0.0 || $size === $title['size'])) {
                if ($title['later'] === [] && $title['divider'] === null) {
                    $title['size'] = $size; // Every line of the title, until something else is drawn after it.
                    $title['baseline'] = min($title['baseline'], (float) explode(' ', $op['td'])[1]);
                }
            } elseif (($op['re'] ?? '') !== '' && $title['size'] > 0 && $colour === $ink) {
                [, $y, , $h] = array_map('floatval', explode(' ', $op['re']));
                if ($h != 0.0) {
                    $title['later'][] = [min($y, $y + $h), max($y, $y + $h)];
                }
            } elseif (($op['line'] ?? '') !== '' && $title['size'] > 0 && $title['divider'] === null) {
                $p = array_map('floatval', explode(' ', str_replace([' m', ' l'], '', $op['line'])));
                if (abs($p[1] - $p[3]) < 0.01 && $p[1] < $title['baseline']) {
                    $title['divider'] = $p[1];
                }
            }
        }
        return $title;
    }

    /**
     * Regression (23 Oct, "How your page scores for “ktm”"): the lower part of g, p and y in the cover title was cut
     * off. mPDF repaints the cover band's background for each block after that block's text, and the title's
     * descenders hang below its line box, so the next block painted over them. Now nothing charcoal is painted over
     * the descender zone after the title, and the first divider is clear of it, for one-line 24 pt, 21 pt, wrapped
     * 19 pt and Devanagari titles, single and comparison, with and without the logo.
     */
    public function test_cover_title_descenders_are_never_painted_over(): void
    {
        $keywords = ['ktm', 'gyp jqy', 'postgraduate diploma in hospitality management',
            'affordable postgraduate engineering programmes with guaranteed placement support in India', 'हिंदी पाठ्यक्रम gyp'];
        $logo = static fn() => ['logo' => true];
        foreach ([false, true] as $branded) {
            if ($branded) {
                add_filter('halo_audit_pdf_brand', $logo);
            }
            foreach (['single' => $this->report(), 'comparison' => $this->comparison()] as $type => $report) {
                foreach ($keywords as $keyword) {
                    $report['keyword'] = $keyword;
                    $title = self::coverTitle(Pdf::render($report));
                    $label = $type . ($branded ? ' with logo' : '') . ': ' . $keyword;
                    $this->assertGreaterThanOrEqual(19.0, $title['size'], $label);
                    $bottom = $title['baseline'] - self::OSWALD_DESCENDER * $title['size'];
                    foreach ($title['later'] as [$low, $high]) {
                        $this->assertFalse($low < $title['baseline'] && $high > $bottom + 0.01,
                            sprintf('%s: a fill painted after the title covers %.2f-%.2f; descenders reach %.2f', $label, $low, $high, $bottom));
                    }
                    $this->assertNotNull($title['divider'], $label);
                    $this->assertGreaterThanOrEqual(2.0, $bottom - $title['divider'], $label . ': room between the descenders and the first divider');
                }
            }
            remove_filter('halo_audit_pdf_brand', $logo);
        }
    }

    /** The title is the first row of the cover's meta table, so the band is painted before it (see Pdf::hero()). */
    public function test_cover_title_is_the_first_row_of_the_meta_table(): void
    {
        foreach ([$this->report(), $this->comparison()] as $report) {
            $html = Pdf::html($report);
            $this->assertMatchesRegularExpression('#<table class="meta"><tr><td class="hero-title" colspan="2" style="border-top:0;padding:[^"]+"><h1[ >]#', $html);
            $this->assertSame(1, substr_count($html, '<h1'));
        }
    }

    /**
     * Regression (23 Oct 2026): badges have room around the text. mPDF ignores inline padding and padding on a block in
     * a cell, so the room is a border in each badge's own tint (chips and the grade badge), and in a comparison the
     * result line is its own block, because mPDF drops the left border of an inline element right after a block.
     * Every chip in a rendered comparison is drawn with both side borders.
     */
    public function test_badges_have_side_room_drawn_as_a_border_in_their_tint(): void
    {
        $css = (new ReflectionMethod(Pdf::class, 'css'))->invoke(null);
        $this->assertMatchesRegularExpression('#\.chip\{[^}]*border-style:solid;border-width:[\d.]+pt [\d.]+pt\}#', $css);
        foreach (['pass', 'warn', 'fail'] as $status) {
            $tint = \HaloAudit\Wp\BrandPdf::STATUS[$status][2];
            $this->assertStringContainsString('.chip-' . $status . '{color:' . \HaloAudit\Wp\BrandPdf::STATUS[$status][1] . ';background:' . $tint . ';border-color:' . $tint . '}', $css);
        }
        $this->assertMatchesRegularExpression('#\.grade\{[^}]*border:solid [^;]+;border-width:[\d.]+pt [\d.]+pt;#', $css);
        $html = Pdf::html($this->comparison());
        $this->assertStringNotContainsString('</div><span class="chip', $html, 'No chip directly after a block.');

        // In the PDF (every page): each chip, its fills joined where a sign and a word sit side by side, has a side
        // border stroke in its tint at its left edge and at its right edge (mPDF writes "<tint> RG <w> w x y m x y l S").
        $pdf = Pdf::render($this->comparison());
        preg_match_all('#stream\r?\n(.*?)\r?\nendstream#s', $pdf, $m);
        $streams = array_filter(array_map(static fn($raw) => (string) @gzuncompress($raw), $m[1]), static fn($d) => strpos($d, ' re f') !== false);
        $tints = array_map(static fn($s) => vsprintf('%.3f %.3f %.3f', array_map(static fn($h) => hexdec($h) / 255, str_split(ltrim($s[2], '#'), 2))), \HaloAudit\Wp\BrandPdf::STATUS);
        $chips = 0;
        foreach ($streams as $content) { // One page at a time, so fills and borders of different pages never pair up.
            // Fills: follow the fill colour through q/Q, as a viewer does, and keep small "x y w -h re f" in a chip tint.
            preg_match_all('#(?<q>(?<![\w.])q(?![\w]))|(?<Q>(?<![\w.])Q(?![\w]))|(?<rg>[\d.]+ [\d.]+ [\d.]+) rg|(?<re>-?[\d.]+ -?[\d.]+ [\d.]+ -[\d.]+) re f#', $content, $ops, PREG_SET_ORDER);
            $colour = '';
            $stack = [];
            $fillsByTint = [];
            foreach ($ops as $op) {
                if (($op['q'] ?? '') !== '') {
                    $stack[] = $colour;
                } elseif (($op['Q'] ?? '') !== '') {
                    $colour = (string) array_pop($stack);
                } elseif (($op['rg'] ?? '') !== '') {
                    $colour = $op['rg'];
                } elseif (($op['re'] ?? '') !== '' && in_array($colour, $tints, true)) {
                    [$x, $y, $w, $h] = array_map('floatval', explode(' ', $op['re']));
                    if ($w < 200 && abs($h) < 20) {
                        $fillsByTint[$colour][] = [$x, $x + $w, $y];
                    }
                }
            }
            foreach ($tints as $tint) {
                preg_match_all('#' . preg_quote($tint, '#') . ' RG [\d.]+ w ([\d.]+) ([\d.]+) m ([\d.]+) ([\d.]+) l S#', $content, $strokes, PREG_SET_ORDER);
                $sides = array_map(static fn($s) => [(float) $s[1], min((float) $s[2], (float) $s[4])], array_filter($strokes, static fn($s) => abs((float) $s[1] - (float) $s[3]) < 0.01 && abs((float) $s[2] - (float) $s[4]) > 1));
                $fills = $fillsByTint[$tint] ?? [];
                usort($fills, static fn($a, $b) => [$a[2], $a[0]] <=> [$b[2], $b[0]]);
                $joined = [];
                foreach ($fills as $f) {
                    $last = count($joined) - 1;
                    if ($last >= 0 && abs($joined[$last][2] - $f[2]) < 0.01 && abs($joined[$last][1] - $f[0]) < 0.01) {
                        $joined[$last][1] = $f[1];
                    } else {
                        $joined[] = $f;
                    }
                }
                foreach ($joined as [$x0, $x1, $y]) {
                    $chips++;
                    $at = static fn(float $lo, float $hi) => (bool) array_filter($sides, static fn($s) => $s[0] >= $lo && $s[0] <= $hi && $s[1] < $y && $s[1] > $y - 20);
                    $this->assertTrue($at($x0, $x0 + 3), sprintf('Left side border of the chip at x %.1f, y %.1f in tint %s', $x0, $y, $tint));
                    $this->assertTrue($at($x1 - 3, $x1), sprintf('Right side border of the chip at x %.1f, y %.1f in tint %s', $x1, $y, $tint));
                }
            }
        }
        $this->assertGreaterThan(10, $chips, 'The comparison has chips to check.');
    }

    /** "Ahead" is in the Pass status colour: olive is never text, outside the kit's eyebrows on charcoal. */
    public function test_ahead_figures_are_not_olive_text(): void
    {
        $css = (new ReflectionMethod(Pdf::class, 'css'))->invoke(null);
        $this->assertStringContainsString('.d-ahead{color:' . \HaloAudit\Wp\BrandPdf::STATUS['pass'][1] . '}', $css);
        $this->assertStringContainsString('.diff-ahead{color:' . \HaloAudit\Wp\BrandPdf::STATUS['pass'][1], $css);
        $this->assertStringNotContainsString('color:' . \HaloAudit\Wp\BrandPdf::OLIVE_HOVER, $css);
        $this->assertStringNotContainsString('color:' . \HaloAudit\Wp\BrandPdf::OLIVE . ';', $css);
    }

    /**
     * With the logo switched on, the only images are the two bundled logos (each with its transparency mask), even
     * when the report's values try to add more; nothing is fetched. The logo is checked byte for byte against the file.
     */
    public function test_branded_pdf_embeds_only_the_bundled_logos(): void
    {
        $this->assertTrue(function_exists('imagecreatefromstring'), 'GD is needed for the logo; without it this test would only see the text mark.');
        [$dir, $png, $css] = self::localAssets();
        $report = $this->comparison();
        $report['keyword'] = '<img src="' . $png . '">';
        $report['checks'][0]['evidence'] = '<link rel="stylesheet" href="' . $css . '"> <img src="data:image/png;base64,' . base64_encode((string) file_get_contents($png)) . '">';
        $filter = static fn() => ['logo' => true];
        add_filter('halo_audit_pdf_brand', $filter);
        $html = Pdf::html($report);
        $pdf = Pdf::render($report);
        remove_filter('halo_audit_pdf_brand', $filter);

        preg_match_all('#<img src="data:image/png;base64,([A-Za-z0-9+/=]+)" height="[\d.]+mm" alt="" />#', $html, $m);
        $this->assertCount(1, $m[1], 'The cover holds the white logo (the dark one is in the running header).');
        $this->assertSame((string) file_get_contents(SEO_ANALYZER_PLUGIN_DIR . 'assets/images/logo-white.png'), base64_decode($m[1][0]));
        $rest = $this->withoutOwnGraphics(str_replace($m[0][0], '', $html));
        foreach (['<img', '<link', 'src="', 'href="'] as $markup) {
            $this->assertStringNotContainsString($markup, $rest, $markup);
        }
        $this->assertSame(4, substr_count($pdf, '/Subtype /Image'), 'Two logos, each with its soft mask.');
        $this->assertSame([], Pdf::refusedFetches());

        unlink($png);
        unlink($css);
        rmdir($dir);
    }

    /** Characters Lato and Oswald lack are set in DejaVu; Devanagari keeps its own font; nothing becomes a box. */
    public function test_characters_the_brand_fonts_lack_fall_back_to_dejavu(): void
    {
        $report = $this->report();
        $report['checks'][0]['evidence'] = 'Заголовок Τίτλος Trường عنوان ✓ Café €5 ₹9 – “quoted”';
        $report['keyword'] = 'निजी विश्वविद्यालय';
        $html = Pdf::html($report);
        foreach (['Заголовок', 'Τίτλος', 'ư', 'ờ', 'عنوان', '✓'] as $run) {
            $this->assertMatchesRegularExpression('#<span class="fb">[^<]*' . preg_quote($run, '#') . '#u', $html, $run);
        }
        foreach (['Café', '€5', '₹9', '“quoted”'] as $plain) {
            $this->assertStringContainsString($plain, $html, 'Lato has ' . $plain);
        }
        $this->assertStringContainsString('<span lang="hi">निजी विश्वविद्यालय</span>', $html);
        $this->assertStringNotContainsString('<span class="fb">निजी', $html);
        // The signs the PDF writes itself are wrapped wherever they appear (metric labels and chips); the words stay in Lato.
        $this->assertStringContainsString('<div class="k-label"><span class="fb">✗</span> To fix</div>', $html);
        $this->assertStringContainsString('<div class="k-label">! To improve</div>', $html);
        $this->assertStringContainsString('<span class="chip chip-fail"><span class="fb">✗</span> Fix</span>', $html);
        $this->assertStringContainsString('<span class="chip chip-pass"><span class="fb">✓</span> Analysis complete</span>', $html);

        $pdf = Pdf::render($report);
        $this->assertMatchesRegularExpression('#/BaseFont /[A-Z]{6}\+Lato-Regular#', $pdf);
        $this->assertMatchesRegularExpression('#/BaseFont /[A-Z]{6}\+DejaVuSansCondensed#', $pdf);
        $this->assertStringNotContainsString('ZapfDingbats', $pdf, 'No core font is used.');
    }

    /** The approved CTA copy and contact address live in one place in the PDF code (DEFAULT_BRAND), never twice. */
    public function test_thrivemattic_copy_lives_only_in_the_default_brand(): void
    {
        $source = (string) file_get_contents(SEO_ANALYZER_PLUGIN_DIR . 'includes/engine/Wp/Pdf.php');
        foreach (['https://thrivemattic.com/contact/', 'Fix this page, then the rest of the site', 'Book a Discovery Call', "'author' => 'Thrivemattic'"] as $copy) {
            $this->assertSame(1, substr_count($source, $copy), $copy);
        }
        $this->assertSame(1, substr_count($source, "'Report generated by thrivemattic.com with Halo Audit'"));
    }

    private function pdfRequest(string $key): WP_REST_Request
    {
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action('rest_api_init');
        $request = new WP_REST_Request('GET', '/' . RestController::NS . '/report/' . $key . '/pdf');
        $request->set_header('X-Halo-Nonce', wp_create_nonce(RestController::NONCE_ACTION));
        return $request;
    }

    public function test_pdf_route_is_refused_without_unlock(): void
    {
        $key = Plugin::store()->create($this->report());
        $request = $this->pdfRequest($key);
        unset($_COOKIE[Gate::COOKIE]);

        $this->assertSame(403, rest_get_server()->dispatch($request)->get_status());
    }

    public function test_pdf_route_answers_500_without_output_when_the_pdf_cannot_be_made(): void
    {
        $key = Plugin::store()->create($this->report());
        // The credit filter runs inside the render: a failure there stands for any renderer failure.
        add_filter('halo_audit_pdf_footer', static function () {
            echo 'stray output';
            throw new RuntimeException('renderer failed');
        });
        $request = $this->pdfRequest($key);
        $_COOKIE[Gate::COOKIE] = Plugin::gate()->token('Asha', 'asha@example.com', time() + 60);

        ob_start();
        $response = rest_get_server()->dispatch($request);
        $printed = ob_get_clean();
        unset($_COOKIE[Gate::COOKIE]);

        $this->assertSame(500, $response->get_status());
        $this->assertSame('halo_pdf', $response->as_error()->get_error_code());
        $this->assertSame('', $printed);
    }
}
