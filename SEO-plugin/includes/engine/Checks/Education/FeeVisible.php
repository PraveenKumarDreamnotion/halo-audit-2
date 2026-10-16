<?php
namespace HaloAudit\Checks\Education;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class FeeVisible extends BaseCheck
{
    public const ID = 'fee_visible';
    public const GROUP = 'education';
    public const SEVERITY = 1;
    public const TITLE = 'Fee shown on this page';
    public const WHY = 'Students and parents look for the fee early. AI assistants also quote fees that pages state plainly.';
    public const FIX_WARN = 'If this is a course or admissions page, state the fee as a number with its currency.';

    private const MONEY = '(?:₹|rs\.?|inr|\$|usd|£|gbp|€|eur)\s?\d[\d,.]*';
    private const WORD = '(?:fees?|tuition)';

    public function run(Context $c): Result
    {
        $text = $c->page->visibleText();
        $near = '[^.\n]{0,60}';
        $pattern = '/' . self::WORD . $near . self::MONEY . '|' . self::MONEY . $near . self::WORD . '/iu';
        return preg_match($pattern, $text) ? Result::pass('A fee amount is shown') : Result::warn('No fee amount was found in the page text');
    }
}
