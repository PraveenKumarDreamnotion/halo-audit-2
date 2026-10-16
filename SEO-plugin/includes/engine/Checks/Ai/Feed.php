<?php
namespace HaloAudit\Checks\Ai;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class Feed extends BaseCheck
{
    public const ID = 'feed';
    public const GROUP = 'ai';
    public const SEVERITY = 1;
    public const TITLE = 'RSS or Atom feed';
    public const WHY = 'A feed lets crawlers and AI tools pick up your new content quickly.';
    public const FIX_WARN = 'Publish an RSS or Atom feed and link to it from the page head.';

    public function run(Context $c): Result
    {
        if ($c->site->feedFound === null) {
            return Result::notMeasured('The feed addresses could not all be checked');
        }
        return $c->site->feedFound ? Result::pass('A feed was found') : Result::warn('No feed was found');
    }
}
