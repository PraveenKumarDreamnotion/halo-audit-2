<?php
namespace HaloAudit\Wp;

/**
 * tm-brand look for the Halo Audit PDF (mPDF 8).
 *
 * Source of truth: tm-brand/DESIGN.md. Charcoal #1c2529, cream #faf7f2, olive #808000 (accent only,
 * never body text), Lato for text, Oswald for headings and numbers.
 *
 * Fonts live in assets/fonts (Lato and Oswald, SIL Open Font Licence, Latin subset). Glyphs they do not
 * have (status marks, non-Latin page text) fall back to DejaVu Sans, which build-zip.sh already keeps.
 */
final class BrandPdf
{
    public const CHARCOAL = '#1c2529';
    public const CHARCOAL_LIGHT = '#2a363c';
    public const OLIVE = '#808000';
    public const OLIVE_HOVER = '#6d6d00';
    public const OLIVE_BRIGHT = '#9a9a00';
    public const CREAM = '#faf7f2';
    public const CREAM_DARK = '#f0ede6';
    public const TEXT = '#333333';
    public const TEXT_SECONDARY = '#777777';
    public const TEXT_MUTED = '#999999';

    /** Status badge: [label, text colour, background]. Text colours pass WCAG AA on their background. */
    public const STATUS = [
        'pass' => ['Pass', '#4f5200', '#ecedd9'],
        'warn' => ['Improve', '#7a4a00', '#f6ead6'],
        'fail' => ['Fix', '#9b1c1c', '#f7e1df'],
        'not_measured' => ['Not measured', '#5f6661', '#ecebe7'],
    ];

    /** Options to merge into `new \Mpdf\Mpdf([...])`. */
    public static function config(string $assetDir, string $tempDir): array
    {
        $defaults = (new \Mpdf\Config\ConfigVariables())->getDefaults();
        $fonts = (new \Mpdf\Config\FontVariables())->getDefaults();

        return [
            'tempDir' => $tempDir,
            'format' => 'A4',
            'margin_left' => 16,
            'margin_right' => 16,
            'margin_top' => 22,
            'margin_bottom' => 18,
            'margin_header' => 8,
            'margin_footer' => 8,
            'fontDir' => array_merge($defaults['fontDir'], [rtrim($assetDir, '/') . '/fonts']),
            'fontdata' => $fonts['fontdata'] + [
                'lato' => ['R' => 'Lato-Regular.ttf', 'B' => 'Lato-Bold.ttf', 'I' => 'Lato-Italic.ttf', 'BI' => 'Lato-Bold.ttf'],
                'latoblack' => ['R' => 'Lato-Black.ttf'],
                'oswald' => ['R' => 'Oswald-Medium.ttf', 'B' => 'Oswald-SemiBold.ttf'],
                'oswaldbold' => ['R' => 'Oswald-Bold.ttf'],
            ],
            'default_font' => 'lato',
            'useSubstitutions' => true,
            'backupSubsFont' => ['dejavusans'],
            'backupSIPFont' => 'dejavusans',
        ];
    }

    public static function css(): string
    {
        $c = static fn(string $k): string => constant(self::class . '::' . $k);
        return '
body { font-family: lato; font-size: 9.5pt; line-height: 1.5; color: ' . $c('TEXT') . '; }
h1, h2, h3, .display { font-family: oswald; font-weight: bold; color: ' . $c('CHARCOAL') . '; }
h2 { font-size: 15pt; margin: 18pt 0 2pt; }
h3 { font-size: 11pt; margin: 0; }
.eyebrow { font-family: lato; font-weight: bold; font-size: 7pt; letter-spacing: 2pt; text-transform: uppercase; color: ' . $c('OLIVE') . '; }
.eyebrow-light { font-family: lato; font-weight: bold; font-size: 7pt; letter-spacing: 2pt; text-transform: uppercase; color: ' . $c('OLIVE_BRIGHT') . '; }
.lede { color: ' . $c('TEXT_SECONDARY') . '; font-size: 9pt; }
.rule { border-bottom: 1.5pt solid ' . $c('OLIVE') . '; height: 4pt; margin-bottom: 8pt; }
.muted { color: ' . $c('TEXT_SECONDARY') . '; }
.small { font-size: 8pt; }

/* Cover band: charcoal, white type, olive accents (DESIGN.md hero pattern) */
.cover td { background-color: ' . $c('CHARCOAL') . '; color: #ffffff; }
.cover h1 { color: #ffffff; font-size: 26pt; line-height: 1.1; margin: 4pt 0 4pt; }
.cover .meta td { background-color: ' . $c('CHARCOAL') . '; color: #c9cdc9; font-size: 8.5pt; padding: 2pt 0; }
.cover .meta .k { color: #8f9893; font-size: 7pt; letter-spacing: 1.5pt; text-transform: uppercase; font-weight: bold; width: 32mm; }

/* Score hero */
.score { font-family: oswaldbold; font-size: 40pt; line-height: 1; color: ' . $c('CHARCOAL') . '; }
.score-sm { font-family: oswaldbold; font-size: 26pt; line-height: 1; color: ' . $c('TEXT_SECONDARY') . '; }
.outof { font-family: lato; font-size: 9pt; color: ' . $c('TEXT_SECONDARY') . '; }
.grade { font-family: oswald; font-weight: bold; font-size: 9pt; color: #ffffff; background-color: ' . $c('CHARCOAL') . '; padding: 1pt 6pt; }
.delta-up { font-family: oswald; font-weight: bold; font-size: 18pt; color: ' . $c('OLIVE_HOVER') . '; }
.delta-down { font-family: oswald; font-weight: bold; font-size: 18pt; color: #9b1c1c; }

/* Cream cards */
.card td { background-color: ' . $c('CREAM') . '; }
.tile td { background-color: ' . $c('CREAM') . '; border-top: 2pt solid ' . $c('OLIVE') . '; }
.tile .n { font-family: oswaldbold; font-size: 22pt; line-height: 1.05; color: ' . $c('CHARCOAL') . '; }

/* Bars: olive fill on cream-dark track */
.bar { background-color: ' . $c('CREAM_DARK') . '; height: 4pt; }
.bar-fill { background-color: ' . $c('OLIVE') . '; height: 4pt; }
.bar-them { background-color: #b9bcb7; height: 3pt; }

/* Tables */
table.list { width: 100%; border-collapse: collapse; }
table.list th { font-family: lato; font-weight: bold; font-size: 7pt; letter-spacing: 1.5pt; text-transform: uppercase; color: ' . $c('TEXT_SECONDARY') . '; text-align: left; padding: 5pt 6pt; border-bottom: 1pt solid ' . $c('CHARCOAL') . '; }
table.list td { padding: 6pt; vertical-align: top; border-bottom: 0.5pt solid #e2dfd8; }
.check { font-weight: bold; color: ' . $c('CHARCOAL') . '; }
.why { color: ' . $c('TEXT_SECONDARY') . '; font-size: 8pt; }
.todo td { background-color: ' . $c('CREAM') . '; border-left: 2pt solid ' . $c('OLIVE') . '; padding: 5pt 8pt; }
.todo .label { font-weight: bold; color: ' . $c('CHARCOAL') . '; }

/* Badges: tinted background, dark text. A pill shape is allowed for badges only. */
.badge { font-family: lato; font-weight: bold; font-size: 7.5pt; padding: 1pt 5pt; }

/* Closing call to action: charcoal, never a flat olive block with text on it */
.cta td { background-color: ' . $c('CHARCOAL') . '; color: #c9cdc9; }
.cta h2 { color: #ffffff; margin: 2pt 0 4pt; font-size: 17pt; }
.cta .button { background-color: ' . $c('OLIVE') . '; color: #ffffff; font-family: lato; font-weight: bold; font-size: 9pt; padding: 6pt 12pt; }

/* Running header and footer */
.run { font-size: 7.5pt; color: ' . $c('TEXT_SECONDARY') . '; }
';
    }

    public static function badge(string $status): string
    {
        [$label, $fg, $bg] = self::STATUS[$status] ?? self::STATUS['not_measured'];
        return '<span class="badge" style="color:' . $fg . ';background-color:' . $bg . '">' . $label . '</span>';
    }

    /**
     * A score bar as a table row. mPDF collapses empty cells and ignores div widths, so each cell holds a
     * 1pt non-breaking space and the colour is set inline.
     */
    public static function bar(?int $score, string $fill = self::OLIVE, float $heightPt = 4.0): string
    {
        $w = max(0, min(100, (int) $score));
        $cell = static fn(string $bg, ?int $width): string => '<td style="background-color:' . $bg . ';height:' . $heightPt . 'pt;font-size:1pt;line-height:1pt"'
            . ($width === null ? '' : ' width="' . $width . '%"') . '>&nbsp;</td>';
        $row = $w === 0 ? $cell(self::CREAM_DARK, null)
            : ($w === 100 ? $cell($fill, null) : $cell($fill, $w) . $cell(self::CREAM_DARK, null));
        return '<table width="100%" cellspacing="0" cellpadding="0"><tr>' . $row . '</tr></table>';
    }

    /** Running header for pages 2+, and the footer for every page. */
    public static function headerFooter(string $logoDark, string $subtitle, string $rightText, string $footerLeft): string
    {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        return '<htmlpageheader name="run"><table width="100%" class="run" style="border-bottom:0.5pt solid #d9d6cf"><tr>'
            . '<td width="50%" style="padding-bottom:4pt"><img src="' . $e($logoDark) . '" height="4.2mm" /> &nbsp; <span style="color:#999">Halo Audit · ' . $e($subtitle) . '</span></td>'
            . '<td width="50%" align="right" style="padding-bottom:4pt">' . $e($rightText) . '</td></tr></table></htmlpageheader>'
            . '<htmlpagefooter name="foot"><table width="100%" class="run"><tr>'
            . '<td width="70%">' . $e($footerLeft) . '</td>'
            . '<td width="30%" align="right">Page {PAGENO} of {nbpg}</td></tr></table></htmlpagefooter>'
            . '<sethtmlpageheader name="run" value="on" show-this-page="0" />'
            . '<sethtmlpagefooter name="foot" value="on" />';
    }

    /** Last block of the report. Charcoal, white heading, one olive button. Inline styles: mPDF ignores `.cta h2`. */
    public static function cta(string $contactUrl): string
    {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $bg = 'background-color:' . self::CHARCOAL . ';';
        return '<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:18pt;page-break-inside:avoid"><tr><td style="' . $bg . 'padding:16pt 18pt">'
            . '<div style="font-weight:bold;font-size:7pt;letter-spacing:2pt;color:' . self::OLIVE_BRIGHT . '">HALO · THRIVEMATTIC</div><div style="font-size:4pt;line-height:1">&nbsp;</div>'
            . '<div style="font-family:oswald;font-weight:bold;font-size:17pt;line-height:1.2;color:#ffffff">Fix this page, then the rest of the site</div>'
            . '<div style="font-size:3pt;line-height:1">&nbsp;</div><div style="color:#c9cdc9">This report checks one page. Halo audits up to 2,000 pages of your site every night, tracks your rankings, and checks what ChatGPT, Perplexity, Gemini, Claude and AI Overviews say about you. In a 30-minute call we walk through these findings and what to fix first.</div>'
            . '<table cellpadding="0" cellspacing="0" style="margin-top:12pt"><tr>'
            . '<td style="background-color:' . self::OLIVE . ';color:#ffffff;font-weight:bold;padding:7pt 14pt">Book a discovery call</td>'
            . '<td style="' . $bg . 'color:#ffffff;padding-left:12pt">' . $e($contactUrl) . '</td></tr></table>'
            . '</td></tr></table>';
    }
}
