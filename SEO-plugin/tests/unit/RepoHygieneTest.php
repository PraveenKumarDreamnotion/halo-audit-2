<?php
namespace HaloAudit\Tests;

use PHPUnit\Framework\TestCase;

final class RepoHygieneTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    /** @dataProvider removed */
    public function test_dead_paths_are_gone(string $path): void
    {
        $this->assertFileDoesNotExist($this->root . '/' . $path);
    }

    public function removed(): array
    {
        return [
            ['public/class-seo-analyzer-public.php'], ['public/js/seo-analyzer-public.js'], ['public/css/seo-analyzer-public.css'],
            ['includes/tcpdf'], ['includes/class-seo-analyzer.php'], ['includes/class-seo-analyzer-loader.php'],
            ['includes/class-seo-analyzer-api.php'], ['includes/class-seo-analyzer-records.php'],
            ['includes/class-seo-analyzer-activator.php'], ['includes/class-seo-analyzer-deactivator.php'],
            ['admin'], ['../seo-analyzer-add-on-openai'], ['../seo-analyzer-nlp-addon'], ['../push-log.txt'],
        ];
    }

    public function test_no_sensitive_logging_or_unsafe_fetch_remains(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root . '/includes', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = file_get_contents($file->getPathname());
            foreach (['error_log(', 'print_r($_POST', 'wp_remote_get(', "'sslverify' => false", 'set_time_limit(300'] as $needle) {
                if (strpos($code, $needle) !== false) {
                    $offenders[] = basename($file->getPathname()) . ': ' . $needle;
                }
            }
        }
        $this->assertSame([], $offenders);
    }

    public function test_engine_has_no_wordpress_calls_outside_the_wp_folder(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root . '/includes/engine', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = $file->getPathname();
            if ($file->getExtension() !== 'php' || strpos($path, '/engine/Wp/') !== false) {
                continue;
            }
            if (preg_match('/\b(wp_[a-z_]+|get_option|add_action|add_filter|apply_filters|esc_[a-z_]+|sanitize_[a-z_]+)\s*\(/', file_get_contents($path), $m)) {
                $offenders[] = basename($path) . ': ' . $m[1];
            }
        }
        $this->assertSame([], $offenders);
    }

    public function test_versions_agree(): void
    {
        $main = file_get_contents($this->root . '/seo-analyzer.php');
        $readme = file_get_contents($this->root . '/README.txt');
        $this->assertStringContainsString('Version: 2.0.1', $main);
        $this->assertStringContainsString("define('SEO_ANALYZER_VERSION', '2.0.1')", $main);
        $this->assertStringContainsString('Stable tag: 2.0.1', $readme);
        $this->assertStringContainsString('Requires PHP: 8.0', $readme);
        $this->assertStringContainsString('Requires at least: 6.2', $readme);
        $this->assertStringContainsString('## 2.0.1', file_get_contents($this->root . '/CHANGELOG.md'));
    }

    public function test_uninstall_removes_every_table_and_option(): void
    {
        $code = file_get_contents($this->root . '/uninstall.php');
        // The data is kept when another active copy of the plugin still uses it.
        $this->assertStringContainsString('plugin_basename(SEO_ANALYZER_PLUGIN_FILE) !== WP_UNINSTALL_PLUGIN', $code);
        $this->assertLessThan(strpos($code, 'DROP TABLE'), strpos($code, 'WP_UNINSTALL_PLUGIN) {'));
        foreach (['seo_analyzer_results', 'seo_analyzer_otp', 'seo_analyzer_api_keys'] as $table) {
            $this->assertStringContainsString($table, $code, $table);
        }
        foreach (['halo_audit_db_version', 'halo_audit_psi_key', 'halo_audit_scan_url', 'halo_audit_scan_secret', 'seo_analyzer_brevo_api_key', 'seo_analyzer_sender_email', 'seo_analyzer_openai_api_key', 'seo_analyzer_redirect_url', 'seo_analyzer_pagespeed_api_key'] as $option) {
            $this->assertStringContainsString($option, $code, $option);
        }
        $this->assertStringContainsString('halo_audit_purge', $code);
    }
}
