<?php
namespace HaloAudit;

/** The slot for the live AI visibility scan. The real client arrives with the scan server. */
interface ScanClient
{
    /** @return array{available:bool,scan_id?:string} */
    public function start(string $reportKey): array;

    /** @return array{available:bool,state?:string} */
    public function status(string $scanId): array;

    /** @return array{available:bool,result?:array} */
    public function result(string $scanId): array;
}
