<?php
namespace HaloAudit\Checks\Ai;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;
use HaloAudit\SiteFacts;

final class LlmsTxt extends BaseCheck
{
    public const ID = 'llms_txt';
    public const GROUP = 'ai';
    public const SEVERITY = 1;
    public const TITLE = 'llms.txt file';
    public const WHY = 'An llms.txt file gives AI assistants a short, clean guide to your most important pages.';
    public const FIX_WARN = 'Your llms.txt file does not start with a "# Site name" heading. Follow the llms.txt format.';
    public const FIX_FAIL = 'Add an llms.txt file at the root of the site. Start it with "# Site name" and list your key pages.';

    public function run(Context $c): Result
    {
        $status = $c->site->llmsStatus;
        if (SiteFacts::unavailable($status)) {
            return Result::notMeasured($status === null ? 'The llms.txt file could not be fetched' : 'The site did not let us check llms.txt (HTTP ' . $status . ')');
        }
        if ($status !== 200) {
            return Result::fail('Not found (HTTP ' . $status . ')');
        }
        $body = ltrim((string) preg_replace('/^\xEF\xBB\xBF/', '', $c->site->llmsBody));
        if (preg_match('/^<(!doctype|html)/i', $body)) {
            return Result::fail('The address returns a web page, not a text file');
        }
        return strpos($body, '#') === 0 ? Result::pass('Found and well formed') : Result::warn('Found, but it does not start with a heading');
    }
}
