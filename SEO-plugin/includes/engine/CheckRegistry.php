<?php
namespace HaloAudit;

use HaloAudit\Checks\Ai;
use HaloAudit\Checks\Education as Edu;
use HaloAudit\Checks\OnPage;
use HaloAudit\Checks\Speed;
use HaloAudit\Checks\Technical;

/** The ordered list of checks. To add a check: one new class file, one new line here. */
final class CheckRegistry
{
    /** @return Check[] */
    public static function all(): array
    {
        return array_merge([
            new OnPage\TitleLength(),
            new OnPage\KeywordInTitle(),
            new OnPage\MetaDescription(),
            new OnPage\SingleH1(),
            new OnPage\KeywordInH1(),
            new OnPage\HeadingOrder(),
            new OnPage\KeywordInUrl(),
            new OnPage\Canonical(),
            new OnPage\Noindex(),
            new OnPage\WordCount(),
            new OnPage\ImageAlt(),
            new OnPage\InternalLinks(),

            new Technical\Https(),
            new Technical\Certificate(),
            new Technical\RedirectChain(),
            new Technical\RobotsReachable(),
            new Technical\Sitemap(),
            new Technical\SecurityHeaders(),
            new Technical\StagingLeaks(),
            new Technical\JsRendering(),

            new Ai\AiCrawlers(),
            new Ai\LlmsTxt(),
            new Ai\JsonLd(),
            new Ai\SchemaTypes(),
            new Ai\FaqSchema(),
            new Ai\SemanticHtml(),
            new Ai\OpenGraph(),
            new Ai\TwitterCard(),
            new Ai\OgImage(),
            new Ai\Feed(),
        ], self::speed(), [
            new Edu\ApplyLink(),
            new Edu\FeeVisible(),
            new Edu\EducationSchema(),
            new Edu\SitemapFreshness(),
        ]);
    }

    /** @return Check[] */
    public static function speed(): array
    {
        return [new Speed\PsiScore(), new Speed\Lcp(), new Speed\Cls()];
    }
}
