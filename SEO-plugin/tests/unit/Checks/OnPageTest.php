<?php
namespace HaloAudit\Tests\Checks;

use HaloAudit\Checks\OnPage;
use HaloAudit\Response;
use HaloAudit\Tests\Support\Make;
use PHPUnit\Framework\TestCase;

final class OnPageTest extends TestCase
{
    /** @dataProvider cases */
    public function test_status(string $class, string $head, string $body, string $expected, string $keyword = 'mba'): void
    {
        $result = (new $class())->run(Make::ctx(Make::page($head, $body), null, $keyword));
        $this->assertSame($expected, $result->status, $class . ' evidence: ' . $result->evidence);
    }

    public function cases(): array
    {
        $t = static fn(int $n): string => '<title>' . str_repeat('a', $n) . '</title>';
        $d = static fn(int $n): string => '<meta name="description" content="' . str_repeat('a', $n) . '">';
        $imgs = static fn(int $with, int $without): string => str_repeat('<img src="a" alt="x">', $with) . str_repeat('<img src="b">', $without);

        return [
            'title 55' => [OnPage\TitleLength::class, $t(55), '', 'pass'],
            'title 50' => [OnPage\TitleLength::class, $t(50), '', 'pass'],
            'title 60' => [OnPage\TitleLength::class, $t(60), '', 'pass'],
            'title 49' => [OnPage\TitleLength::class, $t(49), '', 'warn'],
            'title 30' => [OnPage\TitleLength::class, $t(30), '', 'warn'],
            'title 65' => [OnPage\TitleLength::class, $t(65), '', 'warn'],
            'title 29' => [OnPage\TitleLength::class, $t(29), '', 'fail'],
            'title 66' => [OnPage\TitleLength::class, $t(66), '', 'fail'],
            'title none' => [OnPage\TitleLength::class, '', '', 'fail'],

            'kw title yes' => [OnPage\KeywordInTitle::class, '<title>Best MBA Programme</title>', '', 'pass'],
            'kw title no' => [OnPage\KeywordInTitle::class, '<title>Home</title>', '', 'fail'],
            'kw title blank kw' => [OnPage\KeywordInTitle::class, '<title>Home</title>', '', 'not_measured', ' '],

            'kw h1 yes' => [OnPage\KeywordInH1::class, '', '<h1>Our mba</h1>', 'pass'],
            'kw h1 no' => [OnPage\KeywordInH1::class, '', '<h1>Welcome</h1><h2>MBA</h2>', 'fail'],

            'kw url yes' => [OnPage\KeywordInUrl::class, '', '', 'pass'],
            'kw url multiword' => [OnPage\KeywordInUrl::class, '', '', 'pass', 'MBA courses'],
            'kw url no' => [OnPage\KeywordInUrl::class, '', '', 'fail', 'law degree'],

            'desc 150' => [OnPage\MetaDescription::class, $d(150), '', 'pass'],
            'desc 140' => [OnPage\MetaDescription::class, $d(140), '', 'pass'],
            'desc 160' => [OnPage\MetaDescription::class, $d(160), '', 'pass'],
            'desc 139' => [OnPage\MetaDescription::class, $d(139), '', 'warn'],
            'desc 70' => [OnPage\MetaDescription::class, $d(70), '', 'warn'],
            'desc 69' => [OnPage\MetaDescription::class, $d(69), '', 'fail'],
            'desc 161' => [OnPage\MetaDescription::class, $d(161), '', 'fail'],
            'desc none' => [OnPage\MetaDescription::class, '', '', 'fail'],

            'h1 one' => [OnPage\SingleH1::class, '', '<h1>A</h1>', 'pass'],
            'h1 none' => [OnPage\SingleH1::class, '', '<h2>A</h2>', 'fail'],
            'h1 two' => [OnPage\SingleH1::class, '', '<h1>A</h1><h1>B</h1>', 'fail'],

            'order ok' => [OnPage\HeadingOrder::class, '', '<h1>A</h1><h2>B</h2><h3>C</h3><h2>D</h2>', 'pass'],
            'order skip' => [OnPage\HeadingOrder::class, '', '<h1>A</h1><h3>C</h3>', 'warn'],
            'order none' => [OnPage\HeadingOrder::class, '', '<p>x</p>', 'pass'],

            'canonical self' => [OnPage\Canonical::class, '<link rel="canonical" href="https://example.com/courses/mba">', '', 'pass'],
            'canonical other' => [OnPage\Canonical::class, '<link rel="canonical" href="https://example.com/courses/">', '', 'warn'],
            'canonical none' => [OnPage\Canonical::class, '', '', 'fail'],

            'noindex clean' => [OnPage\Noindex::class, '<meta name="robots" content="index, follow">', '', 'pass'],
            'noindex meta' => [OnPage\Noindex::class, '<meta name="robots" content="NOINDEX, follow">', '', 'fail'],

            'words 300' => [OnPage\WordCount::class, '', Make::words(300), 'pass'],
            'words 299' => [OnPage\WordCount::class, '', Make::words(299), 'warn'],
            'words 200' => [OnPage\WordCount::class, '', Make::words(200), 'warn'],
            'words 199' => [OnPage\WordCount::class, '', Make::words(199), 'fail'],

            'alt all' => [OnPage\ImageAlt::class, '', $imgs(5, 0), 'pass'],
            'alt none on page' => [OnPage\ImageAlt::class, '', '<p>x</p>', 'pass'],
            'alt 80' => [OnPage\ImageAlt::class, '', $imgs(4, 1), 'warn'],
            'alt 60' => [OnPage\ImageAlt::class, '', $imgs(3, 2), 'fail'],

            'links 2' => [OnPage\InternalLinks::class, '', '<a href="/a">a</a><a href="/b">b</a>', 'pass'],
            'links 1' => [OnPage\InternalLinks::class, '', '<a href="/a">a</a><a href="https://other.org">o</a>', 'warn'],
            'links 0' => [OnPage\InternalLinks::class, '', '<a href="mailto:a@b.c">m</a><a href="#x">x</a>', 'fail'],
        ];
    }

    public function test_noindex_in_response_header_fails(): void
    {
        $site = Make::site();
        $site->page = Response::make(200, '', ['X-Robots-Tag' => 'noindex']);
        $result = (new OnPage\Noindex())->run(Make::ctx(Make::page(), $site));
        $this->assertSame('fail', $result->status);
    }

    public function test_every_check_has_copy(): void
    {
        foreach (glob(dirname(__DIR__, 3) . '/includes/engine/Checks/OnPage/*.php') as $file) {
            $class = 'HaloAudit\\Checks\\OnPage\\' . basename($file, '.php');
            $check = new $class();
            $this->assertSame('onpage', $check->group(), $class);
            $this->assertNotSame('', $check->id(), $class);
            $this->assertNotSame('', $check->title(), $class);
            $this->assertNotSame('', $check->why(), $class);
            $this->assertNotSame('', $check->fix('fail'), $class);
            $this->assertStringNotContainsString('—', $check->why() . $check->fix('fail') . $check->fix('warn'), $class);
        }
    }
}
