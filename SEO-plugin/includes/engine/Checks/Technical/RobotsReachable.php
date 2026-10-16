<?php
namespace HaloAudit\Checks\Technical;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;
use HaloAudit\SiteFacts;

final class RobotsReachable extends BaseCheck
{
    public const ID = 'robots_reachable';
    public const GROUP = 'technical';
    public const SEVERITY = 1;
    public const TITLE = 'robots.txt file';
    public const WHY = 'The robots.txt file tells crawlers what they may read and where the sitemap is.';
    public const FIX_WARN = 'Add a robots.txt file at the root of the site and list your sitemap in it.';
    public const FIX_FAIL = 'Add a robots.txt file at the root of the site and list your sitemap in it.';

    public function run(Context $c): Result
    {
        $status = $c->site->robotsStatus;
        if (SiteFacts::unavailable($status)) {
            return Result::notMeasured($status === null ? 'The robots.txt file could not be fetched' : 'The site did not let us check robots.txt (HTTP ' . $status . ')');
        }
        return $status === 200 ? Result::pass('Found') : Result::warn('Not found (HTTP ' . $status . ')');
    }
}
