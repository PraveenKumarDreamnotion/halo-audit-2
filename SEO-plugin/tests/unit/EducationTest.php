<?php
namespace HaloAudit\Tests;

use HaloAudit\Checks\Education as Checks;
use HaloAudit\Education;
use HaloAudit\Tests\Support\Make;
use PHPUnit\Framework\TestCase;

final class EducationTest extends TestCase
{
    /** @dataProvider detection */
    public function test_detect(string $host, array $types, bool $expected): void
    {
        $this->assertSame($expected, Education::detect($host, $types));
    }

    public function detection(): array
    {
        return [
            ['mit.edu', [], true],
            ['www.ashoka.edu.in', [], true],
            ['ox.ac.uk', [], true],
            ['example.com', ['CollegeOrUniversity'], true],
            ['example.com', ['School'], true],
            ['example.com', ['Organization'], false],
            ['education.example.com', [], false],
            ['acme.academy', [], false],
        ];
    }

    private function status(string $class, string $head = '', string $body = '', array $site = []): string
    {
        return (new $class())->run(Make::ctx(Make::page($head, $body), Make::site($site)))->status;
    }

    public function test_apply_link(): void
    {
        $this->assertSame('pass', $this->status(Checks\ApplyLink::class, '', '<a href="/x">Apply Now</a>'));
        $this->assertSame('pass', $this->status(Checks\ApplyLink::class, '', '<a href="/admissions/">Start here</a>'));
        $this->assertSame('warn', $this->status(Checks\ApplyLink::class, '', '<a href="/about">About</a><a href="/applied-sciences">Applied Sciences</a>'));
    }

    public function test_fee_visible(): void
    {
        $this->assertSame('pass', $this->status(Checks\FeeVisible::class, '', '<p>Tuition fee: ₹ 4,50,000 per year</p>'));
        $this->assertSame('pass', $this->status(Checks\FeeVisible::class, '', '<p>$12,000 annual tuition</p>'));
        $this->assertSame('pass', $this->status(Checks\FeeVisible::class, '', '<p>Fees: Rs. 90000</p>'));
        $this->assertSame('warn', $this->status(Checks\FeeVisible::class, '', '<p>Contact us for fee details.</p>'));
        $this->assertSame('warn', $this->status(Checks\FeeVisible::class, '', '<p>We raised $2,000,000 for research.</p>'));
    }

    public function test_education_schema(): void
    {
        $ld = static fn(string $type): string => '<script type="application/ld+json">{"@type":"' . $type . '"}</script>';
        $this->assertSame('pass', $this->status(Checks\EducationSchema::class, $ld('Course')));
        $this->assertSame('pass', $this->status(Checks\EducationSchema::class, $ld('CollegeOrUniversity')));
        $this->assertSame('warn', $this->status(Checks\EducationSchema::class, $ld('Organization')));
    }

    /** @dataProvider freshness */
    public function test_sitemap_freshness(?int $daysAgo, string $expected, string $bucket): void
    {
        $site = ['sitemapStatus' => 200, 'sitemapValid' => true, 'sitemapNewest' => $daysAgo === null ? null : Make::NOW - $daysAgo * 86400];
        $result = (new Checks\SitemapFreshness())->run(Make::ctx(Make::page(), Make::site($site)));
        $this->assertSame($expected, $result->status);
        $this->assertStringContainsString($bucket, $result->evidence);
    }

    public function freshness(): array
    {
        return [
            [3, 'pass', 'ACTIVE'], [14, 'pass', 'ACTIVE'], [15, 'pass', 'MODERATE'], [90, 'pass', 'MODERATE'],
            [91, 'warn', 'STALE'], [365, 'warn', 'STALE'], [366, 'warn', 'DORMANT'], [null, 'not_measured', 'UNKNOWN'],
        ];
    }

    public function test_every_extra_is_in_the_education_group_with_copy(): void
    {
        foreach ([Checks\ApplyLink::class, Checks\FeeVisible::class, Checks\EducationSchema::class, Checks\SitemapFreshness::class] as $class) {
            $check = new $class();
            $this->assertSame('education', $check->group());
            $this->assertNotSame('', $check->title());
            $this->assertNotSame('', $check->why());
            $this->assertNotSame('', $check->fix('warn'));
        }
    }
}
