<?php
namespace HaloAudit\Tests\Checks;

use HaloAudit\Checks\Ai;
use HaloAudit\RobotsTxt;
use HaloAudit\Tests\Support\Make;
use PHPUnit\Framework\TestCase;

final class AiTest extends TestCase
{
    private function status(string $class, string $head = '', string $body = '', array $site = []): string
    {
        return (new $class())->run(Make::ctx(Make::page($head, $body), Make::site($site)))->status;
    }

    private static function ld(string $json): string
    {
        return '<script type="application/ld+json">' . $json . '</script>';
    }

    /** @dataProvider crawlers */
    public function test_ai_crawlers(?int $status, ?string $robots, string $expected): void
    {
        $site = ['robotsStatus' => $status, 'robots' => $robots === null ? null : RobotsTxt::parse($robots)];
        $this->assertSame($expected, $this->status(Ai\AiCrawlers::class, '', '', $site));
    }

    public function crawlers(): array
    {
        return [
            'fetch failed' => [null, null, 'not_measured'],
            'server error' => [503, null, 'not_measured'],
            'no robots file' => [404, null, 'pass'],
            'all allowed' => [200, "User-agent: *\nDisallow: /wp-admin/", 'pass'],
            'no star group' => [200, "User-agent: Bingbot\nDisallow: /", 'pass'],
            'one blocked' => [200, "User-agent: GPTBot\nDisallow: /", 'warn'],
            'two blocked' => [200, "User-agent: GPTBot\nUser-agent: CCBot\nDisallow: /", 'warn'],
            'three blocked' => [200, "User-agent: GPTBot\nUser-agent: CCBot\nUser-agent: ClaudeBot\nDisallow: /", 'fail'],
            'star blocks all' => [200, "User-agent: *\nDisallow: /", 'fail'],
            'only this path blocked' => [200, "User-agent: GPTBot\nDisallow: /courses/", 'warn'],
            'other path blocked' => [200, "User-agent: GPTBot\nDisallow: /private/", 'pass'],
            // RFC 9309: a 4xx robots.txt (even 401 or 403) means no rules. Rate limits and redirects stay unknown.
            'forbidden robots' => [403, null, 'pass'],
            'unauthorised robots' => [401, null, 'pass'],
            'gone robots' => [410, null, 'pass'],
            'rate limited robots' => [429, null, 'not_measured'],
            'redirect with no address' => [302, null, 'not_measured'],
            // Design 3.2: "Disallow: /" for "*" fails even when a few AI crawlers have their own Allow group.
            'star blocks all, one AI crawler allowed' => [200, "User-agent: *\nDisallow: /\n\nUser-agent: GPTBot\nAllow: /", 'fail'],
            'star blocks all, six AI crawlers allowed' => [200, "User-agent: *\nDisallow: /\n\n" . self::groups(['GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'PerplexityBot', 'Google-Extended']), 'fail'],
            // Every AI crawler has its own group, so the "*" group binds none of them.
            'star blocks all, every AI crawler allowed' => [200, "User-agent: *\nDisallow: /\n\n" . self::groups(Ai\AiCrawlers::AGENTS), 'pass'],
            'star blocks only a folder' => [200, "User-agent: *\nDisallow: /private/", 'pass'],
        ];
    }

    private static function groups(array $agents): string
    {
        return implode("\n", array_map(static fn(string $a): string => "User-agent: $a", $agents)) . "\nAllow: /";
    }

    /** @dataProvider llms */
    public function test_llms_txt(?int $status, string $body, string $expected): void
    {
        $this->assertSame($expected, $this->status(Ai\LlmsTxt::class, '', '', ['llmsStatus' => $status, 'llmsBody' => $body]));
    }

    public function llms(): array
    {
        return [
            'good' => [200, "# Example\n> Summary", 'pass'],
            'bom then heading' => [200, "\xEF\xBB\xBF# Example", 'pass'],
            'no heading' => [200, 'Just some text', 'warn'],
            'html soft 404' => [200, '<!DOCTYPE html><html><body>Not found</body></html>', 'fail'],
            'missing' => [404, '', 'fail'],
            'gone' => [410, '', 'fail'],
            'fetch failed' => [null, '', 'not_measured'],
            // Bot walls, rate limits and server errors are not evidence that the file is missing.
            'forbidden' => [403, '', 'not_measured'],
            'unauthorised' => [401, '', 'not_measured'],
            'rate limited' => [429, '', 'not_measured'],
            'server error' => [500, '', 'not_measured'],
            'unavailable' => [503, '', 'not_measured'],
            'redirect with no address' => [301, '', 'not_measured'],
        ];
    }

    public function test_json_ld(): void
    {
        $this->assertSame('pass', $this->status(Ai\JsonLd::class, self::ld('{"@type":"Organization"}')));
        $this->assertSame('warn', $this->status(Ai\JsonLd::class));
        $this->assertSame('fail', $this->status(Ai\JsonLd::class, self::ld('{"@type":"Organization"}') . self::ld('{oops')));
    }

    public function test_schema_types(): void
    {
        $this->assertSame('pass', $this->status(Ai\SchemaTypes::class, self::ld('{"@graph":[{"@type":"Organization"},{"@type":"Course"}]}')));
        $this->assertSame('warn', $this->status(Ai\SchemaTypes::class, self::ld('{"@type":"WebSite"}')));
        $this->assertSame('warn', $this->status(Ai\SchemaTypes::class, self::ld('{"@type":"Article"}')));
        $this->assertSame('fail', $this->status(Ai\SchemaTypes::class));
    }

    public function test_nested_helper_types_are_not_a_page_type(): void
    {
        // WebSite plus its own SearchAction/EntryPoint: still only the site half.
        $search = '{"@type":"WebSite","potentialAction":{"@type":"SearchAction","target":{"@type":"EntryPoint","urlTemplate":"https://example.com/?s={q}"}}}';
        $this->assertSame('warn', $this->status(Ai\SchemaTypes::class, self::ld($search)));
        // A top-level breadcrumb trail and a logo are helpers, not the kind of page.
        $helpers = '{"@graph":[{"@type":"Organization","logo":{"@type":"ImageObject"}},{"@type":"BreadcrumbList","itemListElement":[{"@type":"ListItem"}]}]}';
        $this->assertSame('warn', $this->status(Ai\SchemaTypes::class, self::ld($helpers)));
        // A page type nested inside another value does not count either.
        $nested = '{"@type":"Organization","subjectOf":{"@type":"Article"}}';
        $this->assertSame('warn', $this->status(Ai\SchemaTypes::class, self::ld($nested)));
        // Helpers alone are no schema types at all.
        $this->assertSame('fail', $this->status(Ai\SchemaTypes::class, self::ld('{"@type":"BreadcrumbList"}')));
    }

    public function test_nested_publisher_still_counts_as_the_site_type(): void
    {
        $article = '{"@type":"Article","publisher":{"@type":"Organization","name":"Example"}}';
        $this->assertSame('pass', $this->status(Ai\SchemaTypes::class, self::ld($article)));
        $yoast = '{"@context":"https://schema.org","@graph":[{"@type":"WebPage"},{"@type":"ImageObject"},{"@type":"BreadcrumbList"},{"@type":"WebSite","potentialAction":[{"@type":"SearchAction"}]},{"@type":"Organization"}]}';
        $this->assertSame('pass', $this->status(Ai\SchemaTypes::class, self::ld($yoast)));
        // Several top-level blocks in one array.
        $this->assertSame('pass', $this->status(Ai\SchemaTypes::class, self::ld('[{"@type":"Organization"},{"@type":"Product"}]')));
    }

    public function test_faq_schema(): void
    {
        $details = str_repeat('<details><summary>Q?</summary>A</details>', 5);
        $this->assertSame('pass', $this->status(Ai\FaqSchema::class, '', '<p>No questions here</p>'));
        $this->assertSame('pass', $this->status(Ai\FaqSchema::class, self::ld('{"@type":"FAQPage"}'), $details));
        $this->assertSame('fail', $this->status(Ai\FaqSchema::class, '', $details));
        $this->assertSame('warn', $this->status(Ai\FaqSchema::class, '', '<h2>Frequently Asked Questions</h2>'));
        $this->assertSame('warn', $this->status(Ai\FaqSchema::class, '', '<h2>FAQs</h2><details></details>'));
    }

    /** No FAQ content stays a Pass (scored like any pass), and the evidence says why no schema is needed. */
    public function test_faq_schema_without_faq_content_is_a_pass_that_says_why(): void
    {
        $result = (new Ai\FaqSchema())->run(Make::ctx(Make::page('', '<p>No questions here</p>'), Make::site([])));
        $this->assertSame('pass', $result->status);
        $this->assertSame('No FAQ content on this page, so no FAQ schema is needed', $result->evidence);

        $row = ['id' => Ai\FaqSchema::ID, 'group' => Ai\FaqSchema::GROUP, 'severity' => Ai\FaqSchema::SEVERITY, 'status' => $result->status];
        $this->assertSame(100, \HaloAudit\Scorer::score([$row])['groups']['ai']['score']);
    }

    public function test_semantic_html(): void
    {
        $this->assertSame('pass', $this->status(Ai\SemanticHtml::class, '', '<header></header><nav></nav><main><article></article></main><footer></footer>'));
        $this->assertSame('pass', $this->status(Ai\SemanticHtml::class, '', '<header></header><nav></nav><main></main><footer></footer>'));
        $this->assertSame('warn', $this->status(Ai\SemanticHtml::class, '', '<header></header><footer></footer>'));
        $this->assertSame('fail', $this->status(Ai\SemanticHtml::class, '', '<div><nav></nav></div>'));
    }

    public function test_open_graph_and_twitter(): void
    {
        $og = '<meta property="og:title" content="t"><meta property="og:description" content="d"><meta property="og:image" content="/i.png"><meta property="og:url" content="https://example.com/">';
        $this->assertSame('pass', $this->status(Ai\OpenGraph::class, $og));
        $this->assertSame('fail', $this->status(Ai\OpenGraph::class, '<meta property="og:title" content="t">'));
        $this->assertSame('pass', $this->status(Ai\TwitterCard::class, '<meta name="twitter:card" content="summary_large_image">'));
        $this->assertSame('warn', $this->status(Ai\TwitterCard::class));
    }

    /** @dataProvider ogImages */
    public function test_og_image(bool $declared, ?array $probe, string $expected): void
    {
        $head = $declared ? '<meta property="og:image" content="/i.png">' : '';
        $this->assertSame($expected, $this->status(Ai\OgImage::class, $head, '', ['ogImage' => $probe]));
    }

    public function ogImages(): array
    {
        return [
            'not declared' => [false, null, 'fail'],
            'fetch failed' => [true, null, 'not_measured'],
            'broken' => [true, ['status' => 404, 'width' => null, 'height' => null], 'fail'],
            'right size' => [true, ['status' => 200, 'width' => 1200, 'height' => 630], 'pass'],
            'other size' => [true, ['status' => 200, 'width' => 800, 'height' => 800], 'warn'],
            'size unknown' => [true, ['status' => 200, 'width' => null, 'height' => null], 'warn'],
        ];
    }

    public function test_feed(): void
    {
        $this->assertSame('pass', $this->status(Ai\Feed::class, '', '', ['feedFound' => true]));
        $this->assertSame('warn', $this->status(Ai\Feed::class, '', '', ['feedFound' => false]));
        $this->assertSame('not_measured', $this->status(Ai\Feed::class, '', '', ['feedFound' => null]));
    }

    public function test_every_check_has_copy(): void
    {
        foreach (glob(dirname(__DIR__, 3) . '/includes/engine/Checks/Ai/*.php') as $file) {
            $class = 'HaloAudit\\Checks\\Ai\\' . basename($file, '.php');
            $check = new $class();
            $this->assertSame('ai', $check->group(), $class);
            $this->assertNotSame('', $check->id() . '', $class);
            $this->assertNotSame('', $check->title(), $class);
            $this->assertNotSame('', $check->why(), $class);
            $this->assertNotSame('', $check->fix('warn') . $check->fix('fail'), $class);
            $this->assertStringNotContainsString('—', $check->why() . $check->fix('fail') . $check->fix('warn'), $class);
        }
    }
}
