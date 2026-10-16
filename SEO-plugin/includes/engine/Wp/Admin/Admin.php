<?php
namespace HaloAudit\Wp\Admin;

use HaloAudit\Wp\BrevoSync;
use HaloAudit\Wp\Gate;
use HaloAudit\Wp\Installer;

final class Admin
{
    private const CAPABILITY = 'manage_options';
    private const EXPORT_ACTION = 'halo_audit_export';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [Settings::class, 'register']);
        add_action('admin_post_' . self::EXPORT_ACTION, [self::class, 'export']);
        add_action('admin_post_' . Settings::CLEAR_ACTION, [Settings::class, 'clearSecret']);
        add_action('admin_notices', [self::class, 'printMailNotice']);
    }

    /** Shown on this plugin's screens after a verification email could not be sent. A later successful send clears it. */
    public static function mailNotice(): string
    {
        $failedAt = (int) get_option(Gate::MAIL_FAILURE_OPTION, 0);
        if ($failedAt <= 0) {
            return '';
        }
        return '<div class="notice notice-error"><p><strong>Halo Audit could not send a verification email</strong> on '
            . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $failedAt))
            . '. Visitors cannot unlock their report until email works. Check the Brevo key and sender on the '
            . '<a href="' . esc_url(admin_url('admin.php?page=' . Settings::PAGE)) . '">settings page</a>, or the mail setup of this site.</p></div>';
    }

    /** Shown on this plugin's screens after a confirmed visitor could not be added to the Brevo list. A later success clears it. */
    public static function brevoNotice(): string
    {
        $failed = get_option(BrevoSync::FAILURE_OPTION, null);
        if (!is_array($failed) || !BrevoSync::enabled()) {
            return '';
        }
        $reason = BrevoSync::REASONS[$failed['code'] ?? ''] ?? 'unexpected answer from Brevo';
        return '<div class="notice notice-warning"><p><strong>A confirmed visitor could not be added to the Brevo list</strong> on '
            . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) ($failed['at'] ?? 0)))
            . ': ' . esc_html($reason) . (empty($failed['http']) ? '' : ' (HTTP ' . (int) $failed['http'] . ')')
            . '. The lead is saved here; see the Brevo column on the Reports page. Check the Brevo key, the list ID and Brevo\'s Authorised IPs on the '
            . '<a href="' . esc_url(admin_url('admin.php?page=' . Settings::PAGE)) . '">settings page</a>.</p></div>';
    }

    /** Shown on this plugin's screens when the last sync could not save every contact attribute. Names only. */
    public static function brevoAttributeNotice(): string
    {
        $last = get_option(BrevoSync::ATTRIBUTE_OPTION, null);
        if (!is_array($last) || !BrevoSync::enabled()) {
            return '';
        }
        $parts = [];
        $missing = array_values(array_filter((array) ($last['missing'] ?? []), static fn($n) => in_array($n, BrevoSync::ATTRIBUTES, true)));
        if ($missing) {
            $parts[] = 'Brevo did not save ' . implode(', ', $missing) . '. Check that these contact attributes exist in Brevo (Contacts, Settings, Contact attributes) with the type Text and exactly these names';
        }
        if (in_array($last['page'] ?? '', ['missing', 'invalid'], true)) {
            $parts[] = $last['page'] === 'missing'
                ? 'FORM_NAME was not sent because the form page was not known: clear the page cache and minified files for the pages with the form'
                : 'FORM_NAME was not sent because the form page value failed its check';
        }
        if (!$parts) {
            return '';
        }
        return '<div class="notice notice-warning"><p><strong>A confirmed visitor was added to the Brevo list, but not every contact detail was saved</strong> ('
            . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) ($last['at'] ?? 0))) . '). '
            . esc_html(implode('. ', $parts)) . '. See the Brevo column on the Reports page.</p></div>';
    }

    public static function printMailNotice(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && strpos((string) $screen->id, 'seo-analyzer') !== false && current_user_can(self::CAPABILITY)) {
            echo self::mailNotice(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in mailNotice()
            echo self::brevoNotice(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in brevoNotice()
            echo self::brevoAttributeNotice(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in brevoAttributeNotice()
        }
    }

    public static function menu(): void
    {
        add_menu_page('SEO Analyzer', 'SEO Analyzer', self::CAPABILITY, 'seo-analyzer', [self::class, 'homePage'], 'dashicons-chart-area', 6);
        add_submenu_page('seo-analyzer', 'SEO Analyzer Settings', 'Settings', self::CAPABILITY, Settings::PAGE, [Settings::class, 'page']);
        add_submenu_page('seo-analyzer', 'SEO Analysis Reports', 'Reports', self::CAPABILITY, 'seo-analyzer-reports', [self::class, 'reportsPage']);
    }

    public static function homePage(): void
    {
        echo '<div class="wrap"><h1>SEO Analyzer</h1><p>Add the tool to any page with this shortcode:</p>'
            . '<p><code>[seo_analyzer]</code></p>'
            . '<p>Options: <code>title="Free SEO audit"</code> changes the heading. <code>title=""</code> leaves the heading out when the page already has one. '
            . '<code>heading_level="3"</code> sets the heading level (2 to 5). '
            . '<code>align="wide"</code> or <code>align="full"</code> asks a block theme for its wide or full width.</p>'
            . '<p>The tool needs no theme changes. It fills the width of the place you put it in.</p></div>';
    }

    public static function reportsPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to view this page.', 'seo-analyzer'), '', ['response' => 403]);
        }
        $table = new ReportsTable();
        $table->prepare_items();
        $export = wp_nonce_url(admin_url('admin-post.php?action=' . self::EXPORT_ACTION), self::EXPORT_ACTION);

        echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html(get_admin_page_title()) . '</h1> ';
        echo '<a class="page-title-action" href="' . esc_url($export) . '">Export leads as CSV</a><hr class="wp-header-end">';
        $table->display();
        echo '</div>';
    }

    /** @return array<int,array<string,mixed>> verified leads only */
    public static function exportRows(): array
    {
        global $wpdb;
        return $wpdb->get_results(
            'SELECT name, email, url, keyword, overall_score, grade, is_education, created_at, competitor_url, competitor_overall_score FROM ' . Installer::resultsTable()
            . " WHERE email IS NOT NULL AND email <> '' ORDER BY id DESC",
            ARRAY_A
        );
    }

    /** Stops a spreadsheet from running a cell as a formula. */
    public static function csvCell(string $value): string
    {
        return $value !== '' && strpbrk($value[0], "=+-@\t\r") !== false ? "'" . $value : $value;
    }

    public static function export(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to do this.', 'seo-analyzer'), '', ['response' => 403]);
        }
        check_admin_referer(self::EXPORT_ACTION);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="halo-audit-leads-' . gmdate('Y-m-d') . '.csv"');

        $out = fopen('php://output', 'w');
        // The competitor columns come last so the earlier columns keep their places for existing spreadsheets.
        fputcsv($out, ['Name', 'Email', 'Page', 'Keyword', 'Score', 'Grade', 'Education', 'Date', 'Competitor page', 'Competitor score']);
        foreach (self::exportRows() as $row) {
            fputcsv($out, array_map(static fn($value): string => self::csvCell((string) $value), array_values($row)));
        }
        fclose($out);
        exit;
    }
}
