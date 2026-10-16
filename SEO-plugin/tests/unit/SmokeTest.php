<?php
namespace HaloAudit\Tests;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function test_autoload_namespace_is_registered(): void
    {
        $map = require dirname(__DIR__, 2) . '/vendor/composer/autoload_psr4.php';
        $this->assertArrayHasKey('HaloAudit\\', $map);
    }
}
