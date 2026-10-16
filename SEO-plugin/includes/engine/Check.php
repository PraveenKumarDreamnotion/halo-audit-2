<?php
namespace HaloAudit;

interface Check
{
    public function id(): string;
    public function group(): string;
    public function severity(): int;
    public function title(): string;
    public function why(): string;
    public function fix(string $status): string;
    public function run(Context $c): Result;
}
