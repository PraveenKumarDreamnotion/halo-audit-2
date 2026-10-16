<?php
namespace HaloAudit\Checks\Technical;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class SecurityHeaders extends BaseCheck
{
    public const ID = 'security_headers';
    public const GROUP = 'technical';
    public const SEVERITY = 1;
    public const TITLE = 'Security headers';
    public const WHY = 'Security headers protect visitors from common attacks such as clickjacking.';
    public const FIX_WARN = 'Ask your developer or host to add the missing security headers.';
    public const FIX_FAIL = 'Almost no security headers are set. Ask your developer or host to add them.';

    private const HEADERS = [
        'strict-transport-security', 'content-security-policy', 'x-frame-options',
        'x-content-type-options', 'referrer-policy', 'permissions-policy',
    ];

    public function run(Context $c): Result
    {
        $missing = [];
        foreach (self::HEADERS as $name) {
            if ($c->site->page->header($name) === null) {
                $missing[] = $name;
            }
        }
        $present = count(self::HEADERS) - count($missing);
        $evidence = $present . ' of 6 present' . ($missing === [] ? '' : '. Missing: ' . implode(', ', $missing));
        if ($present >= 5) {
            return Result::pass($evidence);
        }
        return $present >= 2 ? Result::warn($evidence) : Result::fail($evidence);
    }
}
