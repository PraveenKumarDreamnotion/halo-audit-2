<?php
namespace HaloAudit\Checks\Technical;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class JsRendering extends BaseCheck
{
    public const ID = 'js_rendering';
    public const GROUP = 'technical';
    public const SEVERITY = 2;
    public const TITLE = 'Content without JavaScript';
    public const WHY = 'Many crawlers, and most AI crawlers, do not run JavaScript. They only read the text in the first HTML response.';
    public const FIX_WARN = 'Only a little text is in the first HTML response. Render the main content on the server.';
    public const FIX_FAIL = 'Almost no text is in the first HTML response. Render the main content on the server so that crawlers can read it.';

    public function run(Context $c): Result
    {
        $n = $c->page->wordCount();
        $evidence = $n . ' words in the first HTML response';
        if ($n >= 200) {
            return Result::pass($evidence);
        }
        return $n >= 50 ? Result::warn($evidence) : Result::fail($evidence);
    }
}
