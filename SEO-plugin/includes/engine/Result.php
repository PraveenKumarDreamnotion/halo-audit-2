<?php
namespace HaloAudit;

final class Result
{
    public const PASS = 'pass';
    public const WARN = 'warn';
    public const FAIL = 'fail';
    public const NOT_MEASURED = 'not_measured';

    public string $status;
    public string $evidence;

    private function __construct(string $status, string $evidence)
    {
        $this->status = $status;
        $this->evidence = $evidence;
    }

    public static function pass(string $evidence = ''): self { return new self(self::PASS, $evidence); }
    public static function warn(string $evidence = ''): self { return new self(self::WARN, $evidence); }
    public static function fail(string $evidence = ''): self { return new self(self::FAIL, $evidence); }
    public static function notMeasured(string $evidence = ''): self { return new self(self::NOT_MEASURED, $evidence); }
}
