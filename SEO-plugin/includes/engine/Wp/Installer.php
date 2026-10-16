<?php
namespace HaloAudit\Wp;

final class Installer
{
    public const DB_VERSION = '2';
    public const CRON_HOOK = 'halo_audit_purge';

    public static function resultsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'seo_analyzer_results';
    }

    public static function otpTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'seo_analyzer_otp';
    }

    public static function maybeUpgrade(): void
    {
        if (get_option('halo_audit_db_version') !== self::DB_VERSION) {
            self::upgrade();
            update_option('halo_audit_db_version', self::DB_VERSION, false);
        }
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    private static function upgrade(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $results = self::resultsTable();
        $otp = self::otpTable();

        dbDelta("CREATE TABLE $results (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            report_key char(32) DEFAULT NULL,
            name varchar(100) DEFAULT NULL,
            email varchar(100) DEFAULT NULL,
            url varchar(255) NOT NULL,
            keyword varchar(255) NOT NULL,
            competitor_url varchar(255) DEFAULT NULL,
            results longtext NOT NULL,
            competitor_results longtext,
            overall_score float DEFAULT NULL,
            competitor_overall_score float DEFAULT NULL,
            grade char(1) DEFAULT NULL,
            engine_version varchar(20) DEFAULT NULL,
            is_education tinyint(1) NOT NULL DEFAULT 0,
            lead_verified_at datetime DEFAULT NULL,
            scan_id varchar(64) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY report_key (report_key),
            KEY created_at (created_at)
        ) $charset;");

        // dbDelta does not relax NOT NULL on existing columns.
        $wpdb->query("ALTER TABLE $results MODIFY name varchar(100) DEFAULT NULL, MODIFY email varchar(100) DEFAULT NULL");

        // Codes live 15 minutes, so the old table holds nothing worth keeping.
        $wpdb->query("DROP TABLE IF EXISTS $otp");
        dbDelta("CREATE TABLE $otp (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            email varchar(190) NOT NULL,
            code_hash varchar(255) NOT NULL,
            expires_at datetime NOT NULL,
            attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY email (email)
        ) $charset;");

        self::migratePsiKey();
    }

    private static function migratePsiKey(): void
    {
        global $wpdb;
        $old = $wpdb->prefix . 'seo_analyzer_api_keys';
        $suppress = $wpdb->suppress_errors(true);
        $key = $wpdb->get_var("SELECT api_key FROM $old ORDER BY id ASC LIMIT 1");
        $wpdb->suppress_errors($suppress);

        if (is_string($key) && $key !== '' && !get_option('halo_audit_psi_key')) {
            update_option('halo_audit_psi_key', $key, false);
        }
        $wpdb->query("DROP TABLE IF EXISTS $old");
    }
}
