<?php
namespace HaloAudit\Tests;

use HaloAudit\PageFacts;
use HaloAudit\Text;
use PHPUnit\Framework\TestCase;

final class PageFactsTest extends TestCase
{
    private const URL = 'https://example.com/courses/mba/';

    private function page(string $head, string $body): PageFacts
    {
        return PageFacts::fromHtml("<!doctype html><html lang=\"en\"><head>$head</head><body>$body</body></html>", self::URL);
    }

    public function test_title_with_attributes_and_newlines(): void
    {
        $p = $this->page("<title data-x=\"1\">\n  MBA   Programme \n</title>", '');
        $this->assertSame('MBA Programme', $p->title());
    }

    public function test_missing_title_is_null(): void
    {
        $this->assertNull($this->page('', '<svg><title>icon</title></svg>')->title());
    }

    public function test_meta_and_property_are_case_insensitive(): void
    {
        $p = $this->page('<meta NAME="Description" content=" Hello "><meta property="og:title" content="OG">', '');
        $this->assertSame('Hello', $p->meta('description'));
        $this->assertSame('OG', $p->property('og:title'));
        $this->assertNull($p->meta('robots'));
    }

    public function test_headings_are_in_document_order(): void
    {
        $p = $this->page('', '<h2>B</h2><h1>A</h1><h4>D</h4>');
        $this->assertSame([2, 1, 4], array_column($p->headings(), 'level'));
        $this->assertSame('A', $p->headings()[1]['text']);
    }

    public function test_canonical_is_made_absolute(): void
    {
        $p = $this->page('<link rel="canonical" href="/courses/mba/">', '');
        $this->assertSame('https://example.com/courses/mba/', $p->canonical());
    }

    public function test_links_skip_non_pages_and_flag_internal(): void
    {
        $p = $this->page('', '<a href="#top">t</a><a href="mailto:a@b.c">m</a><a href="javascript:void(0)">j</a>'
            . '<a href="/apply">Apply now</a><a href="https://www.example.com/x">X</a><a href="https://other.org/">O</a>');
        $links = $p->links();
        $this->assertCount(3, $links);
        $this->assertSame('https://example.com/apply', $links[0]['href']);
        $this->assertSame('Apply now', $links[0]['text']);
        $this->assertTrue($links[1]['internal']);
        $this->assertFalse($links[2]['internal']);
    }

    public function test_images_report_alt_attribute_presence(): void
    {
        $p = $this->page('', '<img src="a.png" alt=""><img src="b.png">');
        $this->assertSame([true, false], array_column($p->images(), 'has_alt'));
    }

    public function test_json_ld_types_expand_graph_and_count_invalid(): void
    {
        $p = $this->page(
            '<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Organization"},{"@type":["WebPage","FAQPage"]}]}</script>'
            . '<script type="application/ld+json">{not json}</script>',
            ''
        );
        $ld = $p->jsonLd();
        $this->assertSame(2, $ld['blocks']);
        $this->assertSame(1, $ld['invalid']);
        $this->assertEqualsCanonicalizing(['Organization', 'WebPage', 'FAQPage'], $ld['types']);
        $this->assertEqualsCanonicalizing(['Organization', 'WebPage', 'FAQPage'], $ld['top_types']);
    }

    public function test_top_types_leave_out_nested_values(): void
    {
        $p = $this->page(
            '<script type="application/ld+json">{"@type":"WebSite","potentialAction":{"@type":"SearchAction"},"publisher":{"@type":"Organization"}}</script>'
            . '<script type="application/ld+json">[{"@type":"Course","provider":{"@type":"CollegeOrUniversity"}},{"@graph":[{"@type":"Event"}]}]</script>',
            ''
        );
        $ld = $p->jsonLd();
        $this->assertEqualsCanonicalizing(['WebSite', 'Course', 'Event'], $ld['top_types']);
        $this->assertEqualsCanonicalizing(['WebSite', 'SearchAction', 'Organization', 'Course', 'CollegeOrUniversity', 'Event'], $ld['types']);
    }

    public function test_visible_text_drops_script_and_style_and_keeps_word_breaks(): void
    {
        $p = $this->page('', '<p>One</p><p>two</p><script>var hidden = 1;</script><style>.x{}</style><noscript>nope</noscript>');
        $this->assertSame('One two', $p->visibleText());
        $this->assertSame(2, $p->wordCount());
    }

    public function test_non_latin_text_is_counted(): void
    {
        $p = $this->page('<title>प्रवेश</title>', '<p>विश्वविद्यालय प्रवेश सूचना</p>');
        $this->assertSame(3, $p->wordCount());
        $this->assertTrue(Text::has($p->visibleText(), 'प्रवेश'));
    }

    public function test_windows_1252_page_is_parsed(): void
    {
        $html = "<html><head><title>Caf\xE9 MBA</title></head><body><p>Fees are \xA35000</p></body></html>";
        $p = PageFacts::fromHtml($html, self::URL);
        $this->assertSame('Café MBA', $p->title());
        $this->assertSame(3, $p->wordCount());
    }

    public function test_empty_document_does_not_error(): void
    {
        $p = PageFacts::fromHtml('', self::URL);
        $this->assertNull($p->title());
        $this->assertSame(0, $p->wordCount());
        $this->assertSame([], $p->links());
    }

    public function test_has_count_and_feed_links(): void
    {
        $p = $this->page('<link rel="alternate" type="application/rss+xml" href="/feed/">', '<main><details></details><details></details></main>');
        $this->assertTrue($p->has('main'));
        $this->assertFalse($p->has('article'));
        $this->assertSame(2, $p->count('details'));
        $this->assertSame(['https://example.com/feed/'], $p->feedLinks());
    }

    public function test_text_has_is_case_insensitive_and_rejects_empty(): void
    {
        $this->assertTrue(Text::has("Best  MBA\nProgramme", 'mba programme'));
        $this->assertFalse(Text::has('anything', '  '));
    }
}
