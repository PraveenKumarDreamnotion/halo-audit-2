<?php
namespace HaloAudit\Checks\Ai;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class TwitterCard extends BaseCheck
{
    public const ID = 'twitter_card';
    public const GROUP = 'ai';
    public const SEVERITY = 1;
    public const TITLE = 'Twitter card tag';
    public const WHY = 'The twitter:card tag gives the page a large preview on X and in several chat apps.';
    public const FIX_WARN = 'Add <meta name="twitter:card" content="summary_large_image"> to the page head.';

    public function run(Context $c): Result
    {
        $card = $c->page->meta('twitter:card') ?? $c->page->property('twitter:card');
        return $card !== null ? Result::pass($card) : Result::warn('No twitter:card tag');
    }
}
