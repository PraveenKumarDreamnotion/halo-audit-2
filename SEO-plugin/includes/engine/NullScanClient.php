<?php
namespace HaloAudit;

final class NullScanClient implements ScanClient
{
    public function start(string $reportKey): array { return ['available' => false]; }
    public function status(string $scanId): array { return ['available' => false]; }
    public function result(string $scanId): array { return ['available' => false]; }
}
