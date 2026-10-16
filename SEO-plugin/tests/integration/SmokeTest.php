<?php
class Halo_Smoke_Test extends WP_UnitTestCase
{
    public function test_plugin_is_loaded(): void
    {
        $this->assertTrue(defined('SEO_ANALYZER_VERSION'));
    }
}
