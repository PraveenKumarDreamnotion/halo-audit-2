<?php
namespace HaloAudit\Tests;

use HaloAudit\CheckRegistry;
use PHPUnit\Framework\TestCase;

final class CheckRegistryTest extends TestCase
{
    public function test_has_37_checks_with_unique_ids(): void
    {
        $ids = array_map(static fn($c) => $c->id(), CheckRegistry::all());
        $this->assertCount(37, $ids);
        $this->assertSame($ids, array_unique($ids));
    }

    public function test_group_sizes_match_the_spec(): void
    {
        $counts = array_count_values(array_map(static fn($c) => $c->group(), CheckRegistry::all()));
        $this->assertSame(['onpage' => 12, 'technical' => 8, 'ai' => 10, 'speed' => 3, 'education' => 4], $counts);
    }

    public function test_every_check_file_is_registered(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/includes/engine/Checks/*/*.php');
        $this->assertCount(count(CheckRegistry::all()), $files);
    }

    public function test_severities_are_one_to_three(): void
    {
        foreach (CheckRegistry::all() as $check) {
            $this->assertContains($check->severity(), [1, 2, 3], $check->id());
        }
    }
}
