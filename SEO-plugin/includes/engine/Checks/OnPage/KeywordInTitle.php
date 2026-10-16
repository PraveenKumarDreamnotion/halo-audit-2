<?php
namespace HaloAudit\Checks\OnPage;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;
use HaloAudit\Text;

final class KeywordInTitle extends BaseCheck
{
    public const ID = 'keyword_in_title';
    public const GROUP = 'onpage';
    public const SEVERITY = 3;
    public const TITLE = 'Keyword in the title';
    public const WHY = 'The title is the strongest on-page signal of what a page is about.';
    public const FIX_FAIL = 'Put your keyword in the title, near the start.';

    public function run(Context $c): Result
    {
        if (trim($c->keyword) === '') {
            return Result::notMeasured('No keyword given');
        }
        $title = (string) $c->page->title();
        return Text::has($title, $c->keyword) ? Result::pass('Found in the title') : Result::fail('Not found in the title');
    }
}
