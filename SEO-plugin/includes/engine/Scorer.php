<?php
namespace HaloAudit;

final class Scorer
{
    public const WEIGHTS = ['onpage' => 30, 'technical' => 20, 'ai' => 30, 'speed' => 20];

    public const LABELS = [
        'onpage' => 'On-page for your keyword',
        'technical' => 'Technical',
        'ai' => 'AI readiness',
        'speed' => 'Speed',
        'education' => 'Education extras',
    ];

    private const POINTS = [Result::PASS => 100, Result::WARN => 50, Result::FAIL => 0];

    /** @param array<int,array{group:string,severity:int,status:string}> $rows */
    public static function score(array $rows): array
    {
        $groups = [];
        foreach (array_keys(self::WEIGHTS) as $g) {
            $groups[$g] = ['score' => null, 'pass' => 0, 'warn' => 0, 'fail' => 0, 'not_measured' => 0];
        }

        $sum = [];
        $weight = [];
        foreach ($rows as $row) {
            $g = $row['group'];
            if (!isset($groups[$g])) {
                continue;
            }
            $groups[$g][$row['status']]++;
            if ($row['status'] === Result::NOT_MEASURED) {
                continue;
            }
            $sev = (int) $row['severity'];
            $sum[$g] = ($sum[$g] ?? 0) + self::POINTS[$row['status']] * $sev;
            $weight[$g] = ($weight[$g] ?? 0) + $sev;
        }

        $total = 0;
        $totalWeight = 0;
        foreach (self::WEIGHTS as $g => $w) {
            if (empty($weight[$g])) {
                continue;
            }
            $groups[$g]['score'] = (int) round($sum[$g] / $weight[$g]);
            $total += $groups[$g]['score'] * $w;
            $totalWeight += $w;
        }

        $overall = $totalWeight > 0 ? (int) round($total / $totalWeight) : null;

        return ['groups' => $groups, 'overall' => $overall, 'grade' => self::grade($overall)];
    }

    public static function grade(?int $score): ?string
    {
        if ($score === null) {
            return null;
        }
        if ($score >= 90) { return 'A'; }
        if ($score >= 70) { return 'B'; }
        if ($score >= 50) { return 'C'; }
        if ($score >= 30) { return 'D'; }
        return 'F';
    }
}
