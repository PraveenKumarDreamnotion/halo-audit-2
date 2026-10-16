<?php
namespace HaloAudit\Checks\OnPage;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class HeadingOrder extends BaseCheck
{
    public const ID = 'heading_order';
    public const GROUP = 'onpage';
    public const SEVERITY = 1;
    public const TITLE = 'Heading order';
    public const WHY = 'Headings that go down one level at a time give the page a clear outline.';
    public const FIX_WARN = 'Do not skip heading levels. Put an H2 before an H3, and an H3 before an H4.';
    public const FIX_FAIL = 'Do not skip heading levels. Put an H2 before an H3, and an H3 before an H4.';

    public function run(Context $c): Result
    {
        $skips = 0;
        $previous = 0;
        foreach ($c->page->headings() as $heading) {
            if ($previous > 0 && $heading['level'] > $previous + 1) {
                $skips++;
            }
            $previous = $heading['level'];
        }
        return $skips === 0 ? Result::pass('No skipped levels') : Result::warn($skips . ' skipped level(s)');
    }
}
