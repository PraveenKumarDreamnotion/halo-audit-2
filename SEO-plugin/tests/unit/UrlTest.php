<?php
namespace HaloAudit\Tests;

use HaloAudit\Url;
use PHPUnit\Framework\TestCase;

final class UrlTest extends TestCase
{
    /** @dataProvider normalised */
    public function test_normalise(string $in, string $out): void
    {
        $this->assertSame($out, Url::normalise($in));
    }

    public function normalised(): array
    {
        return [
            ['  Example.com/Page ', 'https://example.com/Page'],
            ['example.com', 'https://example.com'],
            ['http://example.com/a b', 'http://example.com/a%20b'],
            ['HTTPS://EXAMPLE.COM/x', 'https://example.com/x'],
            ['', ''],
        ];
    }

    /** @dataProvider resolved */
    public function test_resolve(string $base, string $rel, string $out): void
    {
        $this->assertSame($out, Url::resolve($base, $rel));
    }

    public function resolved(): array
    {
        $b = 'https://example.com/a/b/page.html?x=1';
        return [
            [$b, 'https://other.org/z', 'https://other.org/z'],
            [$b, '//cdn.example.com/i.png', 'https://cdn.example.com/i.png'],
            [$b, '/root', 'https://example.com/root'],
            [$b, 'next', 'https://example.com/a/b/next'],
            [$b, '../up', 'https://example.com/a/up'],
            [$b, './same?q=2', 'https://example.com/a/b/same?q=2'],
            [$b, '?only=query', 'https://example.com/a/b/page.html?only=query'],
            ['https://example.com', 'x', 'https://example.com/x'],
        ];
    }

    public function test_same_site_ignores_www_and_case(): void
    {
        $this->assertTrue(Url::sameSite('https://WWW.example.com/a', 'http://example.com/b'));
        $this->assertFalse(Url::sameSite('https://blog.example.com/', 'https://example.com/'));
    }

    public function test_same_url_ignores_trailing_slash_and_host_case(): void
    {
        $this->assertTrue(Url::sameUrl('https://Example.com/a/', 'https://example.com/a'));
        $this->assertFalse(Url::sameUrl('https://example.com/a', 'https://example.com/b'));
        $this->assertFalse(Url::sameUrl('https://example.com/a?x=1', 'https://example.com/a'));
    }

    public function test_origin_and_path(): void
    {
        $this->assertSame('https://example.com', Url::origin('https://Example.com/a?b'));
        $this->assertSame('/', Url::path('https://example.com'));
        $this->assertSame('/a', Url::path('https://example.com/a?b'));
    }
}
