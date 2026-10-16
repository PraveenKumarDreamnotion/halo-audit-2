<?php
namespace HaloAudit\Checks\OnPage;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;
use HaloAudit\Url;

final class KeywordInUrl extends BaseCheck
{
    public const ID = 'keyword_in_url';
    public const GROUP = 'onpage';
    public const SEVERITY = 1;
    public const TITLE = 'Keyword in the address';
    public const WHY = 'A readable address that names the topic helps people and search engines.';
    public const FIX_FAIL = 'For new pages, use the keyword words in the address. Do not rename a page that already ranks without a redirect.';

    public function run(Context $c): Result
    {
        $words = array_values(array_filter((array) preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($c->keyword))));
        if ($words === []) {
            return Result::notMeasured('No keyword given');
        }
        $haystack = mb_strtolower(rawurldecode(Url::host($c->url) . Url::path($c->url)));
        foreach ($words as $word) {
            if (mb_strpos($haystack, $word) === false) {
                return Result::fail('"' . $word . '" is not in the address');
            }
        }
        return Result::pass('All keyword words are in the address');
    }
}
