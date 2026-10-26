<?php
namespace HaloAudit\Wp;

/**
 * The plugin's tables: created on a fresh site, repaired on an existing one, and checked against the database
 * itself rather than against halo_audit_db_version alone.
 *
 * Why the option alone is not enough: up to 2.0.2 the upgrade wrote halo_audit_db_version = 2 whether or not its
 * dbDelta changes had worked, and the option can also reach a site in a partial database copy. A site could then
 * say 2 while keeping the 1.x results table (no report_key), so every report insert failed.
 *
 * - Activation always runs install(): it checks every table, column and index and makes the missing ones. This is
 *   the step to run after deploying to a site whose schema is in doubt.
 * - A request on a site whose option is missing or older installs as before (one at a time, retried at most hourly).
 * - On a site whose option already says current but whose tables were never checked, only an admin request checks
 *   them, read-only; if something is missing it asks for a reactivation in an admin notice and changes nothing.
 *
 * Repairs only add (tables, columns, indexes) or widen (a NOT NULL column made nullable, keyword varchar(100) to
 * varchar(255)). No table, column, index or row is dropped. The version and the "verified" state are saved only
 * after a check of the real tables finds nothing missing.
 */
final class Installer
{
    public const DB_VERSION = '2';
    public const CRON_HOOK = 'halo_audit_purge';
    /** The last install or check: version, time, ok, problems, warnings and failed statements. */
    public const STATUS_OPTION = 'halo_audit_schema';
    private const LOCK_OPTION = 'halo_audit_schema_lock';
    private const LOCK_SECONDS = 300;
    private const RETRY_SECONDS = 3600;

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

    /** Where a 1.x code table (plain-text codes) is kept when 2.x needs its name. Never read by the plugin. */
    public static function legacyOtpTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'seo_analyzer_otp_legacy';
    }

    /**
     * Every managed table: columns (definition, blocking when missing) and indexes (columns, unique, blocking when
     * missing). The same definitions build a new table and the columns added to an old one.
     *
     * @return array<string,array{columns:array<string,string>,indexes:array<string,array{0:string[],1:bool,2:bool}>}>
     */
    public static function schema(): array
    {
        return [
            self::resultsTable() => [
                'columns' => [
                    'id' => 'mediumint(9) NOT NULL AUTO_INCREMENT',
                    'report_key' => 'char(32) DEFAULT NULL',
                    'name' => 'varchar(100) DEFAULT NULL',
                    'email' => 'varchar(100) DEFAULT NULL',
                    'url' => 'varchar(255) NOT NULL',
                    'keyword' => 'varchar(255) NOT NULL',
                    'competitor_url' => 'varchar(255) DEFAULT NULL',
                    'results' => 'longtext NOT NULL',
                    'competitor_results' => 'longtext',
                    'overall_score' => 'float DEFAULT NULL',
                    'competitor_overall_score' => 'float DEFAULT NULL',
                    'grade' => 'char(1) DEFAULT NULL',
                    'engine_version' => 'varchar(20) DEFAULT NULL',
                    'is_education' => 'tinyint(1) NOT NULL DEFAULT 0',
                    'lead_verified_at' => 'datetime DEFAULT NULL',
                    'scan_id' => 'varchar(64) DEFAULT NULL',
                    'created_at' => 'datetime DEFAULT CURRENT_TIMESTAMP',
                ],
                // name => [columns, unique, blocking]. report_key must be unique: reports are looked up by it.
                'indexes' => [
                    'PRIMARY' => [['id'], true, true],
                    'report_key' => [['report_key'], true, true],
                    'created_at' => [['created_at'], false, false],
                ],
            ],
            self::otpTable() => [
                'columns' => [
                    'id' => 'bigint(20) unsigned NOT NULL AUTO_INCREMENT',
                    'email' => 'varchar(190) NOT NULL',
                    'code_hash' => 'varchar(255) NOT NULL',
                    'expires_at' => 'datetime NOT NULL',
                    'attempts' => 'tinyint(3) unsigned NOT NULL DEFAULT 0',
                ],
                'indexes' => [
                    'PRIMARY' => [['id'], true, true],
                    'email' => [['email'], false, false],
                ],
            ],
        ];
    }

    /** On every request (Plugin::boot). Never changes tables on a site whose version option is current. */
    public static function maybeUpgrade(): void
    {
        $status = get_option(self::STATUS_OPTION, null);
        $verified = is_array($status) && !empty($status['ok']) && ($status['version'] ?? '') === self::DB_VERSION;

        if (get_option('halo_audit_db_version') !== self::DB_VERSION) {
            // A fresh site or one that was never upgraded: install, but not again within the hour after a failure.
            if (!is_array($status) || !empty($status['ok']) || time() - (int) ($status['at'] ?? 0) >= self::RETRY_SECONDS) {
                self::install();
            }
        } elseif (!$verified && is_admin() && (!is_array($status) || time() - (int) ($status['at'] ?? 0) >= self::RETRY_SECONDS)) {
            self::check();
        }

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    /** register_activation_hook: always checks and repairs, whatever the version option says. */
    public static function activate(): void
    {
        self::install();
    }

    /**
     * Checks the real tables and adds what is missing. Saves halo_audit_db_version only when nothing blocking is
     * left; otherwise records what failed (STATUS_OPTION) for the admin notice and leaves the option as it was.
     *
     * @return array{version:string,at:int,ok:bool,mode:string,problems:string[],warnings:string[],failed:array<int,array{statement:string,error:string}>}
     */
    public static function install(): array
    {
        if (!self::lock()) {
            // Another request is installing right now; it saves the status.
            return ['version' => self::DB_VERSION, 'at' => time(), 'ok' => false, 'mode' => 'busy', 'problems' => ['Another request is updating the tables.'], 'warnings' => [], 'failed' => []];
        }
        try {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $failed = [];
            self::moveLegacyOtpTable($failed);
            foreach (self::schema() as $table => $spec) {
                if (self::columns($table) === null) {
                    self::create($table, $spec, $failed);
                } else {
                    self::repair($table, $spec, $failed);
                }
            }
            $warnings = self::migratePsiKey();
            $status = self::verify('install', $failed, $warnings);
            if ($status['ok']) {
                update_option('halo_audit_db_version', self::DB_VERSION, false);
            }
            return $status;
        } finally {
            self::unlock();
        }
    }

    /** Read-only: compares the real tables with schema() and saves the result. */
    public static function check(): array
    {
        return self::verify('check', [], []);
    }

    /** True when the last install or check found the tables complete for this version. */
    public static function verified(): bool
    {
        $status = get_option(self::STATUS_OPTION, null);
        return is_array($status) && !empty($status['ok']) && ($status['version'] ?? '') === self::DB_VERSION;
    }

    /** After a failed report insert: the tables are checked again on the next admin request. */
    public static function markUnverified(): void
    {
        $status = get_option(self::STATUS_OPTION, null);
        if (is_array($status) && !empty($status['ok'])) {
            delete_option(self::STATUS_OPTION);
        }
    }

    /**
     * What is missing or different in the real tables. Blocking: a missing table, column or blocking index, a
     * name/email column that is still NOT NULL, a keyword column narrower than 255. Anything else is a warning.
     *
     * @return array{0:string[],1:string[]} [problems, warnings]
     */
    public static function differences(): array
    {
        $problems = [];
        $warnings = [];
        foreach (self::schema() as $table => $spec) {
            $columns = self::columns($table);
            if ($columns === null) {
                $problems[] = "Table $table is missing.";
                continue;
            }
            if ($table === self::otpTable() && isset($columns['otp'])) {
                $problems[] = "Table $table is still the 1.x code table (plain-text column otp).";
            }
            foreach ($spec['columns'] as $name => $definition) {
                if (!isset($columns[$name])) {
                    $problems[] = "Column $table.$name is missing.";
                    continue;
                }
                $found = $columns[$name];
                if (in_array($name, ['name', 'email'], true) && $table === self::resultsTable() && $found['Null'] !== 'YES') {
                    $problems[] = "Column $table.$name is NOT NULL; reports without a lead cannot be saved.";
                } elseif ($name === 'keyword' && $table === self::resultsTable() && self::varcharLength($found['Type']) !== null && self::varcharLength($found['Type']) < 255) {
                    $problems[] = "Column $table.keyword is {$found['Type']}; keywords up to 255 characters cannot be saved.";
                } elseif (!self::sameColumn($definition, $found)) {
                    $warnings[] = "Column $table.$name is {$found['Type']}" . ($found['Null'] === 'YES' ? ' NULL' : ' NOT NULL') . ', expected ' . $definition . '.';
                }
            }
            $indexes = self::indexes($table);
            foreach ($spec['indexes'] as $name => [$cols, $unique, $blocking]) {
                if (self::findIndex($indexes, $cols, $unique) === null) {
                    $message = ($unique ? 'Unique index' : 'Index') . " on $table(" . implode(', ', $cols) . ') is missing.';
                    if ($blocking) {
                        $problems[] = $message;
                    } else {
                        $warnings[] = $message;
                    }
                }
            }
            $collation = '';
            foreach ($columns as $column) {
                if (!empty($column['Collation'])) {
                    $collation = (string) $column['Collation'];
                    break;
                }
            }
            if ($collation !== '' && strpos($collation, 'utf8mb4') !== 0) {
                $warnings[] = "Table $table uses $collation: text with characters outside it (emoji, for example) cannot be saved. Converting it to utf8mb4 is a manual step.";
            }
        }
        return [$problems, $warnings];
    }

    private static function verify(string $mode, array $failed, array $warnings): array
    {
        [$problems, $found] = self::differences();
        $status = [
            'version' => self::DB_VERSION,
            'at' => time(),
            'ok' => $problems === [] && $failed === [],
            'mode' => $mode,
            'problems' => $problems,
            'warnings' => array_values(array_unique(array_merge($warnings, $found))),
            'failed' => $failed,
        ];
        update_option(self::STATUS_OPTION, $status, true);
        return $status;
    }

    /** @param array{columns:array<string,string>,indexes:array<string,array>} $spec */
    private static function create(string $table, array $spec, array &$failed): void
    {
        global $wpdb;
        $lines = [];
        foreach ($spec['columns'] as $name => $definition) {
            $lines[] = "$name $definition";
        }
        foreach ($spec['indexes'] as $name => [$cols, $unique]) {
            // dbDelta needs two spaces after PRIMARY KEY.
            $lines[] = $name === 'PRIMARY'
                ? 'PRIMARY KEY  (' . implode(',', $cols) . ')'
                : ($unique ? 'UNIQUE KEY ' : 'KEY ') . $name . ' (' . implode(',', $cols) . ')';
        }
        dbDelta("CREATE TABLE $table (\n" . implode(",\n", $lines) . "\n) " . $wpdb->get_charset_collate() . ';');
        if (self::columns($table) === null) {
            $failed[] = ['statement' => "create table $table", 'error' => $wpdb->last_error !== '' ? $wpdb->last_error : 'The database did not create the table.'];
        }
    }

    /** Adds missing columns and indexes, makes name/email nullable and widens keyword. Nothing else is changed. */
    private static function repair(string $table, array $spec, array &$failed): void
    {
        $columns = (array) self::columns($table);
        if ($table === self::otpTable() && isset($columns['otp'])) {
            return; // Still the 1.x code table (its rename failed): never turned into a mix of both.
        }
        foreach ($spec['columns'] as $name => $definition) {
            if (!isset($columns[$name])) {
                if ($name === 'id') {
                    continue; // A table without its id column is not repaired automatically; differences() reports it.
                }
                self::alter($table, "ADD COLUMN `$name` $definition", "add column $table.$name", $failed);
                continue;
            }
            if ($table !== self::resultsTable()) {
                continue;
            }
            $found = $columns[$name];
            $collate = empty($found['Collation']) ? '' : ' COLLATE ' . $found['Collation'];
            if (in_array($name, ['name', 'email'], true) && $found['Null'] !== 'YES') {
                // Same type and collation, only NULL allowed: 2.x saves reports before anyone gives a name.
                self::alter($table, "MODIFY `$name` {$found['Type']}$collate DEFAULT NULL", "make $table.$name nullable", $failed);
            } elseif ($name === 'keyword' && self::varcharLength($found['Type']) !== null && self::varcharLength($found['Type']) < 255) {
                self::alter($table, "MODIFY `keyword` varchar(255)$collate " . ($found['Null'] === 'YES' ? 'DEFAULT NULL' : 'NOT NULL'), "widen $table.keyword to varchar(255)", $failed);
            }
        }

        $indexes = self::indexes($table);
        foreach ($spec['indexes'] as $name => [$cols, $unique]) {
            if ($name === 'PRIMARY' || self::findIndex($indexes, $cols, $unique) !== null) {
                continue;
            }
            $indexName = isset($indexes[$name]) ? $name . '_halo' : $name; // The name is taken by a different index.
            $quoted = implode(', ', array_map(static fn($c) => "`$c`", $cols));
            self::alter($table, ($unique ? 'ADD UNIQUE KEY' : 'ADD KEY') . " `$indexName` ($quoted)", "add index $table.$indexName", $failed);
        }
    }

    private static function alter(string $table, string $change, string $label, array &$failed): void
    {
        global $wpdb;
        if ($wpdb->query("ALTER TABLE `$table` $change") === false) {
            $failed[] = ['statement' => $label, 'error' => $wpdb->last_error !== '' ? $wpdb->last_error : 'The database refused the statement.'];
        }
    }

    /**
     * 1.x kept plain-text codes in a table of the same name (columns otp, expiry). It is renamed, not dropped, so
     * 2.x can create its own; it still holds email addresses and old codes until someone removes it.
     */
    private static function moveLegacyOtpTable(array &$failed): void
    {
        $columns = self::columns(self::otpTable());
        if ($columns === null || !isset($columns['otp'])) {
            return;
        }
        if (self::columns(self::legacyOtpTable()) !== null) {
            $failed[] = ['statement' => 'rename the 1.x code table', 'error' => self::legacyOtpTable() . ' already exists; the 1.x code table was left as it is.'];
            return;
        }
        self::alter(self::otpTable(), 'RENAME TO `' . self::legacyOtpTable() . '`', 'rename the 1.x code table to ' . self::legacyOtpTable(), $failed);
    }

    /**
     * 1.x kept the PageSpeed key in its own table. The key is copied to the option when the option is empty, and the
     * table is dropped only when it holds no key or the same key as the option; any other key is kept there.
     *
     * @return string[] warnings
     */
    private static function migratePsiKey(): array
    {
        global $wpdb;
        $old = $wpdb->prefix . 'seo_analyzer_api_keys';
        if (self::columns($old) === null) {
            return [];
        }
        $suppress = $wpdb->suppress_errors(true);
        $key = $wpdb->get_var("SELECT api_key FROM `$old` ORDER BY id ASC LIMIT 1");
        $wpdb->suppress_errors($suppress);

        if (is_string($key) && $key !== '') {
            if (!get_option('halo_audit_psi_key')) {
                update_option('halo_audit_psi_key', $key, false);
            }
            if ((string) get_option('halo_audit_psi_key') !== $key) {
                return ["$old holds a PageSpeed key that is not the one in the settings, so the table was kept. Remove it by hand once checked."];
            }
        }
        $wpdb->query("DROP TABLE IF EXISTS `$old`");
        return [];
    }

    /** @return array<string,array<string,mixed>>|null Columns by name (SHOW FULL COLUMNS rows), or null when the table does not exist. */
    private static function columns(string $table): ?array
    {
        global $wpdb;
        $suppress = $wpdb->suppress_errors(true);
        $rows = $wpdb->get_results("SHOW FULL COLUMNS FROM `$table`", ARRAY_A);
        $wpdb->suppress_errors($suppress);
        if (!$rows) {
            return null;
        }
        $columns = [];
        foreach ($rows as $row) {
            $columns[$row['Field']] = $row;
        }
        return $columns;
    }

    /** @return array<string,array{0:string[],1:bool}> Indexes by name: [columns in order, unique]. */
    private static function indexes(string $table): array
    {
        global $wpdb;
        $suppress = $wpdb->suppress_errors(true);
        $rows = (array) $wpdb->get_results("SHOW INDEX FROM `$table`", ARRAY_A);
        $wpdb->suppress_errors($suppress);
        $indexes = [];
        foreach ($rows as $row) {
            $indexes[$row['Key_name']][1] = (string) $row['Non_unique'] === '0';
            $indexes[$row['Key_name']][0][(int) $row['Seq_in_index']] = $row['Column_name'];
        }
        foreach ($indexes as $name => $index) {
            ksort($index[0]);
            $indexes[$name][0] = array_values($index[0]);
        }
        return $indexes;
    }

    /** The name of an index on exactly these columns (unique when asked), or null. */
    private static function findIndex(array $indexes, array $cols, bool $unique): ?string
    {
        foreach ($indexes as $name => [$columns, $isUnique]) {
            if ($columns === $cols && ($isUnique || !$unique)) {
                return (string) $name;
            }
        }
        return null;
    }

    private static function varcharLength(string $type): ?int
    {
        return preg_match('/^varchar\((\d+)\)/i', $type, $m) ? (int) $m[1] : null;
    }

    /** Type, nullability and default compared the way MySQL and MariaDB both report them (no integer widths). */
    private static function sameColumn(string $definition, array $found): bool
    {
        $type = static fn(string $t): string => trim(preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', strtolower($t)));
        preg_match('/^(\S+(?: unsigned)?)/i', $definition, $m);
        if ($type($m[1]) !== $type((string) $found['Type'])) {
            return false;
        }
        $notNull = stripos($definition, 'NOT NULL') !== false;
        if ($notNull !== ($found['Null'] !== 'YES')) {
            return false;
        }
        $default = preg_match('/DEFAULT (\S+)/i', $definition, $d) ? strtolower(trim($d[1], "'")) : null;
        $actual = $found['Default'] === null ? null : strtolower(str_replace('()', '', trim((string) $found['Default'], "'")));
        $actual = $actual === 'null' ? null : $actual;
        return $default === $actual || ($default === 'null' && $actual === null);
    }

    /** One install at a time: a row added with INSERT IGNORE, taken over when older than LOCK_SECONDS. */
    private static function lock(): bool
    {
        global $wpdb;
        $now = time();
        if ((int) $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK_OPTION, (string) $now)) === 1) {
            return true;
        }
        return (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
            (string) $now, self::LOCK_OPTION, $now - self::LOCK_SECONDS
        )) === 1;
    }

    private static function unlock(): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->options, ['option_name' => self::LOCK_OPTION]);
        wp_cache_delete(self::LOCK_OPTION, 'options');
    }
}
