<?php
namespace HaloAudit\Checks\Speed;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class Lcp extends BaseCheck
{
    public const ID = 'lcp';
    public const GROUP = 'speed';
    public const SEVERITY = 2;
    public const TITLE = 'Time until the main content shows';
    public const WHY = 'Largest Contentful Paint measures how long a visitor waits to see the main part of the page.';
    public const FIX_WARN = 'Bring the main content in under 2.5 seconds. Preload the main image and reduce server response time.';
    public const FIX_FAIL = 'The main content takes more than 4 seconds to show. Shrink the main image, preload it, and reduce server response time.';

    public function run(Context $c): Result
    {
        if ($c->site->psi === null) {
            return Result::notMeasured('Google PageSpeed did not return a result');
        }
        $ms = $c->site->psi['lcp_ms'];
        $evidence = number_format($ms / 1000, 1) . ' seconds';
        if ($ms < 2500) {
            return Result::pass($evidence);
        }
        return $ms <= 4000 ? Result::warn($evidence) : Result::fail($evidence);
    }
}
