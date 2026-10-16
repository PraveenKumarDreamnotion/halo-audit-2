<?php
namespace HaloAudit\Checks\OnPage;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class Noindex extends BaseCheck
{
    public const ID = 'noindex';
    public const GROUP = 'onpage';
    public const SEVERITY = 3;
    public const TITLE = 'Page can be indexed';
    public const WHY = 'A noindex instruction removes the page from search results.';
    public const FIX_FAIL = 'Remove the noindex instruction if you want this page to appear in search results.';

    public function run(Context $c): Result
    {
        $meta = strtolower((string) $c->page->meta('robots'));
        if (strpos($meta, 'noindex') !== false) {
            return Result::fail('noindex in the robots meta tag');
        }
        $header = strtolower((string) $c->site->page->header('x-robots-tag'));
        if (strpos($header, 'noindex') !== false) {
            return Result::fail('noindex in the X-Robots-Tag header');
        }
        return Result::pass('No noindex instruction');
    }
}
