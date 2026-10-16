<?php
/**
 * Plugin Name: SEO Analyzer
 * Plugin URI: https://thrivemattic.com/ai-tools/seo-analysis/
 * Description: Halo Audit. Scores one page for on-page SEO, technical health, AI readiness and speed.
 * Version: 2.0.1
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Author: thrivemattic
 * Author URI: https://thrivemattic.com/
 * Text Domain: seo-analyzer
 */

if (!defined('WPINC')) {
    die;
}

// A second copy of the plugin in another folder must not load on top of the first.
if (defined('SEO_ANALYZER_VERSION')) {
    return;
}

define('SEO_ANALYZER_VERSION', '2.0.1');
define('SEO_ANALYZER_PLUGIN_FILE', __FILE__);
define('SEO_ANALYZER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SEO_ANALYZER_PLUGIN_URL', plugin_dir_url(__FILE__));

if (!file_exists(SEO_ANALYZER_PLUGIN_DIR . 'vendor/autoload.php')) {
    add_action('admin_notices', static function () {
        echo '<div class="notice notice-error"><p>SEO Analyzer: run <code>composer install --no-dev</code> in the plugin folder.</p></div>';
    });
    return;
}

$seo_analyzer_missing = array_values(array_filter(['curl', 'dom', 'mbstring', 'openssl', 'gd'], static fn(string $ext): bool => !extension_loaded($ext)));
if ($seo_analyzer_missing !== []) {
    add_action('admin_notices', static function () use ($seo_analyzer_missing) {
        echo '<div class="notice notice-error"><p>SEO Analyzer is switched off. This server lacks the PHP extension(s): <code>'
            . esc_html(implode(', ', $seo_analyzer_missing)) . '</code>. Ask your host to turn them on.</p></div>';
    });
    return;
}
unset($seo_analyzer_missing);

require_once SEO_ANALYZER_PLUGIN_DIR . 'vendor/autoload.php';
require_once SEO_ANALYZER_PLUGIN_DIR . 'includes/class-seo-analyzer-mailer.php';

\HaloAudit\Wp\Plugin::boot();
