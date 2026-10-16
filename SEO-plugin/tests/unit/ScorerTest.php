<?php
namespace HaloAudit\Tests;

use HaloAudit\Scorer;
use PHPUnit\Framework\TestCase;

final class ScorerTest extends TestCase
{
    private function row(string $group, int $sev, string $status): array
    {
        return ['group' => $group, 'severity' => $sev, 'status' => $status];
    }

    public function test_group_score_is_severity_weighted(): void
    {
        $out = Scorer::score([
            $this->row('onpage', 3, 'pass'),
            $this->row('onpage', 1, 'fail'),
        ]);
        $this->assertSame(75, $out['groups']['onpage']['score']);
        $this->assertSame(1, $out['groups']['onpage']['pass']);
        $this->assertSame(1, $out['groups']['onpage']['fail']);
    }

    public function test_warn_is_worth_half(): void
    {
        $out = Scorer::score([$this->row('ai', 2, 'warn')]);
        $this->assertSame(50, $out['groups']['ai']['score']);
    }

    public function test_not_measured_is_left_out_of_the_mean(): void
    {
        $out = Scorer::score([
            $this->row('technical', 3, 'pass'),
            $this->row('technical', 3, 'not_measured'),
        ]);
        $this->assertSame(100, $out['groups']['technical']['score']);
        $this->assertSame(1, $out['groups']['technical']['not_measured']);
    }

    public function test_unmeasured_group_is_null_and_overall_renormalises(): void
    {
        $out = Scorer::score([
            $this->row('onpage', 1, 'pass'),     // 100, weight 30
            $this->row('technical', 1, 'fail'),  // 0, weight 20
            $this->row('speed', 3, 'not_measured'),
        ]);
        $this->assertNull($out['groups']['speed']['score']);
        $this->assertNull($out['groups']['ai']['score']);
        $this->assertSame(60, $out['overall']); // 100*30 / (30+20)
    }

    public function test_education_rows_never_change_the_score(): void
    {
        $base = [$this->row('onpage', 1, 'pass')];
        $with = array_merge($base, [$this->row('education', 3, 'fail')]);
        $this->assertSame(Scorer::score($base)['overall'], Scorer::score($with)['overall']);
        $this->assertArrayNotHasKey('education', Scorer::score($with)['groups']);
    }

    public function test_nothing_measured_gives_no_score_and_no_grade(): void
    {
        $out = Scorer::score([$this->row('onpage', 1, 'not_measured')]);
        $this->assertNull($out['overall']);
        $this->assertNull($out['grade']);
    }

    /** @dataProvider grades */
    public function test_grade_bands(?int $score, ?string $grade): void
    {
        $this->assertSame($grade, Scorer::grade($score));
    }

    public function grades(): array
    {
        return [[100, 'A'], [90, 'A'], [89, 'B'], [70, 'B'], [69, 'C'], [50, 'C'], [49, 'D'], [30, 'D'], [29, 'F'], [0, 'F'], [null, null]];
    }
}
