<?php
namespace HaloAudit\Checks\Speed;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class Cls extends BaseCheck
{
    public const ID = 'cls';
    public const GROUP = 'speed';
    public const SEVERITY = 2;
    public const TITLE = 'Layout stability';
    public const WHY = 'Cumulative Layout Shift measures how much the page jumps while it loads. Jumps cause wrong taps.';
    public const FIX_WARN = 'Set a width and height on images, embeds and ad slots so that the layout does not move.';
    public const FIX_FAIL = 'The page jumps a lot while it loads. Set a width and height on images, embeds and ad slots, and reserve space for banners.';

    public function run(Context $c): Result
    {
        if ($c->site->psi === null) {
            return Result::notMeasured('Google PageSpeed did not return a result');
        }
        $cls = $c->site->psi['cls'];
        $evidence = number_format($cls, 2);
        if ($cls < 0.1) {
            return Result::pass($evidence);
        }
        return $cls <= 0.25 ? Result::warn($evidence) : Result::fail($evidence);
    }
}
