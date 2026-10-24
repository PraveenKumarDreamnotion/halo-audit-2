<?php
/**
 * Reads the check rows back out of Pdf::html(), for tests that compare the PDF with the unlocked report.
 * A row is a finding card (a status stripe, the check's name, one result per page, each labelled in a comparison,
 * and when your page has something to do, the "What to do" band) or a line of a passed-checks list (name and
 * results, no stripe or label). A result is the status, then the evidence on the same line. Rows are keyed by check
 * title: the PDF prints Speed first, then each area's checks that need a look, then its passed checks.
 * The Lighthouse value is printed as Pdf::lighthouseValue() shows it.
 */
final class Halo_Pdf_Rows
{
    private const NAME = '<tr>(?:<td class="fcb-[a-z_]+" rowspan="\d"></td>)?<td class="check"><strong>(.*?)</strong>(?:<div class="metric">[^<]*</div>)?(?:<div class="why">.*?</div>)?</td>';
    /** A result cell: in a comparison card the label, then the result line in a block of its own (see Pdf::findingCard()). */
    private const CELL = '<td class="res2?">(?:<div class="fc-lbl">[^<]*</div><div>(.*?)</div>|(.*?))</td>';

    /**
     * The HTML without the fallback-font wrapper Pdf puts around characters the brand fonts lack (status signs,
     * non-Latin runs: <span class="fb">✓</span>) and without the line breaks in long addresses, so a row reads as its
     * text. Both are tested in PdfTest.
     */
    public static function withoutFallback(string $html): string
    {
        // The line breaks Pdf puts into long addresses (see Pdf::text()) are left out too.
        return str_replace('<br />', '', (string) preg_replace('#<span class="fb">(.*?)</span>#su', '$1', $html));
    }

    /** @return array<string,array{0:string,1:string,2:string}> title => [your page cell, competitor cell, what to do] */
    public static function comparison(string $html): array
    {
        $html = self::withoutFallback($html);
        preg_match_all('#' . self::NAME . self::CELL . self::CELL . '</tr>'
            . '(?:<tr><td class="fix" colspan="3"><strong class="fix-label">What to do on your page:</strong> (.*?)</td></tr>)?#s', $html, $m, PREG_SET_ORDER);
        $rows = [];
        foreach ($m as $row) {
            // Each CELL has two alternative groups (labelled block, or plain): take whichever matched.
            $rows[$row[1]] = [($row[2] ?? '') !== '' ? $row[2] : ($row[3] ?? ''), ($row[4] ?? '') !== '' ? $row[4] : ($row[5] ?? ''), $row[6] ?? ''];
        }
        return $rows;
    }

    /** @return string[] the numbers drawn in the score rings, in order (your page, then the competitor); "–" when not measured */
    public static function rings(string $html): array
    {
        preg_match_all('#<img src="data:image/svg\+xml;base64,([A-Za-z0-9+/=]+)" width="27mm" />#', $html, $m);
        $out = [];
        foreach ($m[1] as $b64) {
            if (preg_match('#<text[^>]*>([^<]+)</text>#', (string) base64_decode($b64), $t)) {
                $out[] = html_entity_decode($t[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        return $out;
    }

    /** @return array<string,array{0:string,1:string,2:string}> title => [status label, evidence, what to do] */
    public static function single(string $html): array
    {
        $html = self::withoutFallback($html);
        preg_match_all('#' . self::NAME . '<td class="res"><span class="chip chip-[a-z_]+">(.*?)</span>(?: <span class="evidence">(.*?)</span>)?</td></tr>'
            . '(?:<tr><td class="fix" colspan="2"><strong class="fix-label">What to do:</strong> (.*?)</td></tr>)?#s', $html, $m, PREG_SET_ORDER);
        $rows = [];
        foreach ($m as $row) {
            $rows[$row[1]] = [$row[2], $row[3] ?? '', $row[4] ?? ''];
        }
        return $rows;
    }
}
