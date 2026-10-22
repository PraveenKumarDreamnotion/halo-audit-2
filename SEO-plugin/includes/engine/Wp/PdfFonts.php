<?php
namespace HaloAudit\Wp;

/**
 * The font for Devanagari text (Hindi, Marathi, Nepali, Sanskrit and others) in the PDF. The PDF is set in
 * DejaVu Sans, which has no Devanagari. Pdf::e() tags each Devanagari run with lang="hi", and mPDF (autoLangToFont)
 * asks this class which font to use for that language.
 *
 * The font is FreeSans from GNU FreeFont, bundled with mPDF: every Devanagari letter, sign and digit, and the
 * OpenType tables mPDF uses to shape conjuncts and vowel signs. Its regular face is used for bold text too:
 * mPDF 8.3 misplaces the anusvara in FreeSans Bold ("में" drawn as "मे" with the dot on the next letter), while
 * the regular face shapes correctly, so Hindi in a bold title is set in regular weight.
 *
 * Every other language keeps the current font: Latin, Greek, Cyrillic and the rest stay in DejaVu, and a script no
 * bundled font covers prints as it did before. mPDF's own map would point many scripts at fonts the plugin ZIP
 * leaves out, and a missing font stops the render. Without the font file (an older trimmed copy of the plugin),
 * Devanagari also keeps the current font instead of failing.
 */
final class PdfFonts implements \Mpdf\Language\LanguageToFontInterface
{
    public const FAMILY = 'halodeva';
    /** The one file the ZIP keeps for it, in mPDF's ttfonts folder. */
    public const FILE = 'FreeSans.ttf';

    private bool $available;

    public function __construct(string $fontDir)
    {
        $this->available = is_readable(rtrim($fontDir, '/\\') . '/' . self::FILE);
    }

    /** The mPDF fontdata entry: the regular face for both weights, shaped with all OpenType features. */
    public static function fontdata(): array
    {
        return [self::FAMILY => ['R' => self::FILE, 'B' => self::FILE, 'useOTL' => 0xFF]];
    }

    /** @return array{0:bool,1:string} [core fonts suitable, font name]; an empty name keeps the current font */
    public function getLanguageOptions($llcc, $adobeCJK)
    {
        return [false, strtolower((string) $llcc) === 'hi' && $this->available ? self::FAMILY : ''];
    }

    /**
     * Wraps each run of Devanagari in already escaped text in <span lang="hi">. A run includes the danda (। ॥),
     * which Unicode counts as common punctuation: left to mPDF it joins the next Latin run and prints as a box.
     */
    public static function tag(string $escaped): string
    {
        if (!preg_match('/[\x{0900}-\x{097F}]/u', $escaped)) {
            return $escaped;
        }
        $char = '[\x{0900}-\x{097F}\x{A8E0}-\x{A8FF}]';
        return (string) preg_replace('/(' . $char . '(?:[' . '\x{0900}-\x{097F}\x{A8E0}-\x{A8FF}\x{200C}\x{200D} ]*' . $char . ')?)/u', '<span lang="hi">$1</span>', $escaped);
    }
}
