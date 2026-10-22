<?php
namespace HaloAudit\Checks\Education;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class SitemapFreshness extends BaseCheck
{
    public const ID = 'sitemap_freshness';
    public const GROUP = 'education';
    public const SEVERITY = 1;
    public const TITLE = 'How recently the site was updated';
    public const WHY = 'Admissions information changes every cycle. A site with no recent updates looks out of date to students and to search engines.';
    public const FIX_WARN = 'The sitemap shows no update in the last 90 days. Publish current admissions dates, fees and news.';

    public function run(Context $c): Result
    {
        $newest = $c->site->sitemapNewest;
        if ($newest === null) {
            return Result::notMeasured('UNKNOWN: the sitemap has no last-modified dates');
        }
        $days = max(0, (int) floor(($c->now - $newest) / 86400));
        $text = 'last update ' . $days . ($days === 1 ? ' day' : ' days') . ' ago';
        if ($days <= 14) {
            return Result::pass('ACTIVE: ' . $text);
        }
        if ($days <= 90) {
            return Result::pass('MODERATE: ' . $text);
        }
        return Result::warn(($days <= 365 ? 'STALE: ' : 'DORMANT: ') . $text);
    }
}
