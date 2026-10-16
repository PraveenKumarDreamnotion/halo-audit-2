<?php
namespace HaloAudit;

final class ReportView
{
    /** What a visitor sees before the email gate: scores and counts only, for both pages of a comparison. */
    public static function free(array $report): array
    {
        $view = [
            'url' => $report['url'],
            'keyword' => $report['keyword'],
            'is_education' => (bool) $report['is_education'],
            'speed_done' => (bool) $report['speed_done'],
            'summary' => $report['summary'],
            'labels' => Scorer::LABELS,
        ];
        if (isset($report['competitor'])) {
            $c = $report['competitor'];
            $view['competitor'] = [
                'url' => $c['url'],
                'is_education' => (bool) $c['is_education'],
                'speed_done' => (bool) $c['speed_done'],
                'summary' => $c['summary'],
            ];
        }
        return $view;
    }

    /** The free view plus every finding, for both pages of a comparison. */
    public static function full(array $report): array
    {
        $view = self::free($report) + ['checks' => $report['checks']];
        if (isset($view['competitor'])) {
            $view['competitor']['checks'] = $report['competitor']['checks'];
            $view['pairs'] = self::pairs($report['checks'], $report['competitor']['checks']);
        }
        return $view;
    }

    /**
     * Every check of a comparison, matched by check ID: one row per ID that ran on either page, grouped in the
     * order of Scorer::LABELS. "mine" is your page's result and "theirs" the competitor's; null means the check
     * did not run on that page (an education check on a site that is not a school, for example). That is
     * different from "not_measured", which means the check ran but got no reading. The fix sentence belongs to
     * your page only, so the competitor's side has none.
     *
     * @return array<int,array{id:string,group:string,title:string,why:string,mine:?array,theirs:?array}>
     */
    public static function pairs(array $mine, array $theirs): array
    {
        $rows = [];
        foreach ([['mine', $mine], ['theirs', $theirs]] as [$side, $checks]) {
            foreach ($checks as $check) {
                $id = (string) $check['id'];
                if (!isset($rows[$id])) {
                    $rows[$id] = ['id' => $id, 'group' => (string) $check['group'], 'title' => (string) $check['title'], 'why' => (string) $check['why'], 'mine' => null, 'theirs' => null];
                }
                $rows[$id][$side] = ['status' => (string) $check['status'], 'evidence' => (string) $check['evidence']]
                    + ($side === 'mine' ? ['message' => (string) $check['message']] : []);
            }
        }

        $order = array_flip(array_keys(Scorer::LABELS));
        $position = array_flip(array_keys($rows));
        uksort($rows, static function (string $a, string $b) use ($rows, $order, $position): int {
            $ga = $order[$rows[$a]['group']] ?? PHP_INT_MAX;
            $gb = $order[$rows[$b]['group']] ?? PHP_INT_MAX;
            return $ga === $gb ? $position[$a] <=> $position[$b] : $ga <=> $gb;
        });
        return array_values($rows);
    }
}
