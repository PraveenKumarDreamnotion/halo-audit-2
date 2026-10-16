<?php
$plugin_dir = dirname(__DIR__, 2);
$tests_dir  = getenv('WP_TESTS_DIR') ?: '/wordpress-phpunit';

define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', $plugin_dir . '/vendor/yoast/phpunit-polyfills');

require_once $tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function () use ($plugin_dir) {
    require $plugin_dir . '/seo-analyzer.php';
});

require $tests_dir . '/includes/bootstrap.php';
