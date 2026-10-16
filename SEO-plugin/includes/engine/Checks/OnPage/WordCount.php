<?php
namespace HaloAudit\Checks\OnPage;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class WordCount extends BaseCheck
{
    public const ID = 'word_count';
    public const GROUP = 'onpage';
    public const SEVERITY = 2;
    public const TITLE = 'Amount of content';
    public const WHY = 'A page with very little text gives search engines little to rank.';
    public const FIX_WARN = 'Add more useful content. Aim for at least 300 words that answer what a visitor wants to know.';
    public const FIX_FAIL = 'This page has very little text. Add at least 300 words that answer what a visitor wants to know.';

    public function run(Context $c): Result
    {
        $n = $c->page->wordCount();
        $evidence = $n . ' words';
        if ($n >= 300) {
            return Result::pass($evidence);
        }
        return $n >= 200 ? Result::warn($evidence) : Result::fail($evidence);
    }
}
