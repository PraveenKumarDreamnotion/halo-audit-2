<?php
namespace HaloAudit\Tests\Support;

use HaloAudit\CertProbe;

final class FixedCert implements CertProbe
{
    public ?int $days;
    /** @var string[] */
    public array $asked = [];

    public function __construct(?int $days)
    {
        $this->days = $days;
    }

    public function daysLeft(string $host): ?int
    {
        $this->asked[] = $host;
        return $this->days;
    }
}
