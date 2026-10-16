<?php
namespace HaloAudit\Wp;

use HaloAudit\Analyzer;
use HaloAudit\Fetcher;
use HaloAudit\HttpClient;
use HaloAudit\NullScanClient;
use HaloAudit\UrlGuard;

final class Plugin
{
    private static ?ReportStore $store = null;
    private static ?Gate $gate = null;

    public static function boot(): void
    {
        Installer::maybeUpgrade();

        add_action(Installer::CRON_HOOK, static function (): void {
            self::store()->purgeUnverified();
        });
        register_deactivation_hook(SEO_ANALYZER_PLUGIN_FILE, static function (): void {
            wp_clear_scheduled_hook(Installer::CRON_HOOK);
        });

        add_action('rest_api_init', static function (): void {
            $scan = apply_filters('halo_audit_scan_client', new NullScanClient());
            (new RestController(self::store(), self::gate(), $scan))->register();
        });

        Shortcode::register();
        BrevoSync::register();

        if (is_admin()) {
            Admin\Admin::register();
        }
    }

    public static function store(): ReportStore
    {
        return self::$store ??= new ReportStore();
    }

    public static function gate(): Gate
    {
        return self::$gate ??= new Gate(self::store());
    }

    public static function http(): HttpClient
    {
        return new WpHttpClient();
    }

    public static function analyzer(): Analyzer
    {
        $guard = new UrlGuard(apply_filters('halo_audit_dns_resolver', null));
        $cert = apply_filters('halo_audit_cert_probe', new StreamCertProbe());
        return new Analyzer(new Fetcher(self::http(), $guard), $cert);
    }
}
