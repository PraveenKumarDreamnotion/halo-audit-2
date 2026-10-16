<?php
class Halo_Shortcode_Test extends WP_UnitTestCase
{
    public function test_shortcode_renders_the_form_and_enqueues_assets(): void
    {
        $html = do_shortcode('[seo_analyzer title="Halo <Audit>" comparison="true" popup="true" nlp="true" competitor="true"]');

        $this->assertStringContainsString('class="halo-audit"', $html);
        $this->assertStringContainsString('Halo &lt;Audit&gt;', $html);
        $this->assertStringContainsString('name="url"', $html);
        $this->assertStringContainsString('name="keyword"', $html);
        // comparison="true" brings back the 1.x competitor field (see CompareTest for every attribute mix).
        $this->assertStringContainsString('name="competitor_url"', $html);
        $this->assertStringNotContainsString('competitor_url', do_shortcode('[seo_analyzer title="Halo Audit" popup="true" nlp="true"]'));
        $this->assertTrue(wp_script_is('halo-audit', 'enqueued'));
        $this->assertTrue(wp_style_is('halo-audit', 'enqueued'));
        // Older WordPress escapes the slashes in localised data; newer versions do not.
        $data = str_replace('\/', '/', (string) wp_scripts()->get_data('halo-audit', 'data'));
        $this->assertStringContainsString('halo-audit/v1/', $data);
    }

    public function test_widget_is_a_labelled_region_with_labelled_fields(): void
    {
        $html = do_shortcode('[seo_analyzer]');

        $this->assertMatchesRegularExpression('/<section class="halo-audit" id="(halo-audit-\d+)" data-state="idle" data-heading-level="2" aria-labelledby="\1-title">/', $html);
        $this->assertMatchesRegularExpression('/<h2 class="halo-audit__title" id="halo-audit-\d+-title">Halo Audit<\/h2>/', $html);
        $this->assertSame(2, substr_count($html, '<label class="halo-audit__field">'));
        $this->assertMatchesRegularExpression('/class="halo-audit__status" id="halo-audit-\d+-status" role="status" aria-live="polite"/', $html);
        $this->assertStringContainsString('class="halo-audit__results" hidden', $html);
    }

    public function test_empty_title_renders_no_heading_and_keeps_an_accessible_name(): void
    {
        $html = do_shortcode('[seo_analyzer title=""]');

        $this->assertStringNotContainsString('halo-audit__title', $html);
        $this->assertStringNotContainsString('aria-labelledby', $html);
        $this->assertStringContainsString('aria-label="Halo Audit"', $html);
    }

    public function test_heading_level_is_limited_to_two_through_five(): void
    {
        $this->assertStringContainsString('<h3 class="halo-audit__title"', do_shortcode('[seo_analyzer heading_level="3"]'));
        $this->assertStringContainsString('data-heading-level="3"', do_shortcode('[seo_analyzer heading_level="3"]'));
        $this->assertStringContainsString('<h2 class="halo-audit__title"', do_shortcode('[seo_analyzer heading_level="1"]'));
        $this->assertStringContainsString('<h2 class="halo-audit__title"', do_shortcode('[seo_analyzer heading_level="script"]'));
    }

    public function test_align_adds_the_standard_wide_or_full_class_and_nothing_else(): void
    {
        $this->assertStringContainsString('<section class="halo-audit alignwide" ', do_shortcode('[seo_analyzer align="wide"]'));
        $this->assertStringContainsString('<section class="halo-audit alignfull" ', do_shortcode('[seo_analyzer align="FULL"]'));
        $this->assertStringContainsString('<section class="halo-audit" ', do_shortcode('[seo_analyzer align="left\" onclick=\"x"]'));
    }

    public function test_two_widgets_on_one_page_do_not_share_ids(): void
    {
        $html = do_shortcode('[seo_analyzer][seo_analyzer]');

        preg_match_all('/\sid="([^"]+)"/', $html, $ids);
        $this->assertNotEmpty($ids[1]);
        $this->assertSame(count($ids[1]), count(array_unique($ids[1])));
        $this->assertSame(2, substr_count($html, 'class="halo-audit"'));
        $this->assertSame(1, substr_count((string) wp_scripts()->get_data('halo-audit', 'data'), 'var HaloAudit'));
    }

    public function test_style_is_enqueued_in_the_head_on_a_page_that_holds_the_shortcode(): void
    {
        wp_dequeue_style('halo-audit');
        wp_dequeue_script('halo-audit');
        $with = self::factory()->post->create(['post_type' => 'page', 'post_content' => 'Intro [seo_analyzer title="Audit"] outro']);
        $without = self::factory()->post->create(['post_type' => 'page', 'post_content' => 'No tool here']);

        $this->go_to(get_permalink($without));
        do_action('wp_enqueue_scripts');
        $this->assertFalse(wp_style_is('halo-audit', 'enqueued'));
        $this->assertFalse(wp_script_is('halo-audit', 'enqueued'));

        $this->go_to(get_permalink($with));
        do_action('wp_enqueue_scripts');
        $this->assertTrue(wp_style_is('halo-audit', 'enqueued'));
    }

    public function test_assets_use_the_plugin_url_and_a_file_version(): void
    {
        do_shortcode('[seo_analyzer]');
        $script = wp_scripts()->registered['halo-audit'];
        $style = wp_styles()->registered['halo-audit'];

        $this->assertSame(SEO_ANALYZER_PLUGIN_URL . 'public/js/halo-audit.js', $script->src);
        $this->assertSame(SEO_ANALYZER_PLUGIN_URL . 'public/css/halo-audit.css', $style->src);
        $this->assertSame([], $script->deps);
        $this->assertSame((string) filemtime(SEO_ANALYZER_PLUGIN_DIR . 'public/js/halo-audit.js'), $script->ver);
        $this->assertSame((string) filemtime(SEO_ANALYZER_PLUGIN_DIR . 'public/css/halo-audit.css'), $style->ver);
        $data = str_replace('\/', '/', (string) wp_scripts()->get_data('halo-audit', 'data'));
        $this->assertStringContainsString(rest_url('halo-audit/v1/'), $data);
        $this->assertDoesNotMatchRegularExpression('/key|secret|nonce|brevo/i', $data);
    }

    public function test_front_end_files_do_not_name_any_theme_or_site(): void
    {
        foreach (['public/js/halo-audit.js', 'public/css/halo-audit.css', 'includes/engine/Wp/Shortcode.php'] as $file) {
            $source = file_get_contents(SEO_ANALYZER_PLUGIN_DIR . $file);
            foreach (['tmsa', 'tmca', 'page-id-', 'seo-analyzer-loading', 'seo-analyzer-form', 'thrivemattic', 'generatepress', 'jQuery', 'wp-block-'] as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $source, "$file names $needle");
            }
        }
        $css = file_get_contents(SEO_ANALYZER_PLUGIN_DIR . 'public/css/halo-audit.css');
        $this->assertDoesNotMatchRegularExpression('/(^|[},])\s*(body|html|h[1-6]|button|input|form|ul|p|a)\s*[,{]/m', $css, 'a rule is not scoped to the widget');
    }

    public function test_script_has_no_inner_html_assignment(): void
    {
        $js = file_get_contents(SEO_ANALYZER_PLUGIN_DIR . 'public/js/halo-audit.js');
        $this->assertStringNotContainsString('innerHTML', $js);
        $this->assertStringNotContainsString('insertAdjacentHTML', $js);
        $this->assertStringNotContainsString('document.write', $js);
        $this->assertStringNotContainsString('console.log', $js);
    }
}
