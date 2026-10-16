<?php
namespace HaloAudit;

interface CertProbe
{
    /** Days until the TLS certificate of $host expires, or null when it cannot be read. */
    public function daysLeft(string $host): ?int;
}
