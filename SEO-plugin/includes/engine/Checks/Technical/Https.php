<?php
namespace HaloAudit\Checks\Technical;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class Https extends BaseCheck
{
    public const ID = 'https';
    public const GROUP = 'technical';
    public const SEVERITY = 3;
    public const TITLE = 'Secure connection';
    public const WHY = 'Browsers warn visitors away from pages that do not use HTTPS.';
    public const FIX_FAIL = 'Serve the page over HTTPS and redirect the HTTP address to it.';

    public function run(Context $c): Result
    {
        $final = $c->site->page->finalUrl !== '' ? $c->site->page->finalUrl : $c->url;
        return stripos($final, 'https://') === 0 ? Result::pass('Served over HTTPS') : Result::fail('Served over HTTP');
    }
}
