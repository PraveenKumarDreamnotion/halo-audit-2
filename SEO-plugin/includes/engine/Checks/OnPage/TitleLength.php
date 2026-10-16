<?php
namespace HaloAudit\Checks\OnPage;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class TitleLength extends BaseCheck
{
    public const ID = 'title_length';
    public const GROUP = 'onpage';
    public const SEVERITY = 3;
    public const TITLE = 'Title length';
    public const WHY = 'Search results show about 60 characters of a title. A longer title is cut off and a very short one says too little.';
    public const FIX_WARN = 'Rewrite the title so that it is between 50 and 60 characters.';
    public const FIX_FAIL = 'Give the page a title of 50 to 60 characters that names its topic.';

    public function run(Context $c): Result
    {
        $title = $c->page->title();
        if ($title === null) {
            return Result::fail('No title tag found');
        }
        $n = mb_strlen($title);
        $evidence = $n . ' characters';
        if ($n >= 50 && $n <= 60) {
            return Result::pass($evidence);
        }
        if (($n >= 30 && $n < 50) || ($n > 60 && $n <= 65)) {
            return Result::warn($evidence);
        }
        return Result::fail($evidence);
    }
}
