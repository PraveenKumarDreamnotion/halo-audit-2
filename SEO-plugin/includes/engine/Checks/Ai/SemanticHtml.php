<?php
namespace HaloAudit\Checks\Ai;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class SemanticHtml extends BaseCheck
{
    public const ID = 'semantic_html';
    public const GROUP = 'ai';
    public const SEVERITY = 1;
    public const TITLE = 'Page structure tags';
    public const WHY = 'Tags such as main, article and nav tell a machine which part of the page is the real content.';
    public const FIX_WARN = 'Use more structure tags: header, nav, main, article and footer.';
    public const FIX_FAIL = 'The page is built almost only from div tags. Wrap the parts in header, nav, main, article and footer.';

    private const TAGS = ['main', 'article', 'nav', 'header', 'footer'];

    public function run(Context $c): Result
    {
        $present = array_values(array_filter(self::TAGS, static fn(string $tag): bool => $c->page->has($tag)));
        $n = count($present);
        $evidence = $n . ' of 5 present' . ($n > 0 ? ': ' . implode(', ', $present) : '');
        if ($n >= 4) {
            return Result::pass($evidence);
        }
        return $n >= 2 ? Result::warn($evidence) : Result::fail($evidence);
    }
}
