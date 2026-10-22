<?php
namespace HaloAudit\Checks\OnPage;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class InternalLinks extends BaseCheck
{
    public const ID = 'internal_links';
    public const GROUP = 'onpage';
    public const SEVERITY = 1;
    public const TITLE = 'Links to your other pages';
    public const WHY = 'Links between your pages help visitors and search engines find related content.';
    public const FIX_WARN = 'Add at least one more link to a related page on your site.';
    public const FIX_FAIL = 'Add links to at least two related pages on your site.';

    public function run(Context $c): Result
    {
        $n = count(array_filter($c->page->links(), static fn(array $l): bool => $l['internal']));
        $evidence = $n . ($n === 1 ? ' internal link' : ' internal links');
        if ($n >= 2) {
            return Result::pass($evidence);
        }
        return $n === 1 ? Result::warn($evidence) : Result::fail($evidence);
    }
}
