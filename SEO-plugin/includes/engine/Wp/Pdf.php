<?php
namespace HaloAudit\Wp;

use HaloAudit\Analyzer;
use HaloAudit\CheckRegistry;
use HaloAudit\ReportView;
use HaloAudit\Result;
use HaloAudit\Scorer;
use HaloAudit\Url;

/**
 * The PDF report, drawn by mPDF 8, laid out as a dashboard in the brand look of BrandPdf (charcoal, cream, olive
 * accent; Lato and Oswald). Thrivemattic-branded by default (logo, author, closing call to action; DEFAULT_BRAND), which
 * the halo_audit_pdf_brand filter can change or switch off (see brand()). Page 1: a charcoal cover band (report, pages, keyword, date, status), a
 * score panel with score rings, metric cards (fix, improve, passed, not measured), one card per area, and the
 * priority fixes. Page 2 on: speed, then every check by area as a finding card (status, evidence, what to
 * do), the checks that need a look first and the passed checks in a compact list.
 * Only markup and CSS that mPDF renders as intended are used (each was checked on rendered pages):
 * - rounded corners only on top-level blocks (hero, score panel, section bands, notes): mPDF ignores border-radius
 *   on tables and cells and draws blocks inside cells badly, so card grids are tables with square cards;
 * - score rings and bars are SVG drawn here from the scores and passed as data: URIs, which mPDF decodes in memory
 *   (an inline <svg> element would be written to a temp file and read back, which the asset lock refuses);
 * - a section title is a heading kept with the table after it (use_kwt, see heading());
 * - a finding card is one table that is never split, so a check stays with its "What to do";
 * - the running header set after page 1 is written, so the summary page has none.
 */
final class Pdf
{
    /** The fallback download name, when no safe name can be built from the report (see filename()). */
    public const FILENAME = 'halo-audit.pdf';

    /** How many fixes the "Priority fixes" list shows. Every finding is still listed in full later on. */
    private const TOP_FIXES = 5;

    private const STATUS_LABELS = ['pass' => 'Pass', 'warn' => 'Improve', 'fail' => 'Fix', 'not_measured' => 'Not measured'];
    /** A sign beside each word, so a result still reads on a grayscale print. */
    private const STATUS_SIGNS = ['pass' => '✓', 'warn' => '!', 'fail' => '✗', 'not_measured' => '○'];

    /** Where a check goes in its area: Fix, then Improve, then not measured or not checked, then the rest. */
    private const ATTENTION = ['fail' => 0, 'warn' => 1, 'not_measured' => 2];

    /** One line under each speed measure: whose measure it is and its unit. The value cell then holds only the value. */
    private const SPEED_METRICS = [
        'psi_score' => 'Google Lighthouse Performance, 0 to 100',
        'lcp' => 'Google metric: Largest Contentful Paint (LCP), in seconds',
        'cls' => 'Google metric: Cumulative Layout Shift (CLS), no unit',
    ];

    /** The areas in sentences ("ahead in on-page SEO and speed"); section titles keep Scorer::LABELS. */
    private const AREA_WORDS = ['onpage' => 'on-page SEO', 'technical' => 'technical SEO', 'ai' => 'AI readiness', 'speed' => 'speed', 'education' => 'education extras'];


    /** The metric cards: what each count is called, in this order. */
    private const KPIS = ['fail' => 'To fix', 'warn' => 'To improve', 'pass' => 'Passed', 'not_measured' => 'Not measured'];

    /** Where "Halo Audit" in the footer links to (a PDF link annotation; nothing is fetched). */
    public const PRODUCT_URL = 'https://thrivemattic.com/ai-tools/seo-analysis/';

    /**
     * Colours used in the SVG rings and bars (the CSS has the same tokens): the brand palette in BrandPdf. Olive is
     * the accent for rings, bars and rules only, never for text. The competitor's ring keeps a mid grey that reads
     * on the track; its bar is the lighter grey of the brand kit, and thinner.
     */
    private const ACCENT = BrandPdf::OLIVE;
    private const THEM = '#7e837f';
    private const THEM_BAR = '#b9bcb7';
    private const TRACK = BrandPdf::CREAM_DARK;
    private const INK = BrandPdf::CHARCOAL;

    /** Secondary text: 5.43:1 on white and 5.08:1 on cream (the kit's #777 is below 4.5:1 on cream). */
    private const MUTED = '#666b68';

    /** The brand fonts (Lato, Oswald; SIL OFL) in the plugin's assets/fonts, by mPDF family and style. */
    private const BRAND_FONTS = [
        'lato' => ['R' => 'Lato-Regular.ttf', 'B' => 'Lato-Bold.ttf', 'I' => 'Lato-Italic.ttf', 'BI' => 'Lato-Bold.ttf'],
        'oswald' => ['R' => 'Oswald-Medium.ttf', 'B' => 'Oswald-SemiBold.ttf'],
        'oswaldbold' => ['R' => 'Oswald-Bold.ttf'],
    ];

    /**
     * The characters every bundled Lato and Oswald face draws (read from the font files with fontTools, 22 Oct 2026):
     * ASCII, Latin-1, Latin Extended-A, typographic quotes and dashes, bullet, ellipsis, euro, rupee, trade mark, minus.
     * Anything else (the status signs, arrows, Cyrillic, Greek, Vietnamese, Arabic…) is set in DejaVu Sans Condensed
     * by fallback(). mPDF's own substitution was tried first: it left signs and Cyrillic in Lato as boxes and pulled in
     * the core ZapfDingbats font, so it is off.
     */
    private const BRAND_CHARS = '\x{0021}-\x{007E}\x{00A0}-\x{00AC}\x{00AE}-\x{017F}\x{02C6}\x{02DA}\x{02DC}\x{2010}\x{2013}-\x{2014}'
        . '\x{2018}-\x{201A}\x{201C}-\x{201E}\x{2020}-\x{2022}\x{2026}\x{2030}\x{2032}-\x{2033}\x{2039}-\x{203A}\x{2044}\x{20AC}\x{20B9}\x{2122}\x{2212}';

    /** Devanagari and its joiners: PdfFonts sets these (lang="hi"), so fallback() leaves them alone. */
    private const DEVANAGARI_CHARS = '\x{0900}-\x{097F}\x{A8E0}-\x{A8FF}\x{200C}\x{200D}';

    /** The space inside every status chip, drawn as a border in the chip's tint (see css()): top/bottom, left/right. */
    private const CHIP_PAD = '1.2pt 3.5pt';

    /** Padding of the cover title's cell: room above, and room under the last line for the descenders (see hero()). */
    private const TITLE_PAD = '1.5mm 0 2.4mm';

    /** The bundled logos a branded PDF may show. Never a path or URL from a filter. Size cap in bytes. */
    private const LOGOS = ['white' => 'logo-white.png', 'dark' => 'logo-dark.png'];
    private const LOGO_MAX_BYTES = 65536;

    /** Paths mPDF asked to fetch during the last render, all refused. For tests and diagnosis; normally empty. */
    private static array $refused = [];

    /** The priority fix that the last render() continued on a later page (see render()), or null. For tests. */
    private static ?int $continuedAt = null;

    /** How many of the last area's items go to a new page with the CTA when no CTA layout fits the space left. */
    private const CTA_CARRY = 3;

    /** The CTA layout the last render() used, or null when it had no CTA. For tests. */
    private static ?string $ctaLayoutUsed = null;

    /** The items the last render() carried to the CTA's page (see area()), 0 when none. For tests. */
    private static int $carried = 0;

    /** Whether the last render() had to start a page with the CTA and nothing else (see render()). For tests. */
    private static bool $ctaAlone = false;

    /** ctaHeight() results by block markup (the same copy gives the same height while PHP runs). */
    private static array $ctaHeights = [];

    /** brandFonts() result for this request (the files do not change while PHP runs). */
    private static ?array $brandFonts = null;

    /**
     * The Thrivemattic brand every PDF carries by default (owner decision, 23 Oct 2026; it replaces the neutral default):
     * the bundled logo on the cover and in the running header, the author, and the approved closing call to action.
     * A site can still change it, or switch it off by returning false, through the halo_audit_pdf_brand filter.
     */
    public const DEFAULT_BRAND = [
        'logo' => true,
        'author' => 'Thrivemattic',
        'cta' => [
            'eyebrow' => 'Halo · Thrivemattic',
            'title' => 'Fix this page, then the rest of the site',
            'text' => 'This report checks one page. Halo audits up to 2,000 pages of your site every night, tracks your rankings, and checks what ChatGPT, Perplexity, Gemini, Claude and AI Overviews say about you. In a 30-minute call we walk through these findings and what to fix first.',
            'button' => 'Book a Discovery Call',
            'url' => 'https://thrivemattic.com/contact/',
        ],
    ];

    /** The approved Thrivemattic credit in the footer of every page (the halo_audit_pdf_footer filter can change it). */
    public static function defaultFooter(): string
    {
        return 'Report generated by thrivemattic.com with Halo Audit';
    }

    /**
     * The download name: "halo-audit-<host>-<YYYY-MM-DD>.pdf", or "halo-audit-<host>-vs-<host>-<YYYY-MM-DD>.pdf" for a
     * comparison. The date is the report date printed in the PDF (UTC). Each host is lowercased, loses a leading
     * "www.", is converted to ASCII (IDN) when the intl extension can, keeps only a-z, 0-9, dot and hyphen, and is
     * cut to 60 characters. Whatever the report holds, the result is ASCII with no quote, semicolon or line break,
     * so it is safe in a Content-Disposition header; anything else falls back to FILENAME.
     */
    public static function filename(array $report = []): string
    {
        $parts = ['halo-audit'];
        $mine = self::fileHost((string) ($report['url'] ?? ''));
        $theirs = isset($report['competitor']) ? self::fileHost((string) ($report['competitor']['url'] ?? '')) : '';
        if ($mine !== '') {
            $parts[] = $mine;
        }
        if ($theirs !== '') {
            $parts[] = $mine !== '' ? 'vs-' . $theirs : $theirs;
        }
        $created = (int) ($report['created_at'] ?? 0);
        if ($created > 0) {
            $parts[] = gmdate('Y-m-d', $created);
        }
        $name = implode('-', $parts) . '.pdf';
        return strlen($name) <= 160 && preg_match('/^halo-audit(-[a-z0-9.-]+)*\.pdf$/', $name) ? $name : self::FILENAME;
    }

    /** One host for the file name (see filename()); '' when nothing safe is left. */
    private static function fileHost(string $url): string
    {
        $host = Url::host($url);
        if ($host !== '' && preg_match('/[^\x20-\x7e]/', $host)) {
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;
            $host = is_string($ascii) ? strtolower($ascii) : '';
        }
        $host = (string) preg_replace('/^www\./', '', $host);
        // Repeated hyphens stay: an IDN host in ASCII starts "xn--".
        $host = (string) preg_replace(['/[^a-z0-9.-]+/', '/\.{2,}/'], ['-', '.'], $host);
        return trim(substr(trim($host, '.-'), 0, 60), '.-');
    }

    /**
     * The brand of a PDF: DEFAULT_BRAND, after the halo_audit_pdf_brand filter. The filter receives that array and may
     * return its own ['logo' => bool, 'cta' => ['eyebrow', 'title', 'text', 'button', 'url'], 'author' => '…'], or
     * anything that is not an array (false) for a neutral PDF: no logo, no closing call to action, no author.
     * Only these values are read, and only in these shapes: the logo is one of the plugin's own bundled files (never a
     * path, URL or image data from the filter); texts are plain, trimmed and capped; the CTA address must be an
     * absolute http(s) URL or the block has no link. Anything else counts as not set.
     *
     * @return array{logo:bool,cta:?array{eyebrow:string,title:string,text:string,button:string,url:string},author:string}
     */
    public static function brand(array $report): array
    {
        $brand = ['logo' => false, 'cta' => null, 'author' => ''];
        $raw = apply_filters('halo_audit_pdf_brand', self::DEFAULT_BRAND, $report);
        if (!is_array($raw)) {
            return $brand;
        }
        $brand['logo'] = ($raw['logo'] ?? false) === true;
        $brand['author'] = self::plain($raw['author'] ?? '', 80);
        $cta = $raw['cta'] ?? null;
        if (is_array($cta)) {
            $url = is_string($cta['url'] ?? null) ? esc_url_raw(trim($cta['url']), ['http', 'https']) : '';
            $block = [
                'eyebrow' => self::plain($cta['eyebrow'] ?? '', 40),
                'title' => self::plain($cta['title'] ?? '', 80),
                'text' => self::plain($cta['text'] ?? '', 400),
                'button' => self::plain($cta['button'] ?? '', 40),
                'url' => preg_match('#^https?://[a-z0-9.-]+(?::\d+)?(?:/|$)#i', $url) ? $url : '',
            ];
            $brand['cta'] = $block['title'] !== '' ? $block : null;
        }
        return $brand;
    }

    /** A plain one-line text from a filter: tags removed, white space folded, capped; '' for anything not a string. */
    private static function plain($value, int $max): string
    {
        if (!is_string($value)) {
            return '';
        }
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($value))), 0, $max);
    }

    /** The credit line printed in the footer of every page, after the halo_audit_pdf_footer filter. Plain text. */
    public static function credit(array $report): string
    {
        return trim((string) apply_filters('halo_audit_pdf_footer', self::defaultFooter(), $report));
    }

    /** The whole body as one string. Every dynamic value is escaped: no value from the audited page reaches mPDF as HTML. */
    public static function html(array $report): string
    {
        [$summary, $rest, $details, $cta] = self::parts($report);
        return $summary . implode('', $rest) . '<pagebreak />' . $details . $cta;
    }

    /**
     * Draws the PDF. The priority fixes after the first are written one at a time; when one of them lands on
     * the next page, the PDF is drawn once more with "… (continued)" and the column heads above that fix. Both
     * passes write the same pieces in the same order, so everything before that fix is laid out the same. A
     * comparison, whose priority fixes start at the top of page 2, never needs that pass.
     * The closing CTA is written last, on its own: when the standard block (about 47 mm) would take a new page by
     * itself, the PDF is drawn once more with the largest smaller layout that fits the space that was left
     * ('compact', about 39 mm, else 'side', about 34 mm). When none fits, the PDF is drawn once more with the last
     * area's last few items (CTA_CARRY) moved to the next page under "<area> (continued)", so the standard block
     * closes a page that has findings on it instead of standing alone. At most four passes.
     */
    public static function render(array $report): string
    {
        $continueAt = null;
        $ctaLayout = 'standard';
        $carry = 0;
        for ($pass = 0; ; $pass++) {
            $mpdf = self::engine($report);
            $author = self::brand($report)['author'];
            if ($author !== '') {
                $mpdf->SetAuthor($author);
            }
            $mpdf->SetHTMLFooter(self::runningFooter($report));
            $mpdf->WriteHTML(self::css(), \Mpdf\HTMLParserMode::HEADER_CSS);

            [$summary, $rest, $details, $cta] = self::parts($report, $continueAt, $ctaLayout, $carry);
            $mpdf->AddPage();
            $mpdf->SetHTMLHeader(self::runningHeader($report)); // Page 1 has started without it: every later page has it.
            $mpdf->WriteHTML($summary, \Mpdf\HTMLParserMode::HTML_BODY);
            $moved = null;
            foreach ($rest as $i => $fix) {
                $page = $mpdf->page;
                $mpdf->WriteHTML($fix, \Mpdf\HTMLParserMode::HTML_BODY);
                if ($moved === null && $mpdf->page > $page) {
                    $moved = $i + 1; // The first fix is in the heading block.
                }
            }
            if ($pass === 0 && $moved !== null) {
                $continueAt = $moved;
                continue;
            }
            // The details start on a page of their own, unless the summary already ran onto page 2: then they
            // follow it there, so no page is left almost empty.
            $mpdf->WriteHTML(($mpdf->page === 1 ? '<pagebreak />' : '') . $details, \Mpdf\HTMLParserMode::HTML_BODY);
            $page = $mpdf->page;
            if ($cta !== '') {
                $free = $mpdf->h - $mpdf->bMargin - $mpdf->y;
                $mpdf->WriteHTML($cta, \Mpdf\HTMLParserMode::HTML_BODY);
                if ($carry === 0 && $mpdf->page > $page) {
                    // The largest smaller layout that fits the space left: compact (stacked, as the reference), else side.
                    foreach ($ctaLayout === 'standard' ? ['compact', 'side'] : [] as $smaller) {
                        if (self::ctaHeight(self::brand($report)['cta'], $smaller) <= $free - 1) {
                            $ctaLayout = $smaller;
                            continue 2;
                        }
                    }
                    // None fits: the standard block goes to the next page with the end of the findings.
                    $ctaLayout = 'standard';
                    $carry = self::CTA_CARRY;
                    continue;
                }
            }
            self::$continuedAt = $continueAt;
            self::$ctaLayoutUsed = $cta !== '' ? $ctaLayout : null;
            self::$carried = $carry;
            self::$ctaAlone = $cta !== '' && $mpdf->page > $page;
            return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
        }
    }

    /** The height in mm the closing CTA takes in a layout, measured once on a scratch page (see width()). */
    private static function ctaHeight(?array $cta, string $layout): float
    {
        $html = self::cta($cta, $layout);
        if (!isset(self::$ctaHeights[$html])) {
            self::width(' ', 9); // Sets up the scratch document.
            self::$meter->AddPage();
            self::$meter->WriteHTML(self::css(), \Mpdf\HTMLParserMode::HEADER_CSS);
            $top = self::$meter->y;
            $page = self::$meter->page;
            self::$meter->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);
            // A block that did not fit a fresh page is reported as too tall for any space left.
            self::$ctaHeights[$html] = self::$meter->page === $page ? self::$meter->y - $top : INF;
        }
        return self::$ctaHeights[$html];
    }

    /** @return ?string the CTA layout the last render() used ('standard', 'compact' or 'side'), or null without a CTA */
    public static function ctaLayoutUsed(): ?string
    {
        return self::$ctaLayoutUsed;
    }

    /** @return int how many of the last area's items the last render() moved to the CTA's page (see area()) */
    public static function ctaCarried(): int
    {
        return self::$carried;
    }

    /** @return bool whether the CTA of the last render() began a page with nothing above it */
    public static function ctaAlone(): bool
    {
        return self::$ctaAlone;
    }

    /** The PDF's Title metadata: says which report it is. The Author comes from brand() ("Thrivemattic" by default). */
    public static function title(array $report): string
    {
        return isset($report['competitor']) ? 'Halo Audit: SEO comparison report' : 'Halo Audit: SEO audit report';
    }

    /** @return ?int the index (0-based) of the priority fix the last render() headed "… (continued)", or null */
    public static function continuedAt(): ?int
    {
        return self::$continuedAt;
    }

    /** @return string[] the paths refused during the last render (see engine()) */
    public static function refusedFetches(): array
    {
        return self::$refused;
    }

    /**
     * mPDF set up for the report. It never fetches anything: no file or URL is read for an image or stylesheet,
     * whatever the markup says. The report's own markup has none (every value is escaped), so this is a second lock:
     * - no stream wrapper is allowed for assets, so a path with a scheme (http, https, file, phar…) is refused
     *   before any fetch (the default allows http, https and file);
     * - a path without a scheme reaches mPDF's local loader and a URL its HTTP client: both are replaced by ones
     *   that read nothing. (mPDF 8.3.1 type-hints its own AssetFetcher class, so the fetcher itself can't be swapped.)
     * mPDF treats a refused asset as missing and leaves it out. A data:image URI is decoded inline by mPDF without any
     * fetch; the only ones in the report are the score rings and bars drawn here. Fonts are not affected.
     */
    private static function engine(array $report): \Mpdf\Mpdf
    {
        $temp = trailingslashit(get_temp_dir()) . 'halo-audit-mpdf';
        wp_mkdir_p($temp);

        self::$refused = [];
        $noFiles = new class implements \Mpdf\File\LocalContentLoaderInterface {
            public function load($path)
            {
                Pdf::noteRefused((string) $path);
                return '';
            }
        };
        $noHttp = new class implements \Mpdf\Http\ClientInterface {
            public function sendRequest(\Psr\Http\Message\RequestInterface $request)
            {
                Pdf::noteRefused((string) $request->getUri());
                throw new \InvalidArgumentException('Halo Audit PDFs fetch nothing.'); // mPDF catches this and leaves the asset out.
            }
        };

        // Lato for text and Oswald for headings and numbers, from the plugin's assets/fonts. A copy without those
        // files keeps DejaVu Sans Condensed for everything, as before. Characters Lato and Oswald do not have are set
        // in DejaVu Sans Condensed by fallback() and sign(), so every character DejaVu printed still prints; mPDF's
        // substitution stays off (see BRAND_CHARS). Devanagari keeps its own route (PdfFonts).
        $brandFonts = self::brandFonts();
        $config = (new \Mpdf\Config\ConfigVariables())->getDefaults();
        $mpdf = new \Mpdf\Mpdf([
            'tempDir' => $temp,
            'format' => 'A4',
            'fontDir' => array_merge((array) $config['fontDir'], $brandFonts !== [] ? [self::brandFontDir()] : []),
            'default_font' => $brandFonts !== [] ? 'lato' : 'dejavusanscondensed',
            'useSubstitutions' => false,
            'margin_left' => 18,
            'margin_right' => 18,
            'margin_top' => 22, // Room for the running header from page 2: it never sits on the content.
            'margin_bottom' => 18,
            'margin_header' => 9,
            'margin_footer' => 8,
            // A taller header or footer (a long host, a long credit from the filter) pushes the body down or up
            // instead of overlapping it. The margins above are the minimum, so a normal report is unchanged.
            'setAutoTopMargin' => 'stretch',
            'setAutoBottomMargin' => 'stretch',
            // Devanagari runs (a Hindi keyword or address) carry lang="hi" (see e()), and PdfFonts sets them in
            // the bundled FreeSans; everything else stays in DejaVu Sans. mPDF's own script detection stays off.
            'autoLangToFont' => true,
            'languageToFont' => new PdfFonts(self::fontDir()),
            'fontdata' => (new \Mpdf\Config\FontVariables())->getDefaults()['fontdata'] + $brandFonts + PdfFonts::fontdata(),
            'whitelistStreamWrappers' => [],
        ], new \Mpdf\Container\SimpleContainer(['localContentLoader' => $noFiles, 'httpClient' => $noHttp]));
        $mpdf->SetTitle(self::title($report));
        $mpdf->SetCreator('Halo Audit');
        $mpdf->use_kwt = true; // A heading marked keep-with-table moves to the next page with its table (see heading()).
        return $mpdf;
    }

    /** @internal Called by the refusing loader and HTTP client in engine(). */
    public static function noteRefused(string $path): void
    {
        self::$refused[] = $path;
    }

    private static function brandFontDir(): string
    {
        return SEO_ANALYZER_PLUGIN_DIR . 'assets/fonts';
    }

    /** The brand font families whose files are all present (none when any is missing, so the PDF never half-switches). */
    private static function brandFonts(): array
    {
        if (self::$brandFonts === null) {
            self::$brandFonts = self::BRAND_FONTS;
            foreach (self::BRAND_FONTS as $styles) {
                foreach ($styles as $file) {
                    if (!is_readable(self::brandFontDir() . '/' . $file)) {
                        self::$brandFonts = [];
                    }
                }
            }
        }
        return self::$brandFonts;
    }

    /** The mPDF family for a role: text (Lato), display (Oswald) or number (Oswald Bold); DejaVu without the files. */
    private static function font(string $role): string
    {
        if (self::brandFonts() === []) {
            return 'dejavusanscondensed';
        }
        return ['text' => 'lato', 'display' => 'oswald', 'number' => 'oswaldbold'][$role] ?? 'lato';
    }

    /** mPDF's own font folder (vendor/mpdf/mpdf/ttfonts), where the build keeps DejaVu and FreeSans. */
    private static function fontDir(): string
    {
        $dirs = (array) ((new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'] ?? []);
        return (string) reset($dirs);
    }

    /**
     * @param ?int $continueAt see topFixes()
     * @param string $ctaLayout see cta()
     * @param int $carry how many of the last area's items start a new page with the CTA (see area())
     * @return array{0:string,1:string[],2:string,3:string} the summary page up to the first priority fix, each
     *                                                      later priority fix, the findings, and the closing CTA
     */
    private static function parts(array $report, ?int $continueAt = null, string $ctaLayout = 'standard', int $carry = 0): array
    {
        $competitor = $report['competitor'] ?? null;
        [$fixes, $rest] = self::topFixes($report, $competitor !== null, $continueAt);
        $summary = self::hero($report, $competitor)
            . self::scorePanel($report, $competitor)
            . self::metricCards($report, $competitor)
            . self::areaCards($report, $competitor)
            . $fixes;

        $details = self::legend()
            . self::pageSpeed($report, $competitor)
            . ($competitor ? self::comparisonFindings($report, $carry) : self::findings($report, $carry));
        return [$summary, $rest, $details, self::cta(self::brand($report)['cta'], $ctaLayout)];
    }

    /**
     * A bundled logo as an inline data: URI (mPDF decodes it in memory; the asset lock is untouched), or '' when the
     * site has not switched the logo on, the file is missing, too large or not a PNG, or GD is missing (mPDF needs
     * GD for a PNG with transparency). '' means the text mark is used instead.
     */
    private static function logo(array $report, string $variant, string $height): string
    {
        if (!self::brand($report)['logo'] || !isset(self::LOGOS[$variant]) || !function_exists('imagecreatefromstring')) {
            return '';
        }
        $path = SEO_ANALYZER_PLUGIN_DIR . 'assets/images/' . self::LOGOS[$variant];
        $size = is_readable($path) ? (int) filesize($path) : 0;
        $bytes = $size > 0 && $size <= self::LOGO_MAX_BYTES ? (string) file_get_contents($path) : '';
        $info = $bytes !== '' ? @getimagesizefromstring($bytes) : false;
        if (!is_array($info) || $info[2] !== IMAGETYPE_PNG) {
            return '';
        }
        return '<img src="data:image/png;base64,' . base64_encode($bytes) . '" height="' . $height . '" alt="" />';
    }

    /**
     * The closing call to action, when brand() has one (by default the approved Thrivemattic CTA): a charcoal block with an eyebrow,
     * a heading, a line of text and one button. Inline styles throughout: mPDF ignores descendant selectors, so a
     * class rule would lose to the base heading colour. The button is a link annotation to the given address;
     * nothing is fetched. The button is the darker olive (white on it 5.47:1).
     *
     * $layout: 'standard' is the reference layout (button under the text). 'compact' is the same block, stacked, with
     * smaller type and spacing; 'side' puts the button beside the text. render() uses 'standard' and switches to a
     * compact layout only when the standard block would otherwise take the last page on its own. Same copy and link.
     */
    private static function cta(?array $cta, string $layout = 'standard'): string
    {
        if ($cta === null) {
            return '';
        }
        $s = [
            // gap2 is the space between the heading and the text.
            'standard' => ['margin' => '6mm', 'pad' => '5mm 7mm', 'title' => 17, 'text' => 9, 'lh' => 1.45, 'gap1' => 4, 'gap2' => 7, 'btnTop' => '4mm', 'btnPad' => '2.4mm 5mm'],
            'compact' => ['margin' => '4mm', 'pad' => '3.6mm 6mm', 'title' => 14, 'text' => 8.5, 'lh' => 1.35, 'gap1' => 3, 'gap2' => 5, 'btnTop' => '2.6mm', 'btnPad' => '1.8mm 4mm'],
            'side' => ['margin' => '4mm', 'pad' => '4mm 6mm', 'title' => 15, 'text' => 8.5, 'lh' => 1.35, 'gap1' => 3, 'gap2' => 5, 'btnTop' => '0', 'btnPad' => '2.2mm 4.5mm'],
        ][$layout] ?? null;
        if ($s === null) {
            return self::cta($cta);
        }
        $bg = 'background-color:' . BrandPdf::CHARCOAL . ';';
        // Spacer lines between eyebrow, title and text, as in the brand kit: mPDF ignores margins inside a table cell.
        $copy = '';
        if ($cta['eyebrow'] !== '') {
            $copy .= '<div style="font-size:7pt;font-weight:bold;letter-spacing:2pt;color:' . BrandPdf::OLIVE_BRIGHT . '">' . self::e(mb_strtoupper($cta['eyebrow'])) . '</div>' . self::spacer($s['gap1']);
        }
        $copy .= '<div style="font-family:' . self::font('display') . ';font-weight:bold;font-size:' . $s['title'] . 'pt;line-height:1.2;color:#ffffff">' . self::e($cta['title']) . '</div>';
        if ($cta['text'] !== '') {
            $copy .= self::spacer($s['gap2']) . '<div style="font-size:' . $s['text'] . 'pt;line-height:' . $s['lh'] . ';color:#c9cdc9">' . self::e($cta['text']) . '</div>';
        }
        $button = '';
        if ($cta['button'] !== '') {
            $label = self::e($cta['button']);
            $link = $cta['url'] !== '' ? '<a href="' . esc_url($cta['url']) . '" style="color:#ffffff;text-decoration:none">' . $label . '</a>' : $label;
            $address = $cta['url'] !== '' ? self::e((string) preg_replace('#^https?://#i', '', rtrim($cta['url'], '/'))) : '';
            $cell = '<td style="background-color:' . BrandPdf::OLIVE_HOVER . ';color:#ffffff;font-weight:bold;padding:' . $s['btnPad'] . '">' . $link . '</td>';
            $button = $layout === 'side'
                // Beside the text: the button, and the address under it in small type.
                ? '<table style="width:auto"><tr>' . $cell . '</tr></table>' . ($address !== '' ? self::spacer(3) . '<div style="font-size:8pt;color:#ffffff">' . $address . '</div>' : '')
                : '<table style="margin-top:' . $s['btnTop'] . ';width:auto"><tr>' . $cell
                    . ($address !== '' ? '<td style="' . $bg . 'color:#ffffff;padding-left:4mm;vertical-align:middle">' . $address . '</td>' : '') . '</tr></table>';
        }
        $out = '<table class="cta" autosize="1" style="margin-top:' . $s['margin'] . ';page-break-inside:avoid"><tr><td style="' . $bg . 'padding:' . $s['pad'] . '">';
        if ($layout === 'side' && $button !== '') {
            $out .= '<table style="width:100%"><tr><td style="' . $bg . 'padding:0 5mm 0 0;vertical-align:middle">' . $copy . '</td>'
                . '<td style="' . $bg . 'width:44mm;padding:0;vertical-align:middle">' . $button . '</td></tr></table>';
        } else {
            $out .= $copy . $button;
        }
        return $out . '</td></tr></table>';
    }

    /** Vertical space inside a table cell: a line of the given height in points (mPDF ignores margins there). */
    private static function spacer(int $points): string
    {
        return '<div style="font-size:' . $points . 'pt;line-height:1">&nbsp;</div>';
    }

    /* Page 1: the dashboard */

    /**
     * The cover band: charcoal, white type, the logo when brand() has it on (the default; else the "HALO AUDIT" text
     * mark), what the report is, the pages, keyword, date and whether every test had finished. Colours are set
     * inline or by plain class rules: mPDF does not apply descendant selectors such as ".cover h1".
     */
    private static function hero(array $report, ?array $competitor): string
    {
        $keyword = trim((string) $report['keyword']);
        $logo = self::logo($report, 'white', '6.5mm');
        $eyebrow = $competitor ? 'SEO comparison report' : 'SEO audit report';
        // With a logo, the logo leads the top row and the eyebrow goes under it; without one, the eyebrow takes its
        // place, so a neutral PDF spends no height on an empty row.
        $out = '<div class="hero"><table class="hero-top"><tr>'
            . ($logo !== '' ? '<td class="hero-logo">' . $logo . '</td>' : '<td class="eyebrow">' . $eyebrow . '</td>')
            . '<td class="brand">HALO AUDIT</td></tr></table>';
        if ($logo !== '') {
            $out .= '<div class="eyebrow eyebrow-below">' . $eyebrow . '</div>';
        }
        // The title names the keyword, as in the reference ("How your page compares for “acca course”"); the report
        // type is in the eyebrow above it. Without a keyword the title is the report type.
        $title = $keyword === '' ? ($competitor ? 'SEO comparison report' : 'SEO audit report')
            : ($competitor ? 'How your page compares for &ldquo;' : 'How your page scores for &ldquo;') . self::e($keyword) . '&rdquo;';
        $size = $keyword === '' ? 24.0 : self::titleSize(($competitor ? 'How your page compares for “' : 'How your page scores for “') . $keyword . '”');
        // The title is the first row of the meta table, not a block of its own. mPDF repaints the band's background
        // for each block after it writes that block's text, so the block under a title (its padding, then the table)
        // was painted over the part of the Oswald descenders (0.289 em) that hangs below the line box: g, p and y
        // were cut off. A table is laid out first and its text written last, so nothing covers the title here; the
        // cell's bottom padding keeps the descenders clear of the first divider (margins are ignored in a cell).
        $out .= '<table class="meta"><tr><td class="hero-title" colspan="2" style="border-top:0;padding:' . self::TITLE_PAD . '"><h1' . ($size < 24 ? ' style="font-size:' . $size . 'pt"' : '') . '>'
            . $title . '</h1></td></tr>';
        $out .= self::metaRow($competitor ? 'Your page' : 'Page', self::text($report['url'], self::BOX_COVER));
        if ($competitor) {
            $out .= self::metaRow('Competitor', self::text($competitor['url'], self::BOX_COVER));
        }
        if ($keyword !== '') {
            $out .= self::metaRow('Keyword', self::text($keyword));
        }
        $out .= self::metaRow('Date', self::e(self::date($report)));
        $out .= self::metaRow('Status', self::status($report, $competitor));
        return $out . '</table></div>';
    }

    private static function metaRow(string $label, string $value): string
    {
        return '<tr><td class="meta-label">' . self::e($label) . '</td><td class="meta-value">' . $value . '</td></tr>';
    }

    /**
     * "Analysis complete" only when every page's speed test had finished with a result (or speed is not tested on
     * this site); otherwise which page's test was still running or gave no result, so Speed is not measured.
     */
    private static function status(array $report, ?array $competitor): string
    {
        $sides = $competitor ? [['Your page', $report], ['Competitor page', $competitor]] : [['', $report]];
        $notes = [];
        $running = $failed = false;
        foreach ($sides as [$label, $side]) {
            $who = $label !== '' ? self::e($label) . ': the' : 'The';
            if (empty($side['speed_done'])) {
                $running = true;
                $notes[] = '<div class="status-note">' . $who . ' speed test had not finished when this PDF was made, so Speed is not measured.</div>';
            } elseif (Analyzer::speedFailed($side)) {
                $failed = true;
                $notes[] = '<div class="status-note">' . $who . ' speed test did not return a result, so Speed is not measured.</div>';
            }
        }
        if ($notes === []) {
            return '<span class="chip chip-pass">' . self::sign('✓') . ' Analysis complete</span>';
        }
        $chip = $running ? 'Speed test still running' : ($failed ? 'Page audit complete, speed test failed' : '');
        return '<span class="chip chip-not_measured">' . self::sign('○') . ' ' . $chip . '</span>' . implode('', $notes);
    }

    /** The score panel: a ring per page with its grade, the difference, and what the results mean. */
    private static function scorePanel(array $report, ?array $competitor): string
    {
        $mine = $report['summary'];
        $out = '<div class="panel"><table class="sp"><tr>';
        $out .= self::scoreBlock($competitor ? 'Your page' : 'Overall score', $mine, self::ACCENT, Url::host($report['url']), 'you');
        if ($competitor) {
            $theirs = $competitor['summary'];
            $out .= self::scoreBlock('Competitor', $theirs, self::THEM, Url::host($competitor['url']), 'them');
            $out .= '<td class="sp-diff"><div class="sp-label">Difference</div>' . self::differenceBig($mine['overall'], $theirs['overall']) . '</td>';
        } else {
            $out .= '<td class="sp-meaning">' . self::meaningBox($report, null) . '</td>';
        }
        $out .= '</tr></table>';
        if ($competitor) {
            $out .= '<div class="panel-meaning">' . self::meaningBox($report, $competitor) . '</div>';
        }
        return $out . '</div>';
    }

    private static function scoreBlock(string $label, array $summary, string $colour, string $host, string $who): string
    {
        $out = '<td class="sp-ring">' . self::svg(self::ring($summary['overall'], $colour), '27mm') . '</td><td class="sp-info sp-info-' . $who . '">'
            . '<div class="sp-label">' . self::e($label) . '</div>';
        if ($host !== '') {
            // The column is narrow: a long host breaks after a dot or hyphen, never inside a word (see text()).
            $out .= '<div class="sp-host">' . self::text($host, self::BOX_HOST) . '</div>';
        }
        $out .= '<div class="sp-of">' . ($summary['overall'] === null ? 'Not measured' : ($who === 'you' && $label === 'Overall score' ? 'out of 100' : 'Overall score, out of 100')) . '</div>';
        if (!empty($summary['grade'])) {
            // A spacer line, not a margin: mPDF ignores the margins of a block inside a table cell, so the badge sat
            // flush against "out of 100".
            $out .= self::spacer(3) . '<div class="grade grade-' . self::e(strtolower($summary['grade'])) . '">Grade ' . self::e($summary['grade']) . '</div>';
        }
        return $out . '</td>';
    }

    private static function meaningBox(array $report, ?array $competitor): string
    {
        $out = '<div class="box-title">What the results mean</div><div class="meaning-text">' . self::meaning($report, $competitor) . '</div>';
        if ($competitor) {
            $mineSpeed = $report['summary']['groups']['speed']['score'] ?? null;
            $theirSpeed = $competitor['summary']['groups']['speed']['score'] ?? null;
            if (!empty($report['speed_done']) && !empty($competitor['speed_done']) && ($mineSpeed === null) !== ($theirSpeed === null)) {
                $out .= '<div class="meaning-text">Speed was measured for only one of the two pages, so the overall scores are not directly comparable.</div>';
            }
        }
        return $out . '<div class="scale">Grades: A 90&ndash;100 &middot; B 70&ndash;89 &middot; C 50&ndash;69 &middot; D 30&ndash;49 &middot; F below 30</div>';
    }

    /**
     * A few sentences read straight from the stored scores and counts: the overall result, where your page is
     * ahead or behind (or its strongest and weakest area), and how many checks need work. No new judgement.
     */
    private static function meaning(array $report, ?array $competitor): string
    {
        $word = static fn(string $g): string => self::AREA_WORDS[$g] ?? $g;
        $mine = $report['summary'];
        $who = $competitor ? 'Your page' : 'This page';
        $s = [];
        $s[] = $mine['overall'] === null
            ? $who . ' could not be scored: none of its areas could be measured.'
            : $who . ' scores ' . (int) $mine['overall'] . ' out of 100' . (!empty($mine['grade']) ? ' (grade ' . self::e($mine['grade']) . ')' : '') . '.';

        if ($competitor) {
            $theirs = $competitor['summary'];
            if ($theirs['overall'] !== null) {
                $line = 'The competitor page scores ' . (int) $theirs['overall'] . (!empty($theirs['grade']) ? ' (grade ' . self::e($theirs['grade']) . ')' : '');
                if ($mine['overall'] !== null) {
                    $d = $mine['overall'] - $theirs['overall'];
                    $line .= $d === 0 ? ', the same as your page' : ', so your page is ' . abs($d) . ' point' . (abs($d) === 1 ? '' : 's') . ($d > 0 ? ' ahead.' : ' behind.');
                }
                $s[] = rtrim($line, '.') . '.';
            }
            $ahead = $behind = $level = [];
            foreach ($mine['groups'] as $g => $data) {
                $other = $theirs['groups'][$g]['score'] ?? null;
                if ($data['score'] === null || $other === null) {
                    continue;
                }
                $d = $data['score'] - $other;
                if ($d > 0) {
                    $ahead[] = $word($g);
                } elseif ($d < 0) {
                    $behind[] = $word($g);
                } else {
                    $level[] = $word($g);
                }
            }
            $compared = count($ahead) + count($behind) + count($level);
            // "all four areas", or when some area could not be measured on one page, "both areas that could be compared".
            $all = ($compared === 2 ? 'both areas' : 'all ' . self::number($compared) . ' areas')
                . ($compared < count($mine['groups']) ? ' that could be compared' : '');
            if ($compared > 1 && count($ahead) === $compared) {
                $s[] = 'It is ahead in ' . $all . '.';
            } elseif ($compared > 1 && count($behind) === $compared) {
                $s[] = 'It is behind in ' . $all . '.';
            } elseif ($compared > 1 && count($level) === $compared) {
                $s[] = 'It is level in ' . $all . '.';
            } else {
                // One short sentence per direction: "It is ahead in on-page SEO and speed. It is behind in AI readiness."
                foreach (['ahead in' => $ahead, 'behind in' => $behind, 'level in' => $level] as $where => $areas) {
                    if ($areas !== []) {
                        $s[] = 'It is ' . $where . ' ' . self::e(self::listText($areas)) . '.';
                    }
                }
            }
        } else {
            $measured = array_filter($mine['groups'], static fn(array $d): bool => $d['score'] !== null);
            if (count($measured) > 1) {
                // The first area wins a tie, in the order of the report.
                $best = $worst = null;
                foreach ($measured as $g => $d) {
                    $best = $best === null || $d['score'] > $measured[$best]['score'] ? $g : $best;
                    $worst = $worst === null || $d['score'] < $measured[$worst]['score'] ? $g : $worst;
                }
                if ($best !== $worst) {
                    $s[] = 'Its strongest area is ' . self::e($word($best)) . ' (' . (int) $measured[$best]['score'] . '); '
                        . 'the one that needs most work is ' . self::e($word($worst)) . ' (' . (int) $measured[$worst]['score'] . ').';
                }
            }
        }

        $counts = self::totals($mine['groups']);
        if ($counts['fail'] + $counts['warn'] === 0) {
            $s[] = 'Every check that ran on ' . ($competitor ? 'your page' : 'this page') . ' passed.';
        } else {
            $s[] = ($competitor ? 'On your page, ' : '') . self::checks($counts['fail'], 'needs', 'need') . ' fixing and '
                . self::checks($counts['warn'], 'could', 'could') . ' be improved; the priority fixes below are the place to start.';
            $s[count($s) - 1] = ucfirst($s[count($s) - 1]);
        }
        return implode(' ', $s);
    }

    private static function checks(int $n, string $one, string $many): string
    {
        return $n . ' check' . ($n === 1 ? ' ' . $one : 's ' . $many);
    }

    private static function number(int $n): string
    {
        return [2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five'][$n] ?? (string) $n;
    }

    /** One card per count of your page's scored checks; in a comparison each card also gives the competitor's. */
    private static function metricCards(array $report, ?array $competitor): string
    {
        $mine = self::totals($report['summary']['groups']);
        $theirs = $competitor ? self::totals($competitor['summary']['groups']) : null;
        $cells = [];
        foreach (self::KPIS as $status => $label) {
            $sub = $theirs !== null ? 'Competitor: ' . $theirs[$status] : 'of ' . self::checkCount(array_sum($mine)) . ' scored';
            $cells[] = '<td class="kpi kpi-' . $status . '"><div class="k-label">' . self::sign(self::STATUS_SIGNS[$status]) . ' ' . self::e($label) . '</div>'
                . '<div class="k-num k-' . $status . '">' . (int) $mine[$status] . '</div><div class="k-sub">' . self::e($sub) . '</div></td>';
        }
        return '<table class="cards" autosize="1"><tr>' . implode('<td class="cgap"></td>', $cells) . '</tr></table>';
    }

    /**
     * One card per scored area, two to a row: the name and counts with the score beside them, a bar (and the
     * competitor's, thinner) and, in a tinted foot, the area's most important check that needs work, picked by the
     * same order as the priority fixes. Each card is a few table rows (three, five in a comparison) whose cell
     * borders draw its outline (mPDF cannot round or nest boxes in a cell), so the two cards in a row are always as
     * tall as each other.
     */
    private static function areaCards(array $report, ?array $competitor): string
    {
        $cards = [];
        foreach ($report['summary']['groups'] as $group => $data) {
            $other = $competitor['summary']['groups'][$group] ?? null;
            // The same area names as the section headings and the priority list (Scorer::LABELS), as in the reference.
            // A spacer line under the area name (mPDF ignores margins, padding and line height on a div inside a cell).
            $head = '<td class="ca-head"><div class="c-name">' . self::e(Scorer::LABELS[$group] ?? $group) . '</div>' . self::spacer(3)
                . '<div class="c-counts">' . self::countsLine($data) . '</div></td>'
                . '<td class="ca-score">' . ($data['score'] === null
                    ? '<div class="c-none">Not measured</div>'
                    : '<div class="c-score">' . (int) $data['score'] . '<span class="c-of"> / 100</span></div>') . '</td>';
            // The bars and the competitor line are rows of their own: cell padding is the only spacing mPDF keeps
            // inside a table, so each gap is one padding instead of line-heights that crowd the bars.
            $you = '<div class="c-bar">' . self::svg(self::bar($data['score'], 'you', 400), '78mm') . '</div>';
            $body = $competitor
                ? ['<td class="ca-bar" colspan="2">' . $you . '</td>',
                    '<td class="ca-vs" colspan="2"><div class="c-vs">Competitor ' . self::outOf100($other['score'] ?? null) . '&nbsp;&nbsp;&middot;&nbsp;&nbsp;'
                        . self::differenceSmall($data['score'], $other['score'] ?? null) . '</div></td>',
                    '<td class="ca-bar-end" colspan="2"><div class="c-bar">' . self::svg(self::bar($other['score'] ?? null, 'them', 400), '78mm') . '</div></td>']
                : ['<td class="ca-bar-solo" colspan="2">' . $you . '</td>'];
            $top = self::rankFixes(array_filter($report['checks'], static fn(array $r): bool => $r['group'] === $group));
            $foot = $top !== []
                ? '<div class="c-top"><span class="c-top-label">Top issue&nbsp;&nbsp;</span>' . self::e($top[0]['title']) . '</div>'
                : '<div class="c-top">' . ($data['score'] === null ? 'Not measured on this page.' : 'Every check passed.') . '</div>';
            $cards[] = array_merge([$head], $body, ['<td class="ca-foot" colspan="2">' . $foot . '</td>']);
        }
        // One table per row of two cards, so a page break falls between rows of cards, never inside a card.
        $rows = [];
        $height = count($cards[0] ?? []);
        foreach (array_chunk($cards, 2) as $pair) {
            $pair[1] ??= array_fill(0, $height, '<td class="ca-empty" colspan="2"></td>');
            $row = '<table class="area" autosize="1">';
            for ($r = 0; $r < $height; $r++) {
                $row .= '<tr>' . $pair[0][$r] . '<td class="cgap"></td>' . $pair[1][$r] . '</tr>';
            }
            $rows[] = $row . '</table>';
        }
        // The heading and the first row of cards are one unbreakable block rather than a keep-with-table heading:
        // for a page-break-inside:avoid table, mPDF subtracts a kept heading's height from the space left on the
        // page although the heading is already placed, so a row that fits was moved, with its heading, to the next
        // page (a long hero then left a quarter of page 1 empty and pushed every later page down).
        return '<div class="keep-first">' . self::heading('h2', 'Scores by area', 'Halo Audit scores, out of 100', false) . array_shift($rows) . '</div>'
            . implode('', $rows);
    }

    /**
     * "2 to fix · 2 to improve · 8 passed" as plain text, every count shown, zeros included, as in the reference
     * (owner choice, 23 Oct: the web cards hide zeros, the PDF does not). "N not measured" is added when there is one.
     */
    private static function countsLine(array $group): string
    {
        $parts = [];
        foreach (['fail' => 'to fix', 'warn' => 'to improve', 'pass' => 'passed'] as $status => $word) {
            $parts[] = (int) ($group[$status] ?? 0) . ' ' . $word;
        }
        if ((int) ($group['not_measured'] ?? 0) > 0) {
            $parts[] = (int) $group['not_measured'] . ' not measured';
        }
        return implode(' &middot; ', $parts);
    }

    /** Fix before Improve, then the checks that count most in their area, then the areas that count most overall. */
    private static function rankFixes(array $rows): array
    {
        $rank = [Result::FAIL => 0, Result::WARN => 1];
        $rows = array_values(array_filter($rows, static fn(array $r): bool => isset($rank[$r['status']])));
        $key = static fn(array $r): array => [$rank[$r['status']], -(int) $r['severity'], -(Scorer::WEIGHTS[$r['group']] ?? 0)];
        usort($rows, static fn(array $a, array $b): int => $key($a) <=> $key($b));
        return $rows;
    }

    /**
     * The fixes that matter most for your page: Fix before Improve, then the checks that count most in their
     * area, then the areas that count most in the overall score. A tie keeps the order of the report.
     */
    /**
     * @param ?int $continueAt the index of the first fix that render() found on a later page: it gets the heading
     *                         "… (continued)" and the column heads again
     * @return array{0:string,1:string[]} the heading block with the first fix, then one table per later fix
     */
    private static function topFixes(array $report, bool $comparison, ?int $continueAt = null): array
    {
        $rows = self::rankFixes($report['checks']);
        $title = $comparison ? 'Priority fixes for your page' : 'Priority fixes';
        if ($rows === []) {
            return ['<h2>' . $title . '</h2><p class="note">Nothing to fix: every check that ran passed.</p>', []];
        }
        $meta = count($rows) > self::TOP_FIXES ? self::TOP_FIXES . ' of ' . count($rows) . ' shown, every one is in the findings' : 'Every one is in the findings';
        // The heading, the column heads (as in the reference) and the first fix are one block that is never split,
        // so the heading is never left alone at the foot of a page. It is the smallest safe block: with two fixes in
        // it, a tall cover (a Hindi title) pushed the whole list to page 2 and left a fifth of page 1 empty. Each
        // later fix is a one-row table with the same columns: right under the block when it fits, else on the next
        // page, where render() gives it a "continued" heading. Keeping all five together was tried: in a single
        // audit they do not fit under the area cards (about 66 mm for 54 mm left).
        $cells = [];
        foreach (array_slice($rows, 0, self::TOP_FIXES) as $i => $row) {
            $fix = (string) ($row['message'] ?? '') !== '' ? $row['message'] : (string) ($row['evidence'] ?? '');
            $cells[] = '<tr><td class="t-n">' . ($i + 1) . '</td><td class="t-result">' . self::chip($row['status']) . '</td><td class="check"><strong>'
                . self::e($row['title']) . '</strong><div class="why">' . self::e(Scorer::LABELS[$row['group']] ?? $row['group']) . '</div></td><td class="t-todo">'
                . self::text((string) $fix) . '</td></tr>';
        }
        $head = '<tr><th class="t-n">#</th><th class="t-result">Status</th><th class="check">Check</th><th class="t-todo">What to do</th></tr>';
        $table = static fn(string $rows): string => '<table class="top" autosize="1"><tbody>' . $rows . '</tbody></table>';
        $rest = [];
        foreach (array_slice($cells, 1, null, true) as $n => $cell) {
            $rest[] = $continueAt === $n
                ? '<div class="keep-first">' . self::heading('h2', $title . ' (continued)', '', false) . $table($head . $cell) . '</div>'
                : $table($cell);
        }
        return ['<div class="keep-first">' . self::heading('h2', $title, $meta, false) . $table($head . $cells[0]) . '</div>', $rest];
    }

    private static function legend(): string
    {
        $rows = [
            'pass' => 'meets the guideline',
            'warn' => 'partly meets it',
            'fail' => 'needs action',
            'not_measured' => 'not tested, so not scored',
        ];
        // autosize="1" on this and the Halo Audit Speed row: mPDF otherwise shrinks a short table that is kept
        // together to fit the end of a page (its text fell to 5.8 pt) instead of moving it to the next page.
        $out = '<table class="legend" autosize="1"><tr><td class="legend-title" colspan="4">How to read the results</td></tr><tr>';
        foreach ($rows as $status => $meaning) {
            $out .= '<td class="legend-cell legend-' . $status . '">' . self::chip($status) . ' <span class="legend-text">' . self::e($meaning) . '</span></td>';
        }
        return $out . '</tr></table>';
    }

    /* Page 2 on: speed, then every check by area */

    /**
     * The Speed area: one card per Google measure, labelled as Google's with its unit, each with its Pass, Improve
     * or Fix per page and what to do; then Halo Audit's Speed score, labelled as Halo Audit's own.
     * Built from the stored speed rows, so a report made before this layout existed shows it too.
     */
    private static function pageSpeed(array $report, ?array $competitor): string
    {
        $sides = $competitor ? [$report, $competitor] : [$report];
        $order = [];
        foreach (CheckRegistry::speed() as $check) {
            $order[$check->id()] = $check->severity();
        }
        $bySide = [];
        $first = [];
        foreach ($sides as $i => $side) {
            foreach ($side['checks'] as $row) {
                if ($row['group'] === 'speed') {
                    $bySide[$i][$row['id']] = $row;
                    $first[$row['id']] ??= $row;
                }
            }
        }
        if ($first === []) {
            return '';
        }
        // The registry order first; a stored row the registry no longer knows still shows, at the end.
        $ids = array_merge(array_keys(array_intersect_key($order, $first)), array_keys(array_diff_key($first, $order)));

        $scores = [];
        foreach ($sides as $i => $side) {
            $scores[] = ($competitor ? ($i === 0 ? 'your page ' : 'competitor ') : '') . self::outOf100($side['summary']['groups']['speed']['score'] ?? null);
        }
        $out = '';
        // Named like the area everywhere else (Scorer::LABELS); the meta says whose score it is.
        $title = [Scorer::LABELS['speed'], 'Halo Audit Speed score: ' . implode(' &middot; ', $scores)];
        foreach ($ids as $n => $id) {
            $row = $first[$id];
            // The metric line says whose measure it is and its unit, so the explanation is not repeated in each cell.
            $name = '<strong>' . self::e($row['title']) . '</strong>'
                . (isset(self::SPEED_METRICS[$id]) ? '<div class="metric">' . self::SPEED_METRICS[$id] . '</div>' : self::why($row));
            $cells = [];
            foreach (array_keys($sides) as $i) {
                $cells[] = self::side($bySide[$i][$id] ?? null, $id === 'psi_score', $competitor ? 'two' : 'one');
            }
            $card = self::findingCard($name, $cells, $bySide[0][$id] ?? null);
            $out .= $n === 0 ? self::headingWithCard($title[0], $title[1], $card) : $card;
        }
        if ($ids === []) {
            $out .= self::heading('h2', $title[0], $title[1]);
        }
        $out .= '<table class="halo-row" autosize="1"><tr><td class="halo-name"><strong>Halo Audit Speed score</strong><div class="metric">From the results above. Halo Audit&rsquo;s own score, not Google&rsquo;s</div></td>';
        foreach ($sides as $side) {
            $out .= '<td class="halo halo-score">' . ucfirst(self::outOf100($side['summary']['groups']['speed']['score'] ?? null)) . '</td>';
        }
        $out .= '</tr></table>';

        $parts = [];
        foreach ($order as $id => $w) {
            $parts[] = $w . ' for ' . (['psi_score' => 'the Performance score', 'lcp' => 'LCP', 'cls' => 'CLS'][$id] ?? $id);
        }
        return $out . '<p class="note">Google&rsquo;s figures come from one Lighthouse lab test on a simulated phone, run by PageSpeed Insights: '
            . 'they are not field data from real visitors. For the Halo Audit Speed score each result counts Pass 100, Improve 50 or Fix 0, '
            . 'weighted ' . self::e(self::listText($parts)) . '. It is not Google&rsquo;s Performance score.</p>';
    }

    /**
     * One page: each area's checks that need attention as finding cards (with why and what to do), then its passed
     * checks in a compact list. Every check is printed once. Speed is in its own section.
     */
    private static function findings(array $report, int $carry = 0): string
    {
        $byGroup = [];
        foreach ($report['checks'] as $row) {
            if ($row['group'] !== 'speed') {
                $byGroup[$row['group']][] = $row;
            }
        }
        $groups = $report['summary']['groups'];
        $out = '';
        $ordered = self::ordered($byGroup);
        $last = array_key_last($ordered);
        foreach ($ordered as $group => $rows) {
            $meta = isset($groups[$group]) ? 'Halo Audit score ' . self::outOf100($groups[$group]['score']) : 'Not part of the score';
            [$open, $passed] = self::split($rows, static fn(array $r): bool => $r['status'] === Result::PASS, static fn(array $r): string => $r['status']);
            $cards = $lines = [];
            foreach ($open as $row) {
                $cards[] = self::findingCard('<strong>' . self::e($row['title']) . '</strong>' . self::why($row), [self::side($row, false, 'one')], $row);
            }
            foreach ($passed as $row) {
                $lines[] = self::passedRow($row['title'], [self::side($row, false, 'one', 8.0)]);
            }
            $out .= self::area(Scorer::LABELS[$group] ?? $group, $meta, $cards, 'Passed: ' . self::checkCount(count($passed)),
                '<tr><th class="p-check">Passed check</th><th>Result</th></tr>', $lines, $group === $last ? $carry : 0);
        }
        return $out;
    }

    /**
     * One area of the findings: its title, the finding cards, then the passed checks in a compact list.
     * $carry moves the area's last items (passed rows first, then cards) to a new page, under the heading
     * "<area> (continued)", so the closing CTA that follows does not stand alone on the last page (see render()).
     * A passed list is never split so that one row is left on either side: a short list moves whole, with its title.
     * Every check is still printed once, in the same order.
     *
     * @param string[] $cards     finding cards, in order
     * @param string[] $lines     passed rows (<tr>), in order
     */
    private static function area(string $label, string $meta, array $cards, string $passedTitle, string $head, array $lines, int $carry = 0): string
    {
        $passedTable = static fn(array $rows): string => '<table class="passed"><thead>' . $head . '</thead><tbody>' . implode('', $rows) . '</tbody></table>';
        $carryLines = min(max(0, $carry), count($lines));
        if ($carryLines > 0 && count($lines) - $carryLines <= 1) {
            $carryLines = count($lines);
        }
        $carryCards = min(max(0, $carry - $carryLines), count($cards));
        $stayCards = array_slice($cards, 0, count($cards) - $carryCards);
        $stayLines = array_slice($lines, 0, count($lines) - $carryLines);

        $out = '';
        $headed = false;
        $title = static function (bool $continued, string $card = '') use ($label, $meta, &$headed): string {
            $headed = true;
            return $card !== '' ? self::headingWithCard($label . ($continued ? ' (continued)' : ''), $meta, $card)
                : self::heading('h2', $label . ($continued ? ' (continued)' : ''), $meta);
        };
        foreach ($stayCards as $n => $card) {
            $out .= $n === 0 ? $title(false, $card) : $card;
        }
        if ($stayLines !== []) {
            $out .= ($stayCards !== [] ? self::heading('h3', $passedTitle) : $title(false)) . $passedTable($stayLines);
        }
        if ($carryCards + $carryLines === 0) {
            return $out;
        }

        // The carried part: on a new page, under the area title (marked "continued" when part of the area stayed).
        $continued = $headed;
        $out .= '<pagebreak />';
        foreach (array_slice($cards, count($stayCards)) as $n => $card) {
            $out .= $n === 0 ? $title($continued, $card) : $card;
        }
        if ($carryLines > 0) {
            $rows = array_slice($lines, count($stayLines));
            if ($carryLines === count($lines) && $cards !== []) {
                // The whole list moved: its own title, under the area title when no card came with it.
                $out .= $carryCards === 0
                    ? '<div class="keep-first">' . self::heading('h2', $label . ($continued ? ' (continued)' : ''), $meta, false)
                        . self::heading('h3', $passedTitle, '', false) . $passedTable($rows) . '</div>'
                    : self::heading('h3', $passedTitle) . $passedTable($rows);
            } else {
                // Part of the list (or a list with no cards above it): the area title, then the column heads again.
                $out .= '<div class="keep-first">' . self::heading('h2', $label . ($continued ? ' (continued)' : ''), $meta, false) . $passedTable($rows) . '</div>';
            }
        }
        return $out;
    }

    /**
     * Comparison findings: one card per check from ReportView::pairs, your page and the competitor page side by side.
     * Checks that did not pass on both pages come first; then the checks that passed on both, in a compact list.
     * A side where the check did not run says "Not checked". The fix belongs to your page.
     */
    private static function comparisonFindings(array $report, int $carry = 0): string
    {
        $mineGroups = $report['summary']['groups'];
        $theirGroups = $report['competitor']['summary']['groups'];

        $byGroup = [];
        foreach (ReportView::pairs($report['checks'], $report['competitor']['checks']) as $pair) {
            if ($pair['group'] !== 'speed') {
                $byGroup[$pair['group']][] = $pair;
            }
        }
        $out = '';
        $ordered = self::ordered($byGroup);
        $last = array_key_last($ordered);
        foreach ($ordered as $group => $pairs) {
            $scored = isset($mineGroups[$group]) || isset($theirGroups[$group]);
            $mineScore = $mineGroups[$group]['score'] ?? null;
            $theirScore = $theirGroups[$group]['score'] ?? null;
            $meta = !$scored ? 'Not part of the score'
                : ($mineScore === null && $theirScore === null ? 'Not measured on either page'
                    : 'Your page ' . self::outOf100($mineScore) . ' &middot; competitor ' . self::outOf100($theirScore));
            $bothPass = static fn(array $p): bool => ($p['mine']['status'] ?? null) === Result::PASS && ($p['theirs']['status'] ?? null) === Result::PASS;
            [$open, $passed] = self::split($pairs, $bothPass, static fn(array $p): string => $p['mine']['status'] ?? Result::NOT_MEASURED);
            $cards = $lines = [];
            foreach ($open as $pair) {
                $cards[] = self::findingCard('<strong>' . self::e($pair['title']) . '</strong>' . self::why($pair),
                    [self::side($pair['mine'], false, 'two'), self::side($pair['theirs'], false, 'two')], $pair['mine']);
            }
            foreach ($passed as $pair) {
                $lines[] = self::passedRow($pair['title'], [self::side($pair['mine'], false, 'two', 8.0), self::side($pair['theirs'], false, 'two', 8.0)]);
            }
            $out .= self::area(Scorer::LABELS[$group] ?? $group, $meta, $cards, 'Passed on both pages: ' . self::checkCount(count($passed)),
                '<tr><th class="p-check">Passed check</th><th class="p-side">Your page</th><th class="p-side">Competitor</th></tr>', $lines, $group === $last ? $carry : 0);
        }
        return $out;
    }

    /**
     * Splits an area's rows into those that need a look and those that passed. The first list is ordered by your
     * page's status (Fix, Improve, not measured, then the rest); a tie keeps the order of the report.
     *
     * @return array{0:array,1:array}
     */
    private static function split(array $rows, callable $passed, callable $status): array
    {
        $open = $done = [];
        foreach (array_values($rows) as $i => $row) {
            if ($passed($row)) {
                $done[] = $row;
            } else {
                $open[] = [self::ATTENTION[$status($row)] ?? 3, $i, $row];
            }
        }
        usort($open, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return [array_column($open, 2), $done];
    }

    private static function checkCount(int $n): string
    {
        return $n . ($n === 1 ? ' check' : ' checks');
    }

    /**
     * A finding: a plain row, as in the reference, with the check's name and why it matters, one result per page
     * (labelled in a comparison) and, when your page has something to do, the "What to do" row under it. The status
     * is in the badge, so the row has no coloured stripe. Each finding is one table that mPDF never splits, so a
     * check always stays with its fix.
     */
    private static function findingCard(string $name, array $cells, ?array $mine): string
    {
        $fix = $mine !== null ? trim((string) ($mine['message'] ?? '')) : '';
        $two = count($cells) > 1;
        $out = '<table class="fc" autosize="1"><tr>';
        if ($two) {
            // Each result says whose it is right above it; the columns are fixed so they line up from card to card.
            // Each result line is a block of its own: mPDF drops the left border (the chip's padding, see css()) of an
            // inline element that directly follows a block, so a chip right after the label would lose its left padding.
            $out .= '<td class="check">' . $name . '</td><td class="res2"><div class="fc-lbl">Your page</div><div>' . $cells[0]
                . '</div></td><td class="res2"><div class="fc-lbl">Competitor</div><div>' . $cells[1] . '</div></td></tr>';
        } else {
            $out .= '<td class="check">' . $name . '</td><td class="res">' . $cells[0] . '</td></tr>';
        }
        if ($fix !== '') {
            $out .= '<tr><td class="fix" colspan="' . ($two ? 3 : 2) . '"><strong class="fix-label">What to do' . ($two ? ' on your page' : '') . ':</strong> '
                . self::text($fix) . '</td></tr>';
        }
        return $out . '</table>';
    }

    /** A passed check: one compact line per check, its result per page, no explanation. */
    private static function passedRow(string $title, array $cells): string
    {
        return '<tr><td class="check"><strong>' . self::e($title) . '</strong></td><td class="res">' . implode('</td><td class="res">', $cells) . '</td></tr>';
    }

    /** A result cell: the status and, on the same line, what was found. Long evidence wraps under it. */
    /**
     * @param string $column 'two' (a comparison's result cell) or 'one' (a single audit's), for the width a long
     *                       address may take (see wrapAddresses()); $size is the text size of that cell in pt
     */
    private static function side(?array $side, bool $lighthouse = false, string $column = '', float $size = 9.0): string
    {
        if ($side === null) {
            return '<span class="chip chip-none">&ndash; Not checked</span>';
        }
        $evidence = (string) ($side['evidence'] ?? '');
        if ($lighthouse) {
            $evidence = self::lighthouseValue($evidence);
        }
        $box = null;
        if (isset(self::RESULT_WIDTH[$column])) {
            // The first line starts after the chip: its word in bold 7.5 pt, the sign, 8 pt of padding and a space,
            // counted generously (too narrow only adds an early break).
            $label = self::STATUS_LABELS[$side['status']] ?? (string) $side['status'];
            $chip = self::width($label, 7.5) * 1.12 + 2.5 + 2.82 + self::width(' ', $size);
            $box = [self::RESULT_WIDTH[$column] - $chip, self::RESULT_WIDTH[$column], $size];
        }
        return self::chip($side['status']) . ($evidence !== '' ? ' <span class="evidence">' . self::text($evidence, $box) . '</span>' : '');
    }

    /**
     * The Lighthouse result as the PDF prints it: "82 out of 100 (Google Lighthouse, mobile lab test)" becomes
     * "82 / 100", because the row already says whose score it is and its range. Anything else (a "Not measured"
     * reason) is printed as stored. The stored evidence itself is never changed.
     */
    public static function lighthouseValue(string $evidence): string
    {
        return (string) preg_replace('/^(\d{1,3}) out of 100\b.*$/s', '$1 / 100', $evidence);
    }

    /**
     * A section heading kept with the table after it (mPDF use_kwt), drawn as a band. When the table has to start on
     * the next page, mPDF lays the table out first and draws the heading after it, and the table's layout leaves its
     * own font selected in the PDF while mPDF still believes the heading's font is current: the heading came out in
     * table type. Its first letter is therefore set 0.02 pt larger than the rest (a size used nowhere else), so mPDF
     * always writes the font before the first letter and again before the rest. Invisible on the page; the text still
     * reads and copies as one word. $raw is plain text; $meta (the scores beside the title) is already escaped HTML.
     * $kept false leaves keep-with-table off, for a heading that a page-break-inside:avoid block keeps with its
     * table instead (see areaCards()).
     */
    private static function heading(string $tag, string $raw, string $meta = '', bool $kept = true): string
    {
        $first = mb_substr($raw, 0, 1);
        return '<' . $tag . ($kept ? ' keep-with-table="1"' : '') . '><span class="' . $tag . '">' . self::e($first) . '</span>' . self::e(mb_substr($raw, 1))
            . ($meta !== '' ? '<span class="band-meta">&nbsp;&nbsp;&middot;&nbsp;&nbsp;' . $meta . '</span>' : '') . '</' . $tag . '>';
    }

    /**
     * A section title with its first finding card, as one block that is never split. Not a keep-with-table heading:
     * a card is a page-break-inside:avoid table, and mPDF counts a kept heading twice when it checks whether such a
     * table fits (see areaCards()), so a heading and card that fit were moved to the next page. The block keeps them
     * together and is measured once.
     */
    private static function headingWithCard(string $raw, string $meta, string $card): string
    {
        return '<div class="keep-first">' . self::heading('h2', $raw, $meta, false) . $card . '</div>';
    }

    private static function why(array $row): string
    {
        return (string) ($row['why'] ?? '') !== '' ? '<div class="why">' . self::e($row['why']) . '</div>' : '';
    }

    private static function runningHeader(array $report): string
    {
        $what = isset($report['competitor']) ? 'SEO comparison report' : 'SEO audit report';
        $host = Url::host($report['url']);
        $logo = self::logo($report, 'dark', '4.2mm');
        $mark = $logo !== '' ? $logo . ' <span class="rh-what">&nbsp;Halo Audit &middot; ' . $what . '</span>' : 'HALO AUDIT <span class="rh-what">&middot; ' . $what . '</span>';
        return '<table class="running running-head"><tr><td class="rh-brand">' . $mark . '</td>'
            . '<td class="rh-right">' . ($host !== '' ? self::e($host) . ' &middot; ' : '') . self::e(self::date($report)) . '</td></tr></table>';
    }

    /**
     * The credit with its first "Halo Audit" linked to the product page: only those words, not the whole footer.
     * A credit the filter rewrote without them has no link. The address is a constant, never report data.
     */
    private static function runningFooter(array $report): string
    {
        $credit = preg_replace('/Halo Audit/', '<a class="rf-link" href="' . self::PRODUCT_URL . '">Halo Audit</a>', self::e(self::credit($report)), 1);
        return '<table class="running running-foot"><tr><td class="rf-credit">' . $credit . '</td>'
            . '<td class="rf-page">Page {PAGENO} of {nbpg}</td></tr></table>';
    }

    /* Rings and bars: SVG built here from integers and fixed colours only, passed to mPDF as data: URIs */

    /** A score ring: a grey track, the score as an arc, the number in the middle ("–" when not measured). */
    private static function ring(?int $score, string $colour): string
    {
        $arc = '';
        if ($score !== null && $score >= 100) {
            $arc = '<circle cx="60" cy="60" r="50" fill="none" stroke="' . $colour . '" stroke-width="12"/>';
        } elseif ($score !== null && $score > 0) {
            $a = $score / 100 * 2 * M_PI;
            $arc = '<path d="M 60 10 A 50 50 0 ' . ($score > 50 ? 1 : 0) . ' 1 ' . round(60 + 50 * sin($a), 2) . ' ' . round(60 - 50 * cos($a), 2)
                . '" fill="none" stroke="' . $colour . '" stroke-width="12" stroke-linecap="round"/>';
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120" viewBox="0 0 120 120">'
            . '<circle cx="60" cy="60" r="50" fill="none" stroke="' . self::TRACK . '" stroke-width="12"/>' . $arc
            . '<text x="60" y="' . ($score === null ? 69 : 72) . '" text-anchor="middle" font-family="' . (self::brandFonts() !== [] ? 'oswaldbold" font-weight="normal' : 'dejavusans" font-weight="bold')
            . '" font-size="' . ($score === null ? 26 : 36) . '" fill="' . self::INK . '">' . ($score === null ? '&#8211;' : $score) . '</text></svg>';
    }

    /**
     * A score bar: your page's in the accent colour, the competitor's thinner and grey (apart on a grayscale print).
     * $length is in the same units as the height, so a longer bar drawn at a wider width keeps the same thickness.
     */
    private static function bar(?int $score, string $who, int $length = 200): string
    {
        $h = $who === 'you' ? 8 : 5;
        $fill = $score === null ? 0 : max(0, min(100, $score)) * $length / 100;
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $length . '" height="' . $h . '" viewBox="0 0 ' . $length . ' ' . $h . '">'
            . '<rect x="0" y="0" width="' . $length . '" height="' . $h . '" rx="' . ($h / 2) . '" fill="' . self::TRACK . '"/>'
            . ($fill > 0 ? '<rect x="0" y="0" width="' . $fill . '" height="' . $h . '" rx="' . ($h / 2) . '" fill="' . ($who === 'you' ? self::ACCENT : self::THEM_BAR) . '"/>' : '')
            . '</svg>';
    }

    private static function svg(string $svg, string $width): string
    {
        return '<img src="data:image/svg+xml;base64,' . base64_encode($svg) . '" width="' . $width . '" />';
    }

    /* Small pieces */

    /** Escaped text for element content (never an attribute: a Devanagari run comes back inside <span lang="hi">). */
    private static function e($value): string
    {
        return PdfFonts::tag(self::fallback(htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8')));
    }

    /**
     * Wraps each run of characters the brand fonts cannot draw in <span class="fb">, which is set in DejaVu Sans
     * Condensed (see BRAND_CHARS). Works on escaped text: entities are ASCII and stay as they are. Devanagari and
     * white space are left alone. Without the brand fonts everything is DejaVu already, so nothing is wrapped.
     */
    private static function fallback(string $escaped): string
    {
        // The line-break marker of text() (U+E000) is left alone: it becomes <br /> after this.
        if (self::brandFonts() === [] || !preg_match('/[^\s' . self::BRAND_CHARS . '\x{E000}]/u', $escaped)) {
            return $escaped;
        }
        return (string) preg_replace('/[^\s' . self::BRAND_CHARS . self::DEVANAGARI_CHARS . '\x{E000}]+/u', '<span class="fb">$0</span>', $escaped);
    }

    /**
     * A sign written by the PDF itself outside a chip (✓ ✗ ○ ▲ ▼), set in the fallback font when the brand fonts are
     * in use. "!" is in Lato, so it is left as it is.
     */
    private static function sign(string $html): string
    {
        return self::brandFonts() === [] || $html === '!' ? $html : '<span class="fb">' . $html . '</span>';
    }

    /**
     * Escaped text from the report. With a $box ([width mm of the first line, width mm of later lines, size pt]),
     * a long address (see wrapAddresses()) gets a line break after "/", "?", "&", "=", "." or "-" wherever a line is
     * full, measured with the PDF's own fonts, so it never breaks in the middle of a word. The break is a real <br>
     * placed only where a line ends: no invisible break characters go into the text (mPDF keeps every zero-width
     * space it does not break at, and prints soft hyphens as "-"), so the lines of an address copied from the PDF,
     * joined, are the address. Anything else wraps because every table is set to overflow:wrap (see css()).
     */
    private static function text(string $raw, ?array $box = null): string
    {
        if ($box === null) {
            return self::e($raw);
        }
        $raw = str_replace(self::BREAK_MARK, '', $raw); // Report data never brings its own break.
        return str_replace(self::BREAK_MARK, '<br />', self::e(self::wrapAddresses($raw, ...$box)));
    }

    /**
     * Text widths in mm for wrapAddresses(), from css() (the page is 174 mm between the margins): the cover's value
     * column (160 mm inside the band, less the 34 mm label), the score panel's host (36 mm, less 3 mm padding), and
     * a result cell in a comparison (59 mm) or a single audit (the 118 mm beside the 56 mm check column), less 6 mm
     * padding. Sizes in pt: body 9, small 8.
     */
    private const BOX_COVER = [126.0, 126.0, 9.0];
    private const BOX_HOST = [33.0, 33.0, 8.0];
    private const RESULT_WIDTH = ['two' => 53.0, 'one' => 112.0];

    /** Where wrapAddresses() breaks a line; turned into <br /> after escaping. A private-use character. */
    private const BREAK_MARK = "\u{E000}";

    /** Width measured, in mm, is trusted only up to this share of a line, less a margin: a line too long breaks mid-word. */
    private const LINE_SHARE = 0.98;
    private const LINE_MARGIN = 1.0;

    /** mPDF set up once to measure text with the PDF's fonts (see width()). */
    private static ?\Mpdf\Mpdf $meter = null;

    /**
     * Lays out $raw the way mPDF does (word by word, breaking at spaces) and puts BREAK_MARK into each address-like
     * run that does not fit where it starts: a run of 20 or more characters with "/" in it, or a host-like run
     * ("www.example-school.edu"), made only of characters the brand fonts draw (Devanagari and other scripts are
     * not measured reliably, so they are left to overflow:wrap). Breaks go after "/", "?", "&" or "=" first, then
     * after "." and last after "-" (some viewers drop a hyphen at a line end when text is copied). A part that is
     * wider than a whole line on its own is left to overflow:wrap.
     */
    private static function wrapAddresses(string $raw, float $first, float $rest, float $size): string
    {
        if (!preg_match('#\S{20,}#u', $raw)) {
            return $raw;
        }
        $fit = static fn(float $w): float => $w * self::LINE_SHARE - self::LINE_MARGIN;
        $line = $fit($first);
        $x = 0.0;
        $space = self::width(' ', $size);
        $out = [];
        foreach (explode(' ', $raw) as $word) {
            $w = self::width($word, $size);
            $gap = $x > 0 ? $space : 0.0;
            if ($x + $gap + $w <= $line) {
                $out[] = $word;
                $x += $gap + $w;
                continue;
            }
            $breakable = mb_strlen($word) >= 20 && preg_match('#^[' . self::BRAND_CHARS . ']+$#u', $word)
                && (strpos($word, '/') !== false || preg_match('#^[\w-]+(\.[\w-]+){2,}$#', $word));
            if (!$breakable) {
                // mPDF moves the word to the next line (or overflow:wrap breaks it if it is wider than a line).
                $out[] = $word;
                $x = $w;
                $line = $fit($rest);
                continue;
            }
            // The run starts on this line if its first part fits after the gap; otherwise mPDF breaks at the space.
            if ($x > 0 && $x + $gap + self::width(self::firstPart($word), $size) > $line) {
                $x = 0.0;
                $gap = 0.0;
                $line = $fit($rest);
            }
            $text = '';
            $avail = $line - $x - $gap;
            $fresh = $x == 0; // At the start of a line: nothing earlier on it to move down.
            while ($word !== '') {
                $w = self::width($word, $size);
                if ($w <= $avail) {
                    $text .= $word;
                    $avail -= $w;
                    break;
                }
                $cut = self::cutAt($word, $avail, $size);
                if ($cut === 0) {
                    if ($fresh) {
                        // Not even the next part fits a whole line: it is written as it is and overflow:wrap breaks it.
                        $text .= $word;
                        $avail = 0.0;
                        break;
                    }
                    // Nothing more fits on this line: the rest starts on the next one.
                    $text .= $text === '' ? '' : self::BREAK_MARK;
                    $avail = $fit($rest);
                    $fresh = true;
                    continue;
                }
                $text .= mb_substr($word, 0, $cut) . self::BREAK_MARK;
                $word = mb_substr($word, $cut);
                $avail = $fit($rest);
                $fresh = true;
            }
            $out[] = $text;
            $line = $fit($rest);
            $x = $line - $avail;
        }
        return implode(' ', $out);
    }

    /** The part of an address up to its first break point (see cutAt()). */
    private static function firstPart(string $word): string
    {
        return preg_match('#^.*?[/?&=.\-]#u', $word, $m) ? $m[0] : $word;
    }

    /**
     * How many characters of $word go on a line $avail mm wide, breaking after a "/", "?", "&" or "=" when the last
     * one that fits fills at least three quarters of what could fit, else after a "." on the same terms, else at
     * the furthest break point that fits (a "-" included), so a preference never costs a mostly empty line.
     * 0 when no break point fits.
     */
    private static function cutAt(string $word, float $avail, float $size): int
    {
        $best = [];
        foreach (['#[/?&=]#u', '#\.#u', '#-#u'] as $i => $class) {
            $best[$i] = 0;
            preg_match_all($class, $word, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[0] as [$char, $byte]) {
                $len = mb_strlen(substr($word, 0, $byte)) + 1;
                if ($len < mb_strlen($word) && self::width(mb_substr($word, 0, $len), $size) <= $avail) {
                    $best[$i] = $len;
                }
            }
        }
        $furthest = max($best);
        foreach ([0, 1] as $i) {
            if ($best[$i] > 0 && $best[$i] >= 0.75 * $furthest) {
                return $best[$i];
            }
        }
        return $furthest;
    }

    /**
     * The width in mm of plain text at $size pt in a role's font ('text' Lato, 'display' Oswald; DejaVu Sans
     * Condensed without the brand fonts). Runs the brand fonts lack are measured in the font that draws them:
     * FreeSans for Devanagari (approximate: shaping can change it a little), DejaVu Sans Condensed for the rest.
     */
    private static function width(string $text, float $size, string $role = 'text', string $style = ''): float
    {
        if (self::$meter === null) {
            $refused = self::$refused;
            self::$meter = self::engine([]);
            self::$refused = $refused;
        }
        $total = 0.0;
        preg_match_all('/([' . self::DEVANAGARI_CHARS . ']+)|([\s' . self::BRAND_CHARS . ']+)|(.)/su', $text, $runs, PREG_SET_ORDER);
        foreach ($runs as $run) {
            $family = isset($run[3]) ? 'dejavusanscondensed' : (($run[1] ?? '') !== '' ? PdfFonts::FAMILY : self::font($role));
            self::$meter->SetFont($family, $family === PdfFonts::FAMILY ? '' : $style, $size);
            $total += (float) self::$meter->GetStringWidth($run[0]);
        }
        return $total;
    }

    /**
     * The cover title's size: 24 pt as in the reference, or 21 or 19 pt when a long keyword would wrap it (a two-line
     * title makes the cover taller and pushes the area cards or the priority fixes to page 2). 19 pt and still too
     * long: it wraps. The title is measured as plain text on the band's 160 mm.
     */
    private static function titleSize(string $plain): float
    {
        foreach ([24.0, 21.0, 19.0] as $size) {
            if (self::width($plain, $size, 'display', 'B') <= 160 * self::LINE_SHARE) {
                return $size;
            }
        }
        return 19.0;
    }

    private static function date(array $report): string
    {
        return gmdate('j F Y', (int) $report['created_at']);
    }

    /** A status chip: the sign in the fallback font when the brand fonts lack it (see sign()), the word in Lato. */
    private static function chip(string $status): string
    {
        $label = self::STATUS_LABELS[$status] ?? $status;
        $sign = self::STATUS_SIGNS[$status] ?? '';
        $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        return '<span class="chip chip-' . $esc($status) . '">' . ($sign !== '' ? self::sign($esc($sign)) . ' ' : '') . $esc($label) . '</span>';
    }

    private static function outOf100(?int $score): string
    {
        return $score === null ? 'not measured' : self::e($score) . ' / 100';
    }

    /** @return array{pass:int,warn:int,fail:int,not_measured:int} */
    private static function totals(array $groups): array
    {
        $counts = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'not_measured' => 0];
        foreach ($groups as $data) {
            foreach ($counts as $status => $n) {
                $counts[$status] = $n + (int) ($data[$status] ?? 0);
            }
        }
        return $counts;
    }

    /** The score panel's difference: a large signed number and whether your page is ahead or behind. */
    private static function differenceBig(?int $mine, ?int $theirs): string
    {
        if ($mine === null || $theirs === null) {
            return '<div class="d-num d-level">&ndash;</div><div class="sp-of">Not comparable</div>';
        }
        $d = $mine - $theirs;
        if ($d === 0) {
            return '<div class="d-num d-level">0</div><div class="sp-of">Level with the competitor</div>';
        }
        return '<div class="d-num ' . ($d > 0 ? 'd-ahead">' . self::sign('&#9650;') . ' +' . $d : 'd-behind">' . self::sign('&#9660;') . ' ' . $d) . '</div><div class="sp-of">point'
            . (abs($d) === 1 ? '' : 's') . ($d > 0 ? ' ahead' : ' behind') . '</div>';
    }

    /** An area card's difference: "▲ +12 ahead", "▼ -3 behind", "level" or "not comparable". */
    private static function differenceSmall(?int $mine, ?int $theirs): string
    {
        if ($mine === null || $theirs === null) {
            return '<span class="diff">not comparable</span>';
        }
        $d = $mine - $theirs;
        if ($d === 0) {
            return '<span class="diff">level</span>';
        }
        return $d > 0 ? '<span class="diff diff-ahead">' . self::sign('&#9650;') . ' +' . $d . ' ahead</span>' : '<span class="diff diff-behind">' . self::sign('&#9660;') . ' ' . $d . ' behind</span>';
    }

    private static function listText(array $parts): string
    {
        $last = array_pop($parts);
        return $parts === [] ? (string) $last : implode(', ', $parts) . ' and ' . $last;
    }

    /** Groups in the order of Scorer::LABELS; a group the labels do not know comes last. */
    private static function ordered(array $byGroup): array
    {
        $order = array_flip(array_keys(Scorer::LABELS));
        uksort($byGroup, static fn($a, $b): int => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));
        return $byGroup;
    }

    /**
     * Only CSS that mPDF 8.3 renders as intended (checked on rendered pages). Widths are in mm: the page is 174 mm
     * wide between the margins. The brand look (BrandPdf): charcoal ink, cream panels, olive for rules, bars and
     * rings only, never for text (the olive-bright eyebrow on the charcoal cover is the one exception, as in the
     * brand reference); Lato for text, Oswald for headings and numbers. Status colours are BrandPdf::STATUS (dark
     * text on a light tint, each at least 4.9:1). Type scale, nothing below 7.5 pt: title 24, metric numbers 20,
     * section 14, lede and area scores 11, body and check names (bold) 9, supporting text 8, labels 7.5. Score
     * numbers in the rings are part of the SVG. (mPDF does not apply a '.class strong' rule inside these table
     * cells, so check names take the body size, in bold.)
     */
    private static function css(): string
    {
        $s = BrandPdf::STATUS;
        $t = [
            'ink' => self::INK, 'text' => BrandPdf::TEXT, 'muted' => self::MUTED, 'line' => '#e2dfd8', 'hair' => '#ece9e2',
            'soft' => BrandPdf::CREAM, 'deep' => BrandPdf::CREAM_DARK, 'accent' => self::ACCENT, 'them' => self::THEM,
            'pass' => $s['pass'][1], 'warn' => $s['warn'][1], 'fail' => $s['fail'][1], 'nm' => $s['not_measured'][1],
            'passBg' => $s['pass'][2], 'warnBg' => $s['warn'][2], 'failBg' => $s['fail'][2], 'nmBg' => $s['not_measured'][2],
            // "Ahead" in the Pass status colour: olive is never text (brand README; owner choice, 23 Oct).
            'ahead' => $s['pass'][1],
            // On the charcoal cover: 9.70:1, 5.26:1 and 5.20:1 on #1c2529.
            'onDark' => '#c9cdc9', 'onDarkKey' => '#8f9893', 'onDarkLine' => '#3a464c', 'eyebrow' => BrandPdf::OLIVE_BRIGHT,
            'textFont' => self::font('text'), 'display' => self::font('display'), 'number' => self::font('number'),
            'title' => '24pt', 'metric' => '20pt', 'section' => '14pt', 'lead' => '11pt',
            'body' => '9pt', 'small' => '8pt', 'label' => '7.5pt',
        ];
        // No ligatures: an "fi" drawn as one glyph is copied and searched as U+FB01, which breaks addresses.
        return 'body{font-family:' . $t['textFont'] . ';font-size:' . $t['body'] . ';color:' . $t['text'] . ';line-height:1.45;font-feature-settings:"liga" 0}'
            // overflow:wrap: a run too long for its column (an address) breaks there instead of shrinking the table.
            . 'table{border-collapse:collapse;width:100%;overflow:wrap}'
            . 'td,th{vertical-align:top;text-align:left;font-feature-settings:"liga" 0}' // Cells do not inherit it from body.
            // Characters the brand fonts lack (see fallback()).
            . '.fb{font-family:dejavusanscondensed}'
            // Cover band: charcoal, white title (set inline), light text
            . '.hero{background:' . $t['ink'] . ';padding:5mm 7mm 4mm}'
            . '.hero-top td{padding:0;vertical-align:middle}'
            . '.hero-logo{height:6.5mm}'
            . '.brand{text-align:right;font-size:' . $t['label'] . ';font-weight:bold;letter-spacing:2.5pt;color:' . $t['eyebrow'] . '}'
            . '.eyebrow{font-size:' . $t['label'] . ';font-weight:bold;letter-spacing:2pt;text-transform:uppercase;color:' . $t['eyebrow'] . '}.eyebrow-below{margin-top:4mm}'
            // The only h1 is the cover title, on charcoal (an element rule, which mPDF applies; ".hero h1" it would not).
            . 'h1{font-family:' . $t['display'] . ';font-size:' . $t['title'] . ';font-weight:bold;line-height:1.15;margin:0;color:#ffffff}'
            . '.lede{font-size:' . $t['lead'] . ';color:' . $t['onDark'] . ';margin:0 0 3mm}'
            . '.meta td{padding:1.3mm 0;border-top:0.5pt solid ' . $t['onDarkLine'] . '}'
            . '.meta-label{width:34mm;font-size:' . $t['label'] . ';font-weight:bold;letter-spacing:1pt;color:' . $t['onDarkKey'] . ';text-transform:uppercase;padding-top:2mm}'
            . '.meta-value{font-size:' . $t['body'] . ';color:#dfe2df}'
            . '.status-note{font-size:' . $t['small'] . ';color:' . $t['onDark'] . ';margin-top:1mm}'
            // Score panel
            . '.panel{border:0.75pt solid ' . $t['line'] . ';border-radius:4mm;padding:4mm 5mm;margin-top:4mm}'
            // The columns' own padding is set as ".sp td.x": a plain ".x" loses to ".sp td{padding:0}" (class and
            // element beat one class), which had left the difference and meaning columns against the divider.
            . '.sp td{vertical-align:middle;padding:0}'
            . '.sp-ring{width:29mm}'
            . '.sp td.sp-info{width:36mm;padding-left:3mm}'
            . '.sp-label{font-size:' . $t['label'] . ';font-weight:bold;letter-spacing:1pt;text-transform:uppercase;color:' . $t['muted'] . '}'
            . '.sp-host{font-size:' . $t['small'] . ';color:' . $t['ink'] . ';margin-top:0.5mm}'
            . '.sp-of{font-size:' . $t['small'] . ';color:' . $t['muted'] . ';margin:0.5mm 0 1.5mm}'
            . '.sp td.sp-diff{border-left:0.75pt solid ' . $t['line'] . ';padding:0 1mm 0 5mm}'
            . '.sp td.sp-meaning{border-left:0.75pt solid ' . $t['line'] . ';padding:0 0 0 5mm}'
            . '.d-num{font-family:' . $t['number'] . ';font-size:' . $t['metric'] . ';margin-top:1mm}.d-ahead{color:' . $t['ahead'] . '}.d-behind{color:' . $t['fail'] . '}.d-level{color:' . $t['muted'] . '}'
            // As with the chips, a border in the badge colour gives the room around the text (padding on a block inside a
            // table cell is ignored by mPDF).
            . '.grade{font-family:' . $t['display'] . ';font-size:' . $t['small'] . ';font-weight:bold;border:solid ' . $t['ink'] . ';border-width:1.5pt 4pt;color:#ffffff;background:' . $t['ink'] . '}'
            . '.panel-meaning{margin-top:3mm;padding-top:3mm;border-top:0.5pt solid ' . $t['hair'] . '}'
            . '.box-title{font-size:' . $t['label'] . ';font-weight:bold;letter-spacing:1pt;color:' . $t['ink'] . ';text-transform:uppercase;margin-bottom:1.5mm}'
            . '.meaning-text{font-size:' . $t['body'] . ';line-height:1.45}'
            . '.scale{font-size:' . $t['label'] . ';color:' . $t['muted'] . ';margin-top:1.5mm}'
            // Card grids (metric cards, area cards): square cards in a table, so all cards in a row are as tall
            . '.cards{margin-top:4mm}'
            . '.cgap{width:3mm;padding:0}'
            // Metric cards: cream tiles with an olive top rule; the sign and word in the label carry the status.
            . '.kpi{background:' . $t['soft'] . ';border-top:2pt solid ' . $t['accent'] . ';padding:2.5mm 3.5mm 2.5mm}'
            . '.k-label{font-size:' . $t['label'] . ';font-weight:bold;letter-spacing:0.8pt;text-transform:uppercase;color:' . $t['muted'] . '}'
            . '.k-num{font-family:' . $t['number'] . ';font-size:' . $t['metric'] . ';line-height:1.15;color:' . $t['ink'] . '}'
            . '.k-sub{font-size:' . $t['label'] . ';color:' . $t['muted'] . '}'
            // Area cards: cream, olive top rule; head (name, score), bars, a deeper cream foot (top issue); see areaCards().
            . 'table.area{margin-top:3mm;page-break-inside:avoid}.keep-first{page-break-inside:avoid}'
            . '.area td.ca-head{width:55.5mm;vertical-align:middle;background:' . $t['soft'] . ';border-top:2.5pt solid ' . $t['accent'] . ';padding:2.5mm 0 1.5mm 3.5mm}'
            . '.area td.ca-score{width:30mm;vertical-align:middle;text-align:right;background:' . $t['soft'] . ';border-top:2.5pt solid ' . $t['accent'] . ';padding:2.5mm 3.5mm 1.5mm 0}'
            . '.area td.ca-bar{background:' . $t['soft'] . ';padding:1mm 3.5mm}'
            // Kept tight: a comparison card has two bars and a line, and page 1 must still hold both rows of cards.
            . '.area td.ca-vs{background:' . $t['soft'] . ';padding:0 3.5mm 0.7mm}'
            . '.area td.ca-bar-end{background:' . $t['soft'] . ';padding:0 3.5mm 2mm}'
            // A single audit's card has its one bar only: no taller than before, so page 1 still holds the priority fixes.
            . '.area td.ca-bar-solo{background:' . $t['soft'] . ';padding:1mm 3.5mm 2.5mm}'
            . '.area td.ca-foot{background:' . $t['deep'] . ';padding:1.6mm 3.5mm}'
            . '.ca-empty{padding:0}'
            . '.c-name{font-family:' . $t['display'] . ';font-size:' . $t['lead'] . ';color:' . $t['ink'] . '}'
            . '.c-score{font-family:' . $t['number'] . ';font-size:' . $t['metric'] . ';line-height:1.1;color:' . $t['ink'] . '}.c-of{font-family:' . $t['textFont'] . ';font-size:' . $t['small'] . ';color:' . $t['muted'] . '}'
            . '.c-none{font-size:' . $t['body'] . ';font-weight:bold;color:' . $t['muted'] . '}'
            // Margins and padding of a block inside a cell are ignored; line-height parts the counts from the name.
            . '.c-counts{font-size:' . $t['label'] . ';line-height:2.1}'
            . '.c-vs{font-size:' . $t['label'] . ';color:' . $t['muted'] . ';line-height:1.3}'
            . '.c-top-label{font-size:' . $t['label'] . ';font-weight:bold;letter-spacing:0.8pt;text-transform:uppercase;color:' . $t['muted'] . '}'
            . '.c-top{font-size:' . $t['body'] . ';font-weight:bold;color:' . $t['ink'] . '}'
            . '.diff{color:' . $t['muted'] . '}.diff-ahead{color:' . $t['ahead'] . ';font-weight:bold}.diff-behind{color:' . $t['fail'] . ';font-weight:bold}'
            // Section headings, kept with the table after them (see heading()). No background: mPDF paints a heading's
            // background before it decides to move the heading to the next page, which left an empty band behind.
            . 'h2{font-family:' . $t['display'] . ';font-size:' . $t['section'] . ';font-weight:bold;color:' . $t['ink'] . ';padding:0 0 1.8mm;border-bottom:1.2pt solid ' . $t['accent'] . ';margin:6mm 0 3mm}'
            . 'span.h2{font-size:14.02pt}'
            . '.band-meta{font-family:' . $t['textFont'] . ';font-size:' . $t['small'] . ';font-weight:normal;color:' . $t['muted'] . '}'
            . 'h3{font-size:' . $t['label'] . ';font-weight:bold;letter-spacing:1pt;text-transform:uppercase;color:' . $t['muted'] . ';margin:3mm 0 1.5mm}'
            . 'span.h3{font-size:7.52pt}'
            // Priority fixes
            . '.top td{padding:2.2mm 2.5mm;border-bottom:0.5pt solid ' . $t['hair'] . '}'
            // Column heads, as in the reference: small capitals over a charcoal rule. Each later fix is a table of
            // its own (see topFixes()), so every column has a fixed width and the rows line up.
            . '.top th{font-size:' . $t['label'] . ';font-weight:bold;letter-spacing:1pt;text-transform:uppercase;color:' . $t['muted'] . ';padding:1.5mm 2.5mm;border-bottom:0.75pt solid ' . $t['ink'] . '}'
            . 'table.top{page-break-inside:avoid}'
            . '.t-n{width:8mm;font-family:' . $t['number'] . ';font-size:' . $t['lead'] . ';color:' . $t['ink'] . '}.t-result{width:20mm}.top td.check,.top th.check{width:56mm}'
            . '.top th.t-n{font-family:' . $t['textFont'] . ';font-size:' . $t['label'] . ';color:' . $t['muted'] . '}'
            . ''
            // Clear of the priority table above; no bottom margin, as the next section heading brings its own.
            . '.legend{margin:4mm 0 0;page-break-inside:avoid}.legend td{border:0}'
            . '.legend-title{font-size:' . $t['label'] . ';font-weight:bold;letter-spacing:1pt;color:' . $t['muted'] . ';text-transform:uppercase;padding:0 0 2.5mm}'
            . '.legend-cell{padding:0 2mm 0 0;font-size:' . $t['label'] . '}'
            . '.legend-pass,.legend-fail{width:21%}.legend-warn{width:22%}.legend-not_measured{width:36%}'
            . '.legend-text{font-size:' . $t['label'] . ';color:' . $t['muted'] . '}'
            // Findings: plain rows as in the reference (a hairline under each), one table each, never split
            . 'table.fc{page-break-inside:avoid;border-bottom:0.5pt solid ' . $t['line'] . ';margin-bottom:1mm}'
            . '.fc td{padding:2.2mm 3mm}'
            . '.fc td.check{width:56mm;color:' . $t['ink'] . '}'
            . '.fc td.res2{width:59mm}'
            . '.fc-lbl{font-size:' . $t['label'] . ';font-weight:bold;letter-spacing:0.8pt;text-transform:uppercase;color:' . $t['muted'] . ';margin-bottom:1mm}'
            . '.why{font-size:' . $t['small'] . ';color:' . $t['muted'] . ';margin-top:0.8mm;line-height:1.35}'
            . '.metric{font-size:' . $t['small'] . ';color:' . $t['muted'] . ';margin-top:0.8mm;line-height:1.35}'
            // "What to do": a cream row with an olive rule on the left and a charcoal label (brand kit).
            . '.fc td.fix{background:' . $t['soft'] . ';border-left:2pt solid ' . $t['accent'] . ';padding:2mm 3mm;font-size:' . $t['body'] . '}'
            . '.fix-label{color:' . $t['ink'] . '}'
            . '.halo-row{border:0.75pt solid ' . $t['line'] . ';background:' . $t['soft'] . ';margin-bottom:2.5mm;page-break-inside:avoid}'
            . '.halo-row td{padding:2.5mm 3mm;vertical-align:middle}.halo-name{width:55mm}.halo-score{font-family:' . $t['number'] . ';font-size:' . $t['lead'] . ';color:' . $t['ink'] . '}'
            // Passed checks: one line each, no explanation
            . 'table.passed{border:0.75pt solid ' . $t['line'] . ';margin-bottom:2mm}'
            . '.passed th{font-size:' . $t['label'] . ';font-weight:bold;letter-spacing:0.6pt;color:' . $t['muted'] . ';text-transform:uppercase;background:' . $t['soft'] . ';padding:1.5mm 3mm;border-bottom:0.5pt solid ' . $t['line'] . '}'
            . '.passed td{padding:1.3mm 3mm;border-bottom:0.5pt solid ' . $t['hair'] . '}'
            // The same columns as the finding cards above (stripe and check name, then each page's result), so
            // "Your page" and "Competitor" line up down the whole section.
            . '.p-check{width:56mm}.p-side{width:59mm}'
            . '.passed td.res{font-size:' . $t['small'] . '}'
            // Results: a sign and a word on a light tint, readable without colour (BrandPdf::STATUS)
            // Chip words are in the body font (Lato); only the sign is set in DejaVu, by sign() (brand kit: DejaVu for symbols only).
            // The space inside a chip is a border in its own tint: mPDF draws an inline background tight to the glyphs and
            // ignores inline padding, but it draws inline borders. Thinner top and bottom keep wrapped lines apart.
            . '.chip{font-size:' . $t['label'] . ';font-weight:bold;border-style:solid;border-width:' . self::CHIP_PAD . '}'
            . '.chip-pass{color:' . $t['pass'] . ';background:' . $t['passBg'] . ';border-color:' . $t['passBg'] . '}'
            . '.chip-warn{color:' . $t['warn'] . ';background:' . $t['warnBg'] . ';border-color:' . $t['warnBg'] . '}'
            . '.chip-fail{color:' . $t['fail'] . ';background:' . $t['failBg'] . ';border-color:' . $t['failBg'] . '}'
            . '.chip-not_measured,.chip-none{color:' . $t['nm'] . ';background:' . $t['nmBg'] . ';border-color:' . $t['nmBg'] . '}'
            . '.note{font-size:' . $t['small'] . ';color:' . $t['muted'] . ';line-height:1.45;margin:2mm 0 0;padding:2.5mm 3.5mm;background:' . $t['soft'] . ';border-radius:2mm}'
            // Running header (page 2 on) and footer (every page)
            . '.running td{font-size:' . $t['label'] . ';color:' . $t['muted'] . ';border:0}'
            . '.running-head td{border-bottom:0.5pt solid ' . $t['line'] . ';padding-bottom:3pt;vertical-align:middle}'
            . '.rh-brand{width:84mm;white-space:nowrap;font-weight:bold;letter-spacing:1.5pt;color:' . $t['ink'] . '}.rh-what{letter-spacing:0;font-weight:normal;color:' . $t['muted'] . '}'
            . '.rh-right{text-align:right}'
            . '.running-foot td{border-top:0.5pt solid ' . $t['line'] . ';padding-top:4pt}'
            . '.rf-page{text-align:right;width:25mm;white-space:nowrap}'
            . 'a.rf-link{color:' . $t['ink'] . ';text-decoration:none}';
    }
}
