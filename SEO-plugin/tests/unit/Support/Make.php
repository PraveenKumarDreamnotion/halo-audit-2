<?php
namespace HaloAudit\Tests\Support;

use HaloAudit\Context;
use HaloAudit\PageFacts;
use HaloAudit\Response;
use HaloAudit\SiteFacts;

final class Make
{
    public const URL = 'https://example.com/courses/mba/';
    public const NOW = 1760000000; // 2025-10-09

    public static function page(string $head = '', string $body = '', string $url = self::URL): PageFacts
    {
        return PageFacts::fromHtml("<!doctype html><html lang=\"en\"><head>$head</head><body>$body</body></html>", $url);
    }

    public static function site(array $overrides = []): SiteFacts
    {
        $site = new SiteFacts();
        $site->page = Response::make(200, '', ['content-type' => 'text/html']);
        $site->page->finalUrl = self::URL;
        foreach ($overrides as $key => $value) {
            $site->$key = $value;
        }
        return $site;
    }

    public static function ctx(PageFacts $page, ?SiteFacts $site = null, string $keyword = 'mba', ?string $url = null): Context
    {
        return new Context($url ?? $page->url(), $keyword, $page, $site ?? self::site(), self::NOW);
    }

    public static function words(int $n): string
    {
        return '<p>' . implode(' ', array_fill(0, $n, 'word')) . '</p>';
    }
}
