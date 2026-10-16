<?php
namespace HaloAudit\Checks\OnPage;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class SingleH1 extends BaseCheck
{
    public const ID = 'single_h1';
    public const GROUP = 'onpage';
    public const SEVERITY = 3;
    public const TITLE = 'One main heading';
    public const WHY = 'A page needs exactly one H1 so that its main topic is clear.';
    public const FIX_FAIL = 'Use exactly one H1 heading on the page. Change any others to H2.';

    public function run(Context $c): Result
    {
        $count = count(array_filter($c->page->headings(), static fn(array $h): bool => $h['level'] === 1));
        return $count === 1 ? Result::pass('1 H1 heading') : Result::fail($count . ' H1 headings');
    }
}
