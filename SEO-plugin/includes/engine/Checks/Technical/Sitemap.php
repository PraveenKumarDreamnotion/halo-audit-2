<?php
namespace HaloAudit\Checks\Technical;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class Sitemap extends BaseCheck
{
    public const ID = 'sitemap';
    public const GROUP = 'technical';
    public const SEVERITY = 2;
    public const TITLE = 'XML sitemap';
    public const WHY = 'A sitemap helps search engines find all of your pages.';
    public const FIX_WARN = 'Publish an XML sitemap and list its address in robots.txt.';
    public const FIX_FAIL = 'Publish an XML sitemap and list its address in robots.txt.';

    public function run(Context $c): Result
    {
        if ($c->site->sitemapStatus === null) {
            return Result::notMeasured('The sitemap could not be checked: at least one address failed or was refused');
        }
        return $c->site->sitemapValid ? Result::pass('A valid XML sitemap was found') : Result::warn('No valid XML sitemap was found');
    }
}
