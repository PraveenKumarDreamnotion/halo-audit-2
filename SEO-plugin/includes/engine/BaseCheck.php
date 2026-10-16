<?php
namespace HaloAudit;

abstract class BaseCheck implements Check
{
    public const ID = '';
    public const GROUP = '';
    public const SEVERITY = 1;
    public const TITLE = '';
    public const WHY = '';
    public const FIX_WARN = '';
    public const FIX_FAIL = '';

    public function id(): string { return static::ID; }
    public function group(): string { return static::GROUP; }
    public function severity(): int { return static::SEVERITY; }
    public function title(): string { return static::TITLE; }
    public function why(): string { return static::WHY; }

    public function fix(string $status): string
    {
        if ($status === Result::FAIL) {
            return static::FIX_FAIL;
        }
        if ($status === Result::WARN) {
            return static::FIX_WARN !== '' ? static::FIX_WARN : static::FIX_FAIL;
        }
        return '';
    }
}
