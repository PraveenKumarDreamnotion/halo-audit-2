<?php
namespace HaloAudit\Wp;

final class ReportStore
{
    public static function isKey(string $key): bool
    {
        return preg_match('/^[a-f0-9]{32}$/', $key) === 1;
    }

    /**
     * A comparison keeps the competitor's report in the competitor_* columns (part of the table since 1.x),
     * so the results column always holds one page's report.
     */
    public function create(array $report): string
    {
        global $wpdb;
        $key = bin2hex(random_bytes(16));
        $wpdb->insert(Installer::resultsTable(), [
            'report_key' => $key,
            'url' => mb_substr($report['url'], 0, 255),
            'keyword' => mb_substr($report['keyword'], 0, 255),
            'engine_version' => $report['engine_version'],
            'is_education' => $report['is_education'] ? 1 : 0,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ] + self::columns($report));
        return $key;
    }

    /** @return array<string,mixed> The columns that change when a report changes. */
    private static function columns(array $report): array
    {
        $competitor = $report['competitor'] ?? null;
        unset($report['competitor']);
        return [
            'results' => wp_json_encode($report),
            'overall_score' => $report['summary']['overall'],
            'grade' => $report['summary']['grade'],
            'competitor_url' => is_array($competitor) ? mb_substr($competitor['url'], 0, 255) : null,
            'competitor_results' => is_array($competitor) ? wp_json_encode($competitor) : null,
            'competitor_overall_score' => is_array($competitor) ? $competitor['summary']['overall'] : null,
        ];
    }

    public function get(string $key): ?array
    {
        global $wpdb;
        if (!self::isKey($key)) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Installer::resultsTable() . ' WHERE report_key = %s', $key), ARRAY_A);
        if (!$row) {
            return null;
        }
        $report = json_decode($row['results'], true);
        if (!is_array($report)) {
            return null;
        }
        unset($report['competitor']);
        if (!empty($row['competitor_results'])) {
            $competitor = json_decode($row['competitor_results'], true);
            if (!is_array($competitor) || !isset($competitor['url'], $competitor['summary'], $competitor['checks'])) {
                return null; // A comparison with an unreadable side is not shown at all.
            }
            $report['competitor'] = $competitor;
        }
        return [
            'key' => $key,
            'report' => $report,
            'name' => $row['name'],
            'email' => $row['email'],
            'lead_verified_at' => $row['lead_verified_at'],
        ];
    }

    public function update(string $key, array $report): void
    {
        global $wpdb;
        if (!self::isKey($key)) {
            return;
        }
        $wpdb->update(Installer::resultsTable(), self::columns($report), ['report_key' => $key]);
    }

    /**
     * Saves one page of a report: "mine" writes only your page's columns, "competitor" only the competitor's.
     * A speed result for one page then never rewrites the other page with an older copy of it, even when
     * two requests for the same report overlap.
     */
    public function updateSide(string $key, string $side, array $report): void
    {
        global $wpdb;
        if (!self::isKey($key)) {
            return;
        }
        $names = $side === 'competitor'
            ? ['competitor_results', 'competitor_overall_score']
            : ['results', 'overall_score', 'grade'];
        $wpdb->update(Installer::resultsTable(), array_intersect_key(self::columns($report), array_flip($names)), ['report_key' => $key]);
    }

    public function attachLead(string $key, string $name, string $email): void
    {
        global $wpdb;
        if (!self::isKey($key)) {
            return;
        }
        $wpdb->update(Installer::resultsTable(), [
            'name' => mb_substr($name, 0, 100),
            'email' => mb_substr($email, 0, 100),
            'lead_verified_at' => gmdate('Y-m-d H:i:s'),
        ], ['report_key' => $key]);
    }

    public function purgeUnverified(int $days = 7): int
    {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        return (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Installer::resultsTable() . ' WHERE report_key IS NOT NULL AND lead_verified_at IS NULL AND created_at < %s',
            $cutoff
        ));
    }
}
