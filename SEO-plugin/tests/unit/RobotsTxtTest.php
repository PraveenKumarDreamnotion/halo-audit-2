<?php
namespace HaloAudit\Tests;

use HaloAudit\RobotsTxt;
use PHPUnit\Framework\TestCase;

final class RobotsTxtTest extends TestCase
{
    public function test_empty_file_allows_everything(): void
    {
        $this->assertTrue(RobotsTxt::parse('')->isAllowed('GPTBot', '/'));
    }

    public function test_file_without_star_group_allows_unnamed_agents(): void
    {
        $r = RobotsTxt::parse("User-agent: Bingbot\nDisallow: /");
        $this->assertTrue($r->isAllowed('GPTBot', '/page'));
        $this->assertFalse($r->isAllowed('bingbot', '/page'));
    }

    public function test_named_group_is_reported_and_star_is_not_a_name(): void
    {
        $r = RobotsTxt::parse("User-agent: *\nDisallow: /\n\nUser-agent: gptbot\nAllow: /");
        $this->assertTrue($r->hasNamedGroup('GPTBot'));
        $this->assertFalse($r->hasNamedGroup('ClaudeBot'));
        $this->assertFalse($r->hasNamedGroup('*'));
        $this->assertFalse($r->isAllowed('*', '/'));
        $this->assertFalse($r->isAllowed('ClaudeBot', '/page'));
        $this->assertTrue($r->isAllowed('GPTBot', '/page'));
    }

    public function test_agent_match_is_case_insensitive(): void
    {
        $r = RobotsTxt::parse("user-agent: gptbot\ndisallow: /");
        $this->assertFalse($r->isAllowed('GPTBot', '/'));
    }

    public function test_grouped_user_agents_share_rules(): void
    {
        $r = RobotsTxt::parse("User-agent: GPTBot\nUser-agent: ClaudeBot\nDisallow: /private/\n\nUser-agent: *\nDisallow:");
        $this->assertFalse($r->isAllowed('ClaudeBot', '/private/x'));
        $this->assertTrue($r->isAllowed('ClaudeBot', '/public'));
        $this->assertTrue($r->isAllowed('CCBot', '/private/x'));
    }

    public function test_named_group_wins_over_star(): void
    {
        $r = RobotsTxt::parse("User-agent: *\nDisallow: /\n\nUser-agent: GPTBot\nAllow: /");
        $this->assertTrue($r->isAllowed('GPTBot', '/a'));
        $this->assertFalse($r->isAllowed('PerplexityBot', '/a'));
    }

    public function test_longest_match_wins_and_allow_wins_ties(): void
    {
        $r = RobotsTxt::parse("User-agent: *\nDisallow: /courses/\nAllow: /courses/mba/\nDisallow: /tie\nAllow: /tie");
        $this->assertTrue($r->isAllowed('GPTBot', '/courses/mba/'));
        $this->assertFalse($r->isAllowed('GPTBot', '/courses/bba/'));
        $this->assertTrue($r->isAllowed('GPTBot', '/tie'));
    }

    public function test_wildcards_and_end_anchor(): void
    {
        $r = RobotsTxt::parse("User-agent: *\nDisallow: /*.pdf$\nDisallow: /search*q=");
        $this->assertFalse($r->isAllowed('GPTBot', '/files/a.pdf'));
        $this->assertTrue($r->isAllowed('GPTBot', '/files/a.pdf.html'));
        $this->assertFalse($r->isAllowed('GPTBot', '/search?x=1&q=2'));
    }

    public function test_comments_blank_lines_and_crlf(): void
    {
        $r = RobotsTxt::parse("# hello\r\nUser-agent: * # everyone\r\n\r\nDisallow: /x # no\r\n");
        $this->assertFalse($r->isAllowed('GPTBot', '/x'));
    }

    public function test_sitemaps_are_collected(): void
    {
        $r = RobotsTxt::parse("Sitemap: https://example.com/sitemap.xml\nUser-agent: *\nDisallow:\nsitemap: https://example.com/news.xml");
        $this->assertSame(['https://example.com/sitemap.xml', 'https://example.com/news.xml'], $r->sitemaps());
    }
}
