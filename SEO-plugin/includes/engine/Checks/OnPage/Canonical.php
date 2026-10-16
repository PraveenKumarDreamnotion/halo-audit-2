<?php
namespace HaloAudit\Checks\OnPage;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;
use HaloAudit\Url;

final class Canonical extends BaseCheck
{
    public const ID = 'canonical';
    public const GROUP = 'onpage';
    public const SEVERITY = 2;
    public const TITLE = 'Canonical tag';
    public const WHY = 'The canonical tag tells search engines which address is the true one for this page.';
    public const FIX_WARN = 'The canonical tag points to another address. Confirm that this is intended. If it is not, point it to this page.';
    public const FIX_FAIL = 'Add a canonical tag that points to the full address of this page.';

    public function run(Context $c): Result
    {
        $canonical = $c->page->canonical();
        if ($canonical === null) {
            return Result::fail('No canonical tag found');
        }
        return Url::sameUrl($canonical, $c->url) ? Result::pass($canonical) : Result::warn('Points to ' . $canonical);
    }
}
