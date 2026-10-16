<?php
namespace HaloAudit\Checks\OnPage;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class MetaDescription extends BaseCheck
{
    public const ID = 'meta_description';
    public const GROUP = 'onpage';
    public const SEVERITY = 2;
    public const TITLE = 'Meta description';
    public const WHY = 'The description is often the text shown under your title in search results.';
    public const FIX_WARN = 'Lengthen the meta description to between 140 and 160 characters.';
    public const FIX_FAIL = 'Write a meta description of 140 to 160 characters that says what the page offers.';

    public function run(Context $c): Result
    {
        $description = $c->page->meta('description');
        if ($description === null) {
            return Result::fail('No meta description found');
        }
        $n = mb_strlen($description);
        $evidence = $n . ' characters';
        if ($n >= 140 && $n <= 160) {
            return Result::pass($evidence);
        }
        if ($n >= 70 && $n < 140) {
            return Result::warn($evidence);
        }
        return Result::fail($evidence);
    }
}
