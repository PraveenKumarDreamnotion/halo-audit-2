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
        $html = Pdf::html($this->report());
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
        $this->assertStringContainsString('<span class="chip chip-fail">✗ 1 to fix</span>&nbsp; <span class="chip chip-warn">! 0 to improve</span>&nbsp; <span class="chip chip-pass">✓ 0 passed</span></div>', $html);
        $this->assertStringContainsString('<span class="chip chip-not_measured">○ 3 not measured</span>', $html);
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
            'How to read the results', self::title('Speed and performance'), self::title('On-page for your keyword'), self::title('Technical'), self::title('AI readiness')];
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

    public function test_credit_is_the_filtered_default_and_names_this_site_not_the_plugin_author(): void
    {
        update_option('blogname', 'Example School');
        $expected = 'Report generated by Example School with Halo Audit.';
        $this->assertSame($expected, Pdf::defaultFooter());
        $this->assertSame($expected, Pdf::credit($this->report()));
        $this->assertStringNotContainsStringIgnoringCase('thrivemattic', Pdf::html($this->report()));
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
        $this->assertSame(1, preg_match_all('#/URI \(#', $pdf) / $pages, 'No other link.');
        $this->assertSame([], Pdf::refusedFetches());

        // A credit without the words has no link; the rest of the footer is never a link.
        add_filter('halo_audit_pdf_footer', static fn() => 'Report by Example School');
        $this->assertStringNotContainsString('/URI (', Pdf::render($this->report()));
    }

    /** No address in the default credit: a staging or local site would print its internal URL to the customer. */
    public function test_default_footer_never_prints_the_site_address(): void
    {
        update_option('blogname', 'Example School');
        $this->assertStringNotContainsString('://', Pdf::defaultFooter());
        $this->assertStringNotContainsString(home_url('/'), Pdf::defaultFooter());
        update_option('home', 'https://stg-example-staging.kinsta.cloud');
        $this->assertSame('Report generated by Example School with Halo Audit.', Pdf::defaultFooter());
    }

    public function test_default_footer_handles_an_empty_site_title(): void
    {
        update_option('blogname', '');
        $this->assertSame('Report generated by ' . \HaloAudit\Url::host(home_url('/')) . ' with Halo Audit.', Pdf::defaultFooter());
        $this->assertStringNotContainsString('://', Pdf::defaultFooter());
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
        $this->assertSame(1, preg_match('#<table class="top".*?</table>#s', $html, $table));
        preg_match_all('#<tr><td class="t-n">(\d)</td><td class="t-result"><span class="chip chip-([a-z_]+)">[^<]*</span></td><td class="check"><strong>Title ([a-z])</strong>.*?<td class="t-todo">Do \3</td></tr>#s', $table[0], $rows, PREG_SET_ORDER);
        $this->assertSame(['1d fail', '2c fail', '3b fail', '4a warn', '5g warn'], array_map(static fn($r) => $r[1] . $r[3] . ' ' . $r[2], $rows));
        $this->assertStringContainsString(self::heading('Priority fixes', 'h2', '5 of 6 shown, every one is in the findings') . '<table class="top"', $html);

        $report['checks'] = [self::row('h', 'pass', 3, 'onpage'), self::row('e', 'not_measured', 3, 'speed')];
        $html = Pdf::html($report);
        $this->assertStringNotContainsString('class="top"', $html);
        $this->assertStringContainsString('<h2>Priority fixes</h2><p class="note">Nothing to fix: every check that ran passed.</p>', $html);
    }

    public function test_speed_is_one_section_with_googles_measures_and_halo_audits_score(): void
    {
        $html = Pdf::html($this->comparison());
        // The section: its heading (with both pages' Halo Audit Speed score), one card per measure, the score row, the note.
        $this->assertSame(1, preg_match('#' . preg_quote(self::title('Speed and performance'), '#') . '(.*?)<p class="note">#s', $html, $table));
        $this->assertMatchesRegularExpression('#^<span class="band-meta">&nbsp;&nbsp;&middot;&nbsp;&nbsp;Halo Audit Speed score: your page \d+ / 100 &middot; competitor 0 / 100</span></h2><table class="fc"#', $table[1]);
        // Each measure says once whose it is and its unit; each page's cell, labelled, holds the value.
        $metrics = ['Google Lighthouse Performance, 0 to 100' => 'performance', 'Google metric: Largest Contentful Paint (LCP), in seconds' => 'lcp',
            'Google metric: Cumulative Layout Shift (CLS), no unit' => 'cls'];
        foreach ($metrics as $label => $what) {
            $this->assertStringContainsString('<div class="metric">' . $label . '</div>', $table[1]);
            $this->assertMatchesRegularExpression('#<td class="res2"><div class="fc-lbl">Your page</div><span class="chip chip-[a-z]+">[^<]*</span> <span class="evidence">MINE ' . $what . '</span></td>'
                . '<td class="res2"><div class="fc-lbl">Competitor</div><span class="chip chip-fail">[^<]*</span> <span class="evidence">THEIRS ' . $what . '</span>#', $table[1]);
        }
        $this->assertSame(3, substr_count($table[1], '<table class="fc"'), 'One card per Google measure.');
        $this->assertStringNotContainsString('<div class="why">Why psi_score</div>', $table[1], 'No second explanation under a speed measure.');
        $this->assertMatchesRegularExpression('#<strong>Halo Audit Speed score</strong><div class="metric">From the results above. Halo Audit&rsquo;s own score, not Google&rsquo;s</div></td><td class="halo halo-score">\d+ / 100</td><td class="halo halo-score">0 / 100</td>#', $table[1]);
        $this->assertSame(1, substr_count($html, 'not field data from real visitors'), 'One note on where the figures come from.');
        $this->assertStringContainsString('weighted 3 for the Performance score, 2 for LCP and 2 for CLS. It is not Google&rsquo;s Performance score.', $html);

        // Printed once: no second Speed table among the findings.
        $this->assertSame(1, substr_count($html, 'MINE lcp'));
        $this->assertStringNotContainsString(self::title('Speed') . '<', $html);

        // The stored Lighthouse sentence is printed as its number out of 100; anything else as stored.
        $this->assertSame('82 / 100', Pdf::lighthouseValue('82 out of 100 (Google Lighthouse, mobile lab test)'));
        $this->assertSame('0 / 100', Pdf::lighthouseValue('0 out of 100 (Google Lighthouse, mobile lab test)'));
        $this->assertSame('Google PageSpeed did not return a result', Pdf::lighthouseValue('Google PageSpeed did not return a result'));

        // A single page has one result column; a page with no speed rows has no speed section.
        $single = $this->comparison();
        unset($single['competitor']);
        $this->assertSame(1, preg_match('#' . preg_quote(self::title('Speed and performance'), '#') . '(.*?)<p class="note">#s', Pdf::html($single), $one));
        $this->assertStringNotContainsString('Competitor', $one[1]);
        $this->assertMatchesRegularExpression('#<td class="res"><span class="chip chip-warn">! Improve</span> <span class="evidence">MINE performance</span></td>#', $one[1]);
        $this->assertStringNotContainsString('Speed and performance', Pdf::html($this->report()));
    }

    public function test_what_to_do_is_a_row_joined_to_its_check(): void
    {
        $html = Pdf::html($this->comparison());
        // A check with a fix: one card (a table mPDF never splits) holds the stripe, the check, both results and the fix.
        $this->assertMatchesRegularExpression('#<table class="fc" autosize="1"><tr><td class="fcb-warn" rowspan="2"></td><td class="check"><strong>Title llms_txt</strong>[^\n]*?</tr>'
            . '<tr><td class="fix" colspan="3"><strong class="fix-label">What to do on your page:</strong> Do llms_txt</td></tr></table>#', $html);
        // The competitor's fix is never printed: the advice is for your page.
        $this->assertStringNotContainsString('Do https', $html);
        // A check your page passes has no fix row; its stripe is green.
        $this->assertMatchesRegularExpression('#<tr><td class="fcb-pass" rowspan="1"></td><td class="check"><strong>Title https</strong>.*?</tr></table>#', $html);
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
            preg_match_all('#<(h[23]) keep-with-table="1">(.*?)</\1>(.{0,6})#s', $html, $headings, PREG_SET_ORDER);
            $this->assertNotEmpty($headings);
            foreach ($headings as [, $tag, $inner, $after]) {
                // The first letter alone in a span (see Pdf::heading()), the rest plain text, then the scores if any.
                $this->assertMatchesRegularExpression('#^<span class="' . $tag . '">[^<]</span>[^<]*(<span class="band-meta">[^<]*</span>)?$#u', $inner);
                $this->assertSame('<table', $after, 'A kept heading is followed by its table.');
            }
            // A section heading that is not kept opens a keep-first block, straight before the table it stays with.
            $plain = preg_match_all('#<h2>#', $html);
            $this->assertSame($plain - substr_count($html, '<h2>Priority fixes</h2>'),
                preg_match_all('#<div class="keep-first"><h2><span class="h2">[^<]</span>[^<]*(<span class="band-meta">[^<]*</span>)?</h2><table class="(fc|area)"#u', $html));
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
        $this->assertStringContainsString('<strong>Title https</strong></td><td class="res"><span class="chip chip-pass">✓ Pass</span> <span class="evidence">MINE https</span></td><td class="res"><span class="chip chip-pass">✓ Pass</span> <span class="evidence">THEIRS https</span>', $both[1]);
        $this->assertCount(count(\HaloAudit\ReportView::pairs($compare['checks'], $compare['competitor']['checks'])), Halo_Pdf_Rows::comparison($html));
        // Your page passes, the competitor does not: printed in full, with the reason.
        $this->assertStringContainsString('<strong>Title title_length</strong><div class="why">Why title_length</div>', $html);
    }

    private const STATUS_TEXT = ['pass' => '✓ Pass', 'warn' => '! Improve', 'fail' => '✗ Fix', 'not_measured' => '○ Not measured'];

    /** Long addresses wrap by the table's overflow:wrap, so the text carries no invisible break characters. */
    public function test_addresses_are_written_as_they_are(): void
    {
        $report = $this->report();
        $report['url'] = 'https://www.example.com/admissions/postgraduate/2026/international-applicants/master-of-business-administration-full-time/?ref=a&lang=en-GB';
        $report['checks'][1]['evidence'] = 'Canonical points to ' . $report['url'];
        $html = Pdf::html($report);
        $this->assertStringNotContainsString("\u{200B}", $html);
        $this->assertStringNotContainsString('<wbr', $html);
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
        $this->assertStringContainsString('Your page compared with a competitor page.</p>', $html);
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
        $this->assertSame(1, preg_match('#' . preg_quote(self::title('Speed and performance'), '#') . '(.*?)<p class="note">#s', $html, $table));
        $this->assertStringContainsString('<td class="halo halo-score">Not measured</td><td class="halo halo-score">Not measured</td>', $table[1]);
        $this->assertSame(0, preg_match('#\d+ / 100#', $table[1]), 'No number when nothing was measured.');
        $this->assertSame(6, substr_count($table[1], '<span class="chip chip-not_measured">○ Not measured</span>'));
        // Page 1's Speed card says so too, and its ring-free card shows no number.
        $this->assertMatchesRegularExpression('#<div class="c-name">Speed \(Halo Audit\)</div><div class="c-counts">.*?</div></td><td class="ca-score"><div class="c-none">Not measured</div>#s', $html);
    }

    public function test_html_loads_nothing_from_outside(): void
    {
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

    public function test_filename_is_always_halo_audit_pdf(): void
    {
        // The same name for every report: it carries nothing from the audited address.
        $this->assertSame('halo-audit.pdf', Pdf::FILENAME);
        $this->assertSame('halo-audit.pdf', Pdf::filename());
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
