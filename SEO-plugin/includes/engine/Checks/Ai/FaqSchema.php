<?php
namespace HaloAudit\Checks\Ai;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class FaqSchema extends BaseCheck
{
    public const ID = 'faq_schema';
    public const GROUP = 'ai';
    public const SEVERITY = 1;
    public const TITLE = 'FAQ schema';
    public const WHY = 'Question-and-answer content is what AI assistants quote most. FAQ schema marks it up for them.';
    public const FIX_WARN = 'This page has an FAQ section. Add FAQPage schema once it has five or more questions.';
    public const FIX_FAIL = 'This page has five or more questions and no FAQPage schema. Add FAQPage schema that matches the visible questions.';

    public function run(Context $c): Result
    {
        if (in_array('FAQPage', $c->page->jsonLd()['types'], true)) {
            return Result::pass('FAQPage schema found');
        }
        $questions = $c->page->count('details');
        if ($questions >= 5) {
            return Result::fail($questions . ' questions and no FAQPage schema');
        }
        foreach ($c->page->headings() as $heading) {
            if (preg_match('/\bfaqs?\b|frequently asked/i', $heading['text'])) {
                return Result::warn('An FAQ heading and no FAQPage schema');
            }
        }
        // Still a pass, as before: with no FAQ content there is nothing to mark up. The words now say why.
        return Result::pass('No FAQ content on this page, so no FAQ schema is needed');
    }
}
