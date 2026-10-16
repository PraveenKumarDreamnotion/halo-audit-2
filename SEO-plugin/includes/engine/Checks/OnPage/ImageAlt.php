<?php
namespace HaloAudit\Checks\OnPage;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class ImageAlt extends BaseCheck
{
    public const ID = 'image_alt';
    public const GROUP = 'onpage';
    public const SEVERITY = 1;
    public const TITLE = 'Image alt text';
    public const WHY = 'Alt text describes an image to search engines and to people who use screen readers.';
    public const FIX_WARN = 'Add an alt attribute to every image. Use alt="" for images that are decoration only.';
    public const FIX_FAIL = 'Many images have no alt attribute. Add one to every image. Use alt="" for images that are decoration only.';

    public function run(Context $c): Result
    {
        $images = $c->page->images();
        $total = count($images);
        if ($total === 0) {
            return Result::pass('No images on the page');
        }
        $with = count(array_filter($images, static fn(array $i): bool => $i['has_alt']));
        $evidence = $with . ' of ' . $total . ' images have an alt attribute';
        if ($with === $total) {
            return Result::pass($evidence);
        }
        return ($with / $total) >= 0.8 ? Result::warn($evidence) : Result::fail($evidence);
    }
}
