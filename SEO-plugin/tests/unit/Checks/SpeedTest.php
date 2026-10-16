<?php
namespace HaloAudit\Tests\Checks;

use HaloAudit\Checks\Speed;
use HaloAudit\Tests\Support\Make;
use PHPUnit\Framework\TestCase;

final class SpeedTest extends TestCase
{
    private function status(string $class, ?array $psi): string
    {
        return (new $class())->run(Make::ctx(Make::page(), Make::site(['psi' => $psi])))->status;
    }

    private function psi(int $score = 95, float $lcp = 2000.0, float $cls = 0.05): array
    {
        return ['score' => $score, 'lcp_ms' => $lcp, 'cls' => $cls];
    }

    public function test_all_are_not_measured_without_data(): void
    {
        foreach ([Speed\PsiScore::class, Speed\Lcp::class, Speed\Cls::class] as $class) {
            $this->assertSame('not_measured', $this->status($class, null), $class);
        }
    }

    public function test_psi_score_bands(): void
    {
        $this->assertSame('pass', $this->status(Speed\PsiScore::class, $this->psi(90)));
        $this->assertSame('warn', $this->status(Speed\PsiScore::class, $this->psi(89)));
        $this->assertSame('warn', $this->status(Speed\PsiScore::class, $this->psi(50)));
        $this->assertSame('fail', $this->status(Speed\PsiScore::class, $this->psi(49)));
    }

    public function test_lcp_bands(): void
    {
        $this->assertSame('pass', $this->status(Speed\Lcp::class, $this->psi(95, 2499.0)));
        $this->assertSame('warn', $this->status(Speed\Lcp::class, $this->psi(95, 2500.0)));
        $this->assertSame('warn', $this->status(Speed\Lcp::class, $this->psi(95, 4000.0)));
        $this->assertSame('fail', $this->status(Speed\Lcp::class, $this->psi(95, 4001.0)));
    }

    public function test_cls_bands(): void
    {
        $this->assertSame('pass', $this->status(Speed\Cls::class, $this->psi(95, 2000.0, 0.09)));
        $this->assertSame('warn', $this->status(Speed\Cls::class, $this->psi(95, 2000.0, 0.1)));
        $this->assertSame('warn', $this->status(Speed\Cls::class, $this->psi(95, 2000.0, 0.25)));
        $this->assertSame('fail', $this->status(Speed\Cls::class, $this->psi(95, 2000.0, 0.26)));
    }

    public function test_every_check_has_copy(): void
    {
        foreach ([Speed\PsiScore::class, Speed\Lcp::class, Speed\Cls::class] as $class) {
            $check = new $class();
            $this->assertSame('speed', $check->group());
            $this->assertNotSame('', $check->title());
            $this->assertNotSame('', $check->why());
            $this->assertNotSame('', $check->fix('fail'));
            $this->assertNotSame('', $check->fix('warn'));
        }
    }
}
