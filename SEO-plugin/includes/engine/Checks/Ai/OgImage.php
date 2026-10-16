<?php
namespace HaloAudit\Checks\Ai;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class OgImage extends BaseCheck
{
    public const ID = 'og_image';
    public const GROUP = 'ai';
    public const SEVERITY = 1;
    public const TITLE = 'Share image';
    public const WHY = 'A broken or wrongly sized share image makes the page look bad when it is shared.';
    public const FIX_WARN = 'Use a share image of 1200 by 630 pixels.';
    public const FIX_FAIL = 'Set og:image to an image address that loads. Use an image of 1200 by 630 pixels.';

    public function run(Context $c): Result
    {
        if ($c->page->property('og:image') === null) {
            return Result::fail('No og:image tag');
        }
        $image = $c->site->ogImage;
        if ($image === null) {
            return Result::notMeasured('The image could not be fetched');
        }
        if ($image['status'] >= 400) {
            return Result::fail('The image address returns HTTP ' . $image['status']);
        }
        if ($image['width'] === 1200 && $image['height'] === 630) {
            return Result::pass('1200 by 630 pixels');
        }
        $size = $image['width'] === null ? 'size unknown' : $image['width'] . ' by ' . $image['height'] . ' pixels';
        return Result::warn('The image loads, ' . $size);
    }
}
