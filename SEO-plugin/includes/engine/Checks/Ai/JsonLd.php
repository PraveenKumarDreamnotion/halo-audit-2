<?php
namespace HaloAudit\Checks\Ai;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class JsonLd extends BaseCheck
{
    public const ID = 'json_ld';
    public const GROUP = 'ai';
    public const SEVERITY = 2;
    public const TITLE = 'Structured data';
    public const WHY = 'Structured data states facts about your page in a form that search engines and AI assistants read directly.';
    public const FIX_WARN = 'Add JSON-LD structured data that describes your organisation and this page.';
    public const FIX_FAIL = 'One or more structured data blocks contain a syntax error and are ignored. Correct the JSON.';

    public function run(Context $c): Result
    {
        $ld = $c->page->jsonLd();
        if ($ld['invalid'] > 0) {
            return Result::fail($ld['invalid'] . ' of ' . $ld['blocks'] . ($ld['blocks'] === 1 ? ' block cannot' : ' blocks cannot') . ' be read');
        }
        return $ld['blocks'] === 0 ? Result::warn('No JSON-LD found') : Result::pass($ld['blocks'] . ($ld['blocks'] === 1 ? ' valid block' : ' valid blocks'));
    }
}
