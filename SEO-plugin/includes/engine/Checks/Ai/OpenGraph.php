<?php
namespace HaloAudit\Checks\Ai;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class OpenGraph extends BaseCheck
{
    public const ID = 'open_graph';
    public const GROUP = 'ai';
    public const SEVERITY = 2;
    public const TITLE = 'Open Graph tags';
    public const WHY = 'Open Graph tags control the title, text and image shown when the page is shared or previewed.';
    public const FIX_FAIL = 'Add the missing Open Graph tags. A page needs og:title, og:description, og:image and og:url.';

    private const REQUIRED = ['og:title', 'og:description', 'og:image', 'og:url'];

    public function run(Context $c): Result
    {
        $missing = array_values(array_filter(self::REQUIRED, static fn(string $p): bool => $c->page->property($p) === null));
        return $missing === [] ? Result::pass('All four tags present') : Result::fail('Missing: ' . implode(', ', $missing));
    }
}
