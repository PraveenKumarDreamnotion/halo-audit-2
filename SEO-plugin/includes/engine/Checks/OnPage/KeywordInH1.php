<?php
namespace HaloAudit\Checks\OnPage;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;
use HaloAudit\Text;

final class KeywordInH1 extends BaseCheck
{
    public const ID = 'keyword_in_h1';
    public const GROUP = 'onpage';
    public const SEVERITY = 2;
    public const TITLE = 'Keyword in the main heading';
    public const WHY = 'The main heading tells readers and search engines the topic of the page.';
    public const FIX_FAIL = 'Use your keyword in the H1 heading of the page.';

    public function run(Context $c): Result
    {
        if (trim($c->keyword) === '') {
            return Result::notMeasured('No keyword given');
        }
        foreach ($c->page->headings() as $heading) {
            if ($heading['level'] === 1 && Text::has($heading['text'], $c->keyword)) {
                return Result::pass('Found in the H1');
            }
        }
        return Result::fail('Not found in any H1');
    }
}
