<?php
namespace HaloAudit\Wp;

final class Shortcode
{
    private const HANDLE = 'halo-audit';
    private const DEFAULT_NAME = 'Halo Audit';

    public static function register(): void
    {
        add_shortcode('seo_analyzer', [self::class, 'render']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueueForPage']);
    }

    public static function registerAssets(): void
    {
        if (wp_script_is(self::HANDLE, 'registered')) {
            return;
        }
        wp_register_style(self::HANDLE, SEO_ANALYZER_PLUGIN_URL . 'public/css/halo-audit.css', [], self::version('public/css/halo-audit.css'));
        wp_register_script(self::HANDLE, SEO_ANALYZER_PLUGIN_URL . 'public/js/halo-audit.js', [], self::version('public/js/halo-audit.js'), true);
        // Only the REST address. The nonce is fetched at run time, so a cached page never holds a stale one.
        wp_localize_script(self::HANDLE, 'HaloAudit', ['root' => esc_url_raw(rest_url(RestController::NS . '/'))]);
    }

    /** Puts the stylesheet in the head when the page itself holds the shortcode. render() covers every other placement. */
    public static function enqueueForPage(): void
    {
        self::registerAssets();
        $post = is_singular() ? get_post() : null;
        if ($post instanceof \WP_Post && has_shortcode((string) $post->post_content, 'seo_analyzer')) {
            wp_enqueue_style(self::HANDLE);
        }
    }

    public static function render($atts): string
    {
        // popup and nlp are accepted so that existing pages do not break. They do nothing.
        // comparison="true" adds the competitor page field, as in 1.x; competitor="false" turns that off.
        $atts = shortcode_atts(
            ['title' => self::DEFAULT_NAME, 'heading_level' => '2', 'align' => '', 'popup' => '', 'comparison' => '', 'nlp' => '', 'competitor' => '',
                'cta_url' => '', 'cta_title' => '', 'cta_text' => '', 'cta_button' => ''],
            $atts,
            'seo_analyzer'
        );
        $title = trim((string) $atts['title']);
        $level = (int) $atts['heading_level'];
        if ($level < 2 || $level > 5) {
            $level = 2;
        }
        // align="wide" or "full" adds the standard WordPress class, so a block theme gives the tool its wide or full width.
        $align = strtolower(trim((string) $atts['align']));
        $class = 'halo-audit' . (in_array($align, ['wide', 'full'], true) ? ' align' . $align : '');
        $id = wp_unique_id('halo-audit-');
        $compare = self::compareMode($atts);
        // The page this form is on, signed (FORM_NAME in Brevo). Each placement gets the page it is shown on.
        $pageUrl = self::currentPageUrl();
        // An optional closing block under the findings: the site's own offer (cta_url, cta_title, cta_text, cta_button).
        // Without cta_url there is no block. The plugin itself names no site.
        $cta = '';
        $ctaUrl = esc_url((string) $atts['cta_url'], ['http', 'https']);
        if ($ctaUrl !== '') {
            $cta = ' data-cta-url="' . esc_attr($ctaUrl) . '"';
            foreach (['cta_title' => 'data-cta-title', 'cta_text' => 'data-cta-text', 'cta_button' => 'data-cta-button'] as $key => $attr) {
                $value = trim((string) $atts[$key]);
                $cta .= $value !== '' ? ' ' . $attr . '="' . esc_attr($value) . '"' : '';
            }
        }

        self::registerAssets();
        wp_enqueue_style(self::HANDLE);
        wp_enqueue_script(self::HANDLE);

        // With title="" the page supplies the heading, and the region is named by aria-label.
        $name = $title !== ''
            ? 'aria-labelledby="' . esc_attr($id) . '-title"'
            : 'aria-label="' . esc_attr(self::DEFAULT_NAME) . '"';

        ob_start();
        ?>
        <section class="<?php echo esc_attr($class); ?>" id="<?php echo esc_attr($id); ?>" data-state="idle" data-heading-level="<?php echo esc_attr((string) $level); ?>"<?php echo $pageUrl !== '' ? ' data-form-page="' . esc_attr(self::pageToken($pageUrl)) . '"' : ''; ?><?php echo $compare ? ' data-compare="1"' : ''; ?><?php echo $cta; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above ?> <?php echo $name; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts ?>>
            <?php if ($title !== '') : ?>
            <h<?php echo (int) $level; ?> class="halo-audit__title" id="<?php echo esc_attr($id); ?>-title"><?php echo esc_html($title); ?></h<?php echo (int) $level; ?>>
            <?php endif; ?>
            <form class="halo-audit__form<?php echo $compare ? ' halo-audit__form--compare' : ''; ?>" novalidate>
                <label class="halo-audit__field">
                    <span class="halo-audit__label"><?php echo $compare ? 'Your page address' : 'Page address'; ?></span>
                    <input type="text" name="url" inputmode="url" autocomplete="url" autocapitalize="none" spellcheck="false" placeholder="https://example.com/page" required>
                </label>
<?php if ($compare) : ?>
                <label class="halo-audit__field">
                    <span class="halo-audit__label">Competitor page address</span>
                    <input type="text" name="competitor_url" inputmode="url" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="https://competitor.example/page" required>
                </label>
<?php endif; ?>
                <label class="halo-audit__field">
                    <span class="halo-audit__label">Primary keyword</span>
                    <input type="text" name="keyword" placeholder="The keyword this page should rank for" maxlength="255" required>
                </label>
                <button type="submit" class="halo-audit__button">Analyse</button>
            </form>
            <p class="halo-audit__status" id="<?php echo esc_attr($id); ?>-status" role="status" aria-live="polite"></p>
            <div class="halo-audit__results" hidden></div>
            <noscript><p>This tool needs JavaScript.</p></noscript>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Comparison mode follows 1.x: comparison="true" turns it on. competitor="true" alone does nothing,
     * as in 1.x, and competitor="false" (or 0, no, off) keeps the competitor field off.
     */
    public static function compareMode(array $atts): bool
    {
        if (filter_var($atts['comparison'] ?? '', FILTER_VALIDATE_BOOLEAN) !== true) {
            return false;
        }
        $competitor = trim((string) ($atts['competitor'] ?? ''));
        return $competitor === '' || filter_var($competitor, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
    }

    /**
     * The canonical address of the page being shown, when it is a single post or page of this site; '' anywhere
     * else (archives, feeds, REST, a page that is not published). This is FORM_NAME in Brevo: the page on which
     * the visitor filled in the form, not the address being audited.
     */
    public static function currentPageUrl(): string
    {
        if (!is_singular()) {
            return '';
        }
        $post = get_queried_object();
        $url = $post instanceof \WP_Post ? wp_get_canonical_url($post) : false;
        return is_string($url) ? self::sitePageUrl($url) : '';
    }

    /**
     * The page address, signed with a site secret. The browser sends it back with the audit; the server keeps
     * the address only when the signature matches, so a visitor cannot put another address in FORM_NAME.
     */
    public static function pageToken(string $url): string
    {
        $payload = rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
        return $payload . '.' . self::sign($payload);
    }

    /** The page address in a token made by pageToken(), or '' for a missing, altered or foreign token. */
    public static function pageUrl(string $token): string
    {
        if (!preg_match('/^([A-Za-z0-9_-]{1,1400})\.([a-f0-9]{32})$/', $token, $m) || !hash_equals(self::sign($m[1]), $m[2])) {
            return '';
        }
        $url = base64_decode(strtr($m[1], '-_', '+/') . str_repeat('=', (4 - strlen($m[1]) % 4) % 4), true);
        return is_string($url) ? self::sitePageUrl($url) : '';
    }

    private static function sign(string $payload): string
    {
        return substr(hash_hmac('sha256', $payload, wp_salt('auth') . '|halo_audit_form_page'), 0, 32);
    }

    /** An http(s) address on this site's own host, at most 1000 characters; anything else is ''. */
    private static function sitePageUrl(string $url): string
    {
        $url = esc_url_raw(trim($url), ['http', 'https']);
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if ($url === '' || strlen($url) > 1000 || $host === '' || $host !== strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST))) {
            return '';
        }
        return $url;
    }

    private static function version(string $file): string
    {
        $time = @filemtime(SEO_ANALYZER_PLUGIN_DIR . $file);
        return $time ? (string) $time : SEO_ANALYZER_VERSION;
    }
}
