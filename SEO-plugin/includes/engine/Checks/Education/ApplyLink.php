<?php
namespace HaloAudit\Checks\Education;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class ApplyLink extends BaseCheck
{
    public const ID = 'apply_link';
    public const GROUP = 'education';
    public const SEVERITY = 2;
    public const TITLE = 'Apply link on this page';
    public const WHY = 'A student who is ready to apply must not have to search for the way in.';
    public const FIX_WARN = 'Add a clear "Apply" or "Admissions" link to this page, near the top.';

    private const PATTERN = '/\b(apply|admissions?|enrol|enroll|enrolment|enrollment)\b/i';

    public function run(Context $c): Result
    {
        foreach ($c->page->links() as $link) {
            $slug = str_replace(['-', '_', '/'], ' ', (string) parse_url($link['href'], PHP_URL_PATH));
            if (preg_match(self::PATTERN, $link['text']) || preg_match(self::PATTERN, $slug)) {
                return Result::pass('Found: "' . ($link['text'] !== '' ? $link['text'] : $link['href']) . '"');
            }
        }
        return Result::warn('No apply or admissions link on this page');
    }
}
