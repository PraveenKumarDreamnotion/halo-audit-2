<?php
use HaloAudit\CertProbe;
use HaloAudit\Wp\Admin\Admin;
use HaloAudit\Wp\Admin\ReportsTable;
use HaloAudit\Wp\Gate;
use HaloAudit\Wp\Installer;
use HaloAudit\Wp\ReportStore;
use HaloAudit\Wp\RestController;

/**
 * Table install and repair (Installer), on tables of their own: every test runs under a table prefix of its own, so
 * the test site's real tables are never touched, and the tables it makes are temporary (the WordPress test case
 * turns CREATE TABLE into CREATE TEMPORARY TABLE). ALTER TABLE still commits, so tear_down puts back and commits
 * the options it changed. Nothing reaches the internet: pages and PageSpeed are faked.
 */
class Halo_Installer_Test extends WP_UnitTestCase
{
    /** The live table on the day this was written: the 1.x results table of create_results_table(). */
    private const LEGACY_RESULTS = 'id mediumint(9) NOT NULL AUTO_INCREMENT, name varchar(100) NOT NULL, email varchar(100) NOT NULL,'
        . ' url varchar(255) NOT NULL, keyword varchar(255) NOT NULL, competitor_url varchar(255), results longtext NOT NULL,'
        . ' competitor_results longtext, overall_score float, competitor_overall_score float,'
        . ' created_at datetime DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id)';
    private const ROW_COLUMNS = 'id, name, email, url, keyword, competitor_url, results, competitor_results, overall_score, competitor_overall_score, created_at';

    private string $prefix = '';
    private array $saved = [];
    private int $alters = 0;
    private bool $psiFails = false;

    public function set_up(): void
    {
        parent::set_up();
        global $wpdb, $wp_rest_server;
        foreach (['halo_audit_db_version', Installer::STATUS_OPTION, 'halo_audit_psi_key'] as $option) {
            $this->saved[$option] = get_option($option, null);
        }
        $this->prefix = $wpdb->prefix;
        $wpdb->prefix = 'hinst' . wp_rand(1000, 9999) . '_';
        add_filter('query', [$this, 'count_alters'], 20);
        $wp_rest_server = new WP_REST_Server();
        do_action('rest_api_init');
        add_filter('halo_audit_dns_resolver', static fn() => static fn(string $host): array => ['93.184.216.34']);
        add_filter('halo_audit_cert_probe', static fn() => new class implements CertProbe {
            public function daysLeft(string $host): ?int { return 90; }
        });
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
        $_SERVER['REMOTE_ADDR'] = '198.51.100.' . wp_rand(1, 254);
    }

    public function tear_down(): void
    {
        global $wpdb;
        foreach (['seo_analyzer_results', 'seo_analyzer_otp', 'seo_analyzer_otp_legacy', 'seo_analyzer_api_keys'] as $table) {
            $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}$table`"); // DROP TEMPORARY TABLE under the test case.
        }
        $wpdb->prefix = $this->prefix;
        foreach ($this->saved as $option => $value) {
            $value === null ? delete_option($option) : update_option($option, $value, $option !== Installer::STATUS_OPTION ? false : true);
        }
        $wpdb->query('COMMIT'); // ALTER committed the test's own writes; commit the restored values too.
        $GLOBALS['current_screen'] = null;
        unset($_COOKIE[Gate::COOKIE]);
        parent::tear_down();
    }

    public function count_alters($query)
    {
        if (is_string($query) && stripos(ltrim($query), 'ALTER TABLE') === 0) {
            $this->alters++;
        }
        return $query;
    }

    public function fake_http($pre, $args, $url)
    {
        $answer = static fn(int $code, string $body, string $type) => ['headers' => ['content-type' => $type], 'body' => $body, 'response' => ['code' => $code, 'message' => ''], 'cookies' => []];
        if ($url === 'https://legacy-site.example.com/page') {
            return $answer(200, '<html><head><title>MBA Programme at Example Business School India</title></head><body><h1>MBA</h1><p>' . str_repeat('word ', 320) . '</p></body></html>', 'text/html');
        }
        if (strpos($url, 'googleapis.com/pagespeedonline') !== false) {
            return $this->psiFails
                ? $answer(500, '{"error":{"code":500}}', 'application/json')
                : $answer(200, '{"lighthouseResult":{"categories":{"performance":{"score":0.95}},"audits":{"largest-contentful-paint":{"numericValue":1800},"cumulative-layout-shift":{"numericValue":0.01}}}}', 'application/json');
        }
        return $answer(404, '', 'text/html');
    }

    // ---- helpers ----------------------------------------------------------------------------------------------

    private function legacyTable(string $definition = self::LEGACY_RESULTS): void
    {
        global $wpdb;
        $wpdb->query('CREATE TABLE ' . Installer::resultsTable() . " ($definition) " . $wpdb->get_charset_collate());
        $this->assertNotNull($this->columns(Installer::resultsTable()));
    }

    /** Three 1.x rows with gaps in their ids, non-ASCII text and both kinds of report, and the next id set to 57. */
    private function legacyRows(): array
    {
        global $wpdb;
        $table = Installer::resultsTable();
        $rows = [
            ['id' => 5, 'name' => 'José Álvarez', 'email' => 'jose@example.invalid', 'url' => 'https://one.example/', 'keyword' => 'mba admissions',
                'competitor_url' => null, 'results' => '{"legacy":1,"title":"Été — ✓"}', 'competitor_results' => null, 'overall_score' => 61.5, 'competitor_overall_score' => null, 'created_at' => '2025-03-01 10:00:00'],
            ['id' => 9, 'name' => 'Priya Sharma', 'email' => 'priya@example.invalid', 'url' => 'https://two.example/a', 'keyword' => 'b.tech',
                'competitor_url' => 'https://rival.example/', 'results' => '{"legacy":2}', 'competitor_results' => '{"legacy":"rival"}', 'overall_score' => 72, 'competitor_overall_score' => 55, 'created_at' => '2025-08-15 08:30:00'],
            ['id' => 41, 'name' => 'A', 'email' => 'a@example.invalid', 'url' => 'https://three.example/', 'keyword' => str_repeat('k', 200),
                'competitor_url' => null, 'results' => '{"legacy":3}', 'competitor_results' => null, 'overall_score' => 0, 'competitor_overall_score' => null, 'created_at' => '2026-01-02 00:00:00'],
        ];
        foreach ($rows as $row) {
            $this->assertSame(1, $wpdb->insert($table, $row));
        }
        $wpdb->query("ALTER TABLE `$table` AUTO_INCREMENT = 57");
        return $this->rows();
    }

    private function rows(): array
    {
        global $wpdb;
        // The rows that existed before the repair: those without a report_key (all of them while the column is missing).
        $where = in_array('report_key', (array) $this->columns(Installer::resultsTable()), true) ? ' WHERE report_key IS NULL' : '';
        return $wpdb->get_results('SELECT ' . self::ROW_COLUMNS . ' FROM ' . Installer::resultsTable() . $where . ' ORDER BY id', ARRAY_A);
    }

    private function columns(string $table): ?array
    {
        global $wpdb;
        $suppress = $wpdb->suppress_errors(true);
        $cols = $wpdb->get_col("SHOW COLUMNS FROM `$table`");
        $wpdb->suppress_errors($suppress);
        return $cols ?: null;
    }

    /** @return array<string,array{0:string[],1:bool}> */
    private function indexes(string $table): array
    {
        global $wpdb;
        $out = [];
        foreach ((array) $wpdb->get_results("SHOW INDEX FROM `$table`", ARRAY_A) as $row) {
            $out[$row['Key_name']][0][(int) $row['Seq_in_index']] = $row['Column_name'];
            $out[$row['Key_name']][1] = (string) $row['Non_unique'] === '0';
        }
        return $out;
    }

    private function createTable(string $table): string
    {
        global $wpdb;
        return (string) $wpdb->get_var("SHOW CREATE TABLE `$table`", 1);
    }

    private function activate(): void
    {
        do_action('activate_' . plugin_basename(SEO_ANALYZER_PLUGIN_FILE));
    }

    private function assertComplete(): void
    {
        global $wpdb;
        $expected = ['id', 'report_key', 'name', 'email', 'url', 'keyword', 'competitor_url', 'results', 'competitor_results', 'overall_score',
            'competitor_overall_score', 'grade', 'engine_version', 'is_education', 'lead_verified_at', 'scan_id', 'created_at'];
        $columns = $this->columns(Installer::resultsTable());
        sort($expected);
        sort($columns);
        $this->assertSame($expected, $columns, 'Each results column exactly once.');
        $indexes = $this->indexes(Installer::resultsTable());
        $byColumns = array_map(static fn($i) => implode(',', $i[0]) . ($i[1] ? ' unique' : ''), $indexes);
        $this->assertContains('id unique', $byColumns);
        $this->assertContains('report_key unique', $byColumns);
        $this->assertContains('created_at', $byColumns);
        $this->assertSame(1, count(array_filter($byColumns, static fn($i) => strpos($i, 'report_key') === 0)), 'One index on report_key.');
        $this->assertSame(['attempts', 'code_hash', 'email', 'expires_at', 'id'], (static function ($c) { sort($c); return $c; })($this->columns(Installer::otpTable())));
        $this->assertSame(Installer::DB_VERSION, get_option('halo_audit_db_version'));
        $this->assertTrue(Installer::verified());
        $this->assertSame([], get_option(Installer::STATUS_OPTION)['problems']);
        $this->assertSame('', Admin::schemaNotice());
        $this->assertSame('YES', $wpdb->get_row("SHOW COLUMNS FROM `" . Installer::resultsTable() . "` LIKE 'name'", ARRAY_A)['Null']);
    }

    // ---- A: fresh install ------------------------------------------------------------------------------------

    public function test_a_activation_on_a_fresh_site_creates_every_table_column_and_index(): void
    {
        delete_option('halo_audit_db_version');
        delete_option(Installer::STATUS_OPTION);
        $this->assertNull($this->columns(Installer::resultsTable()));
        $this->assertNull($this->columns(Installer::otpTable()));

        $this->activate();

        $this->assertComplete();
        $status = get_option(Installer::STATUS_OPTION);
        $this->assertSame(['install', true, []], [$status['mode'], $status['ok'], $status['failed']]);
        $this->assertSame(0, $this->alters, 'A fresh table is created whole, not altered.');
    }

    public function test_a_a_request_on_a_site_without_a_version_installs_as_before(): void
    {
        delete_option('halo_audit_db_version');
        delete_option(Installer::STATUS_OPTION);
        Installer::maybeUpgrade();
        $this->assertComplete();
    }

    // ---- B: the live 1.x table behind halo_audit_db_version = 2 -------------------------------------------------

    public function test_b_legacy_table_behind_version_2_is_left_alone_by_visitors_flagged_in_admin_and_repaired_by_activation(): void
    {
        global $wpdb;
        $this->legacyTable();
        $before = $this->legacyRows();
        $shape = $this->createTable(Installer::resultsTable());
        update_option('halo_audit_db_version', '2', false);
        delete_option(Installer::STATUS_OPTION);
        $this->alters = 0;

        // A visitor's request: no check, no DDL.
        Installer::maybeUpgrade();
        $this->assertSame(0, $this->alters);
        $this->assertFalse(get_option(Installer::STATUS_OPTION, false));

        // An admin request: a read-only check that names what is missing and asks for a reactivation.
        set_current_screen('dashboard');
        Installer::maybeUpgrade();
        $this->assertSame(0, $this->alters);
        $this->assertSame($shape, $this->createTable(Installer::resultsTable()));
        $status = get_option(Installer::STATUS_OPTION);
        $this->assertSame(['check', false], [$status['mode'], $status['ok']]);
        $this->assertContains('Column ' . Installer::resultsTable() . '.report_key is missing.', $status['problems']);
        $this->assertContains('Unique index on ' . Installer::resultsTable() . '(report_key) is missing.', $status['problems']);
        $this->assertStringContainsString('deactivate and activate Halo Audit', Admin::schemaNotice());
        $this->assertFalse(Installer::verified());

        // Reactivation repairs; the visitor's 500 becomes a stored report.
        $this->activate();
        $this->assertComplete();
        $this->assertSame($before, $this->rows(), 'Every 1.x row, id and value unchanged.');
        $this->assertSame(['0', '0', '0'], $wpdb->get_col('SELECT is_education FROM ' . Installer::resultsTable() . ' ORDER BY id'));
        $this->assertSame([null, null, null], $wpdb->get_col('SELECT report_key FROM ' . Installer::resultsTable() . ' ORDER BY id'));

        $store = new ReportStore();
        $key = $store->create(['engine_version' => '2.0.2', 'url' => 'https://new.example/', 'keyword' => 'k', 'is_education' => false, 'checks' => [], 'summary' => ['groups' => [], 'overall' => 80, 'grade' => 'B']]);
        $this->assertNotNull($key);
        $this->assertSame('57', $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Installer::resultsTable() . ' WHERE report_key = %s', $key)), 'AUTO_INCREMENT kept.');
        $this->assertSame(80, $store->get($key)['report']['summary']['overall']);

        // The Reports screen lists the old rows next to the new one.
        set_current_screen('toplevel_page_seo-analyzer');
        $list = new ReportsTable();
        $list->prepare_items();
        $this->assertSame(['57', '41', '9', '5'], array_column($list->items, 'id'));
    }

    public function test_b_early_1x_shape_gets_its_keyword_widened_and_competitor_column_added(): void
    {
        global $wpdb;
        $this->legacyTable('id mediumint(9) NOT NULL AUTO_INCREMENT, name varchar(100) NOT NULL, email varchar(100) NOT NULL, url varchar(255) NOT NULL,'
            . ' keyword varchar(100) NOT NULL, competitor_url varchar(255), results longtext NOT NULL, overall_score float, competitor_overall_score float,'
            . ' created_at datetime DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id)');
        $wpdb->insert(Installer::resultsTable(), ['id' => 3, 'name' => 'Old', 'email' => 'old@example.invalid', 'url' => 'https://old.example/', 'keyword' => 'old keyword', 'results' => '{"v":1}']);
        update_option('halo_audit_db_version', '2', false);

        $this->activate();

        $this->assertComplete();
        $keyword = $wpdb->get_row("SHOW COLUMNS FROM `" . Installer::resultsTable() . "` LIKE 'keyword'", ARRAY_A);
        $this->assertSame(['varchar(255)', 'NO'], [$keyword['Type'], $keyword['Null']]);
        $this->assertSame(['Old', 'old keyword', '{"v":1}'], array_values($wpdb->get_row('SELECT name, keyword, results FROM ' . Installer::resultsTable() . ' WHERE id = 3', ARRAY_A)));
    }

    public function test_b_1x_code_table_is_renamed_not_dropped_and_the_psi_key_is_moved_before_its_table_goes(): void
    {
        global $wpdb;
        $wpdb->query('CREATE TABLE ' . Installer::otpTable() . ' (id mediumint(9) NOT NULL AUTO_INCREMENT, email varchar(100) NOT NULL, widget_id varchar(50) NOT NULL,'
            . ' otp varchar(6) NOT NULL, expiry datetime NOT NULL, created_at datetime DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY email_widget (email, widget_id))');
        $wpdb->insert(Installer::otpTable(), ['email' => 'old@example.invalid', 'widget_id' => 'w1', 'otp' => '123456', 'expiry' => '2025-01-01 00:00:00']);
        $keys = $wpdb->prefix . 'seo_analyzer_api_keys';
        $wpdb->query("CREATE TABLE $keys (id mediumint(9) NOT NULL AUTO_INCREMENT, api_key varchar(255) NOT NULL, PRIMARY KEY (id))");
        $wpdb->insert($keys, ['api_key' => 'SYNTHETIC-OLD-KEY']);
        delete_option('halo_audit_psi_key');
        update_option('halo_audit_db_version', '2', false);

        $this->activate();

        $this->assertComplete();
        $this->assertSame('old@example.invalid', $wpdb->get_var('SELECT email FROM ' . Installer::legacyOtpTable()));
        $this->assertSame('0', $wpdb->get_var('SELECT COUNT(*) FROM ' . Installer::otpTable()));
        $this->assertSame('SYNTHETIC-OLD-KEY', get_option('halo_audit_psi_key'));
        $this->assertNull($this->columns($keys));
    }

    public function test_b_a_1x_key_table_with_a_different_key_and_a_code_table_that_cannot_be_renamed_are_kept(): void
    {
        global $wpdb;
        $keys = $wpdb->prefix . 'seo_analyzer_api_keys';
        $wpdb->query("CREATE TABLE $keys (id mediumint(9) NOT NULL AUTO_INCREMENT, api_key varchar(255) NOT NULL, PRIMARY KEY (id))");
        $wpdb->insert($keys, ['api_key' => 'SYNTHETIC-OLDER-KEY']);
        update_option('halo_audit_psi_key', 'SYNTHETIC-CURRENT-KEY', false);
        // A 1.x code table whose new name is already taken.
        $wpdb->query('CREATE TABLE ' . Installer::otpTable() . ' (id mediumint(9) NOT NULL AUTO_INCREMENT, email varchar(100) NOT NULL, otp varchar(6) NOT NULL, PRIMARY KEY (id))');
        $wpdb->insert(Installer::otpTable(), ['email' => 'old@example.invalid', 'otp' => '654321']);
        $wpdb->query('CREATE TABLE ' . Installer::legacyOtpTable() . ' (id int NOT NULL, PRIMARY KEY (id))');
        $this->alters = 0;

        $this->activate();

        $this->assertSame('SYNTHETIC-OLDER-KEY', $wpdb->get_var("SELECT api_key FROM $keys"), 'A different key is never dropped.');
        $this->assertSame('SYNTHETIC-CURRENT-KEY', get_option('halo_audit_psi_key'));
        $otpColumns = $this->columns(Installer::otpTable());
        sort($otpColumns);
        $this->assertSame(['email', 'id', 'otp'], $otpColumns, 'The 1.x code table is not altered when it cannot be renamed.');
        $status = get_option(Installer::STATUS_OPTION);
        $this->assertFalse($status['ok']);
        $this->assertContains('Table ' . Installer::otpTable() . ' is still the 1.x code table (plain-text column otp).', $status['problems']);
        $this->assertSame('rename the 1.x code table', $status['failed'][0]['statement']);
        $this->assertStringContainsString('not the one in the settings', implode(' ', $status['warnings']));

        // A second activation does not mix the two tables either.
        $this->activate();
        $this->assertFalse(Installer::verified());
        $this->assertNotContains('code_hash', $this->columns(Installer::otpTable()));
    }

    // ---- C: partly migrated -----------------------------------------------------------------------------------

    public function test_c_partly_migrated_table_gets_only_what_is_missing_and_a_rerun_changes_nothing(): void
    {
        global $wpdb;
        $this->legacyTable(str_replace(', PRIMARY KEY (id)', ', report_key char(32) DEFAULT NULL, grade char(1) DEFAULT NULL,'
            . ' PRIMARY KEY (id), UNIQUE KEY report_key (report_key), KEY url_lookup (url(100))', self::LEGACY_RESULTS));
        $wpdb->insert(Installer::resultsTable(), ['id' => 12, 'name' => 'Half', 'email' => 'half@example.invalid', 'url' => 'https://half.example/', 'keyword' => 'k',
            'results' => '{"half":true}', 'report_key' => str_repeat('c', 32), 'grade' => 'C']);
        update_option('halo_audit_db_version', '2', false);
        $this->alters = 0;

        $this->activate();

        $this->assertComplete();
        $indexes = $this->indexes(Installer::resultsTable());
        $this->assertArrayHasKey('url_lookup', $indexes, 'An index the plugin does not know is kept.');
        $this->assertSame(['report_key'], array_values($indexes['report_key'][0]));
        // 4 columns (engine_version, is_education, lead_verified_at, scan_id), name and email made nullable, 1 index.
        $this->assertSame(7, $this->alters);
        $this->assertSame(['Half', 'C', str_repeat('c', 32)], array_values($wpdb->get_row('SELECT name, grade, report_key FROM ' . Installer::resultsTable() . ' WHERE id = 12', ARRAY_A)));

        $shape = $this->createTable(Installer::resultsTable());
        $this->alters = 0;
        $this->activate();
        $this->assertSame(0, $this->alters);
        $this->assertSame($shape, $this->createTable(Installer::resultsTable()));
    }

    public function test_c_an_index_name_taken_by_a_different_index_gets_a_second_name_and_the_old_one_stays(): void
    {
        $this->legacyTable(str_replace(', PRIMARY KEY (id)', ', report_key char(32) DEFAULT NULL, PRIMARY KEY (id), KEY report_key (report_key)', self::LEGACY_RESULTS));
        update_option('halo_audit_db_version', '2', false);

        $this->activate();

        $indexes = $this->indexes(Installer::resultsTable());
        $this->assertFalse($indexes['report_key'][1], 'The non-unique index is kept as it was.');
        $this->assertTrue($indexes['report_key_halo'][1]);
        $this->assertTrue(Installer::verified());
    }

    // ---- D: already correct -----------------------------------------------------------------------------------

    public function test_d_repeated_installs_on_a_correct_schema_change_nothing(): void
    {
        global $wpdb;
        $this->activate();
        $store = new ReportStore();
        $key = $store->create(['engine_version' => '2.0.2', 'url' => 'https://d.example/', 'keyword' => 'd', 'is_education' => true, 'checks' => [], 'summary' => ['groups' => [], 'overall' => 70, 'grade' => 'C']]);
        $shape = [$this->createTable(Installer::resultsTable()), $this->createTable(Installer::otpTable())];
        $row = $wpdb->get_row('SELECT * FROM ' . Installer::resultsTable(), ARRAY_A);
        $this->alters = 0;

        foreach ([1, 2, 3] as $run) {
            $this->activate();
            Installer::install();
            Installer::check();
        }

        $this->assertSame(0, $this->alters);
        $this->assertSame($shape, [$this->createTable(Installer::resultsTable()), $this->createTable(Installer::otpTable())]);
        $this->assertSame($row, $wpdb->get_row('SELECT * FROM ' . Installer::resultsTable(), ARRAY_A));
        $this->assertNotNull($store->get($key));
        $this->assertComplete();
    }

    /** The test site's own tables, made by the real install: activation finds nothing to change and no warning. */
    public function test_d_the_real_installed_schema_verifies_without_any_change(): void
    {
        global $wpdb;
        $isolated = $wpdb->prefix;
        $wpdb->prefix = $this->prefix;
        try {
            $shape = $this->createTable(Installer::resultsTable());
            $this->alters = 0;
            $this->activate();
            $this->assertSame(0, $this->alters);
            $this->assertSame($shape, $this->createTable(Installer::resultsTable()));
            $status = get_option(Installer::STATUS_OPTION);
            $this->assertSame([true, [], []], [$status['ok'], $status['problems'], $status['warnings']]);
        } finally {
            $wpdb->prefix = $isolated; // tear_down only ever drops the isolated tables.
        }
    }

    // ---- E: a failed migration --------------------------------------------------------------------------------

    public function test_e_a_failed_statement_leaves_the_version_unset_reports_it_and_a_retry_completes(): void
    {
        $this->legacyTable();
        $before = $this->legacyRows();
        delete_option('halo_audit_db_version');
        delete_option(Installer::STATUS_OPTION);
        $refuse = static fn($query) => is_string($query) && strpos($query, 'ADD COLUMN `report_key`') !== false ? '' : $query;
        add_filter('query', $refuse);

        $this->activate();

        $status = get_option(Installer::STATUS_OPTION);
        $this->assertFalse($status['ok']);
        $this->assertSame('add column ' . Installer::resultsTable() . '.report_key', $status['failed'][0]['statement']);
        $this->assertSame('The database refused the statement.', $status['failed'][0]['error']);
        $this->assertFalse(get_option('halo_audit_db_version'), 'The version is not saved.');
        $this->assertFalse(Installer::verified());
        $this->assertSame($before, $this->rows(), 'Rows untouched.');
        $this->assertStringContainsString('Failed: add column', Admin::schemaNotice());
        $this->assertContains('engine_version', $this->columns(Installer::resultsTable()), 'The other columns were still added.');

        // Visitors' requests within the hour do not retry the DDL.
        $this->alters = 0;
        Installer::maybeUpgrade();
        $this->assertSame(0, $this->alters);

        // The cause is fixed; the next activation completes and keeps the rows.
        remove_filter('query', $refuse);
        $this->activate();
        $this->assertComplete();
        $this->assertSame($before, $this->rows());
    }

    public function test_e_a_failure_on_a_site_already_at_version_2_leaves_the_option_as_it_was(): void
    {
        $this->legacyTable();
        update_option('halo_audit_db_version', '2', false);
        $refuse = static fn($query) => is_string($query) && stripos($query, 'ALTER TABLE') === 0 ? '' : $query;
        add_filter('query', $refuse);
        $this->activate();
        remove_filter('query', $refuse);
        $this->assertSame('2', get_option('halo_audit_db_version'));
        $this->assertFalse(Installer::verified());
        $this->assertGreaterThanOrEqual(7, count(get_option(Installer::STATUS_OPTION)['failed']));
    }

    public function test_e_a_second_install_while_one_runs_changes_nothing(): void
    {
        global $wpdb;
        $this->legacyTable();
        $wpdb->insert($wpdb->options, ['option_name' => 'halo_audit_schema_lock', 'option_value' => (string) time(), 'autoload' => 'no']);
        $this->alters = 0;
        $status = Installer::install();
        $this->assertSame(['busy', false], [$status['mode'], $status['ok']]);
        $this->assertSame(0, $this->alters);
        $wpdb->delete($wpdb->options, ['option_name' => 'halo_audit_schema_lock']);
    }

    // ---- F: the report flow after a repair -------------------------------------------------------------------

    private function call(string $method, string $route, array $params = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/' . RestController::NS . $route);
        $request->set_header('X-Halo-Nonce', wp_create_nonce(RestController::NONCE_ACTION));
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        return rest_get_server()->dispatch($request);
    }

    public function test_f_after_repair_a_report_is_stored_speed_tested_retried_and_the_old_rows_stay(): void
    {
        global $wpdb;
        $this->legacyTable();
        $before = $this->legacyRows();
        update_option('halo_audit_db_version', '2', false);
        update_option('halo_audit_psi_key', 'FAKE-psi-key-for-tests-0000', false);

        // Before the repair: the report cannot be saved, and /analyze says so (no report_id).
        $suppress = $wpdb->suppress_errors(true);
        $broken = $this->call('POST', '/analyze', ['url' => 'https://legacy-site.example.com/page', 'keyword' => 'mba']);
        $wpdb->suppress_errors($suppress);
        $this->assertSame([500, 'halo_store'], [$broken->get_status(), $broken->get_data()['code']]);

        $this->activate();

        $analyze = $this->call('POST', '/analyze', ['url' => 'https://legacy-site.example.com/page', 'keyword' => 'mba']);
        $this->assertSame(200, $analyze->get_status());
        $id = $analyze->get_data()['report_id'];
        $this->assertSame('1', $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Installer::resultsTable() . ' WHERE report_key = %s', $id)));

        // First speed test gives no result; the retry measures the same stored report.
        $this->psiFails = true;
        $first = $this->call('POST', '/speed', ['report_id' => $id, 'side' => 'mine']);
        $this->assertSame([200, true, true], [$first->get_status(), $first->get_data()['speed_done'], $first->get_data()['speed_failed']]);
        $this->psiFails = false;
        $retry = $this->call('POST', '/speed', ['report_id' => $id, 'side' => 'mine', 'retry' => true]);
        $this->assertSame([200, true, false, 100], [$retry->get_status(), $retry->get_data()['speed_done'], $retry->get_data()['speed_failed'], $retry->get_data()['summary']['groups']['speed']['score']]);

        $stored = (new ReportStore())->get($id)['report'];
        $this->assertSame([true, 100], [$stored['speed_done'], $stored['summary']['groups']['speed']['score']]);
        $_COOKIE[Gate::COOKIE] = \HaloAudit\Wp\Plugin::gate()->token('Asha', 'asha@example.com', time() + 60);
        $this->assertSame(100, $this->call('GET', '/report/' . $id)->get_data()['summary']['groups']['speed']['score']);
        $this->assertSame($before, $this->rows(), 'The 1.x rows are unchanged.');
    }
}
