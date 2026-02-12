<?php

/**
 * Lightweight bootstrap for running FeatureFlags tests without the ddtrace extension.
 * The main tests/phpunit.xml bootstrap requires the extension; this one doesn't.
 * Usage: php vendor/bin/phpunit --bootstrap tests/FeatureFlags/bootstrap.php tests/FeatureFlags/LRUCacheTest.php
 */

error_reporting(E_ALL);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$phpunitVersionParts = explode('.', \PHPUnit\Runner\Version::id());
define('PHPUNIT_MAJOR', intval($phpunitVersionParts[0]));

if (PHPUNIT_MAJOR >= 8) {
    require dirname(__DIR__) . '/Common/MultiPHPUnitVersionAdapter_typed.php';
} else {
    require dirname(__DIR__) . '/Common/MultiPHPUnitVersionAdapter_untyped.php';
}

// Stub dd_trace_internal_fn if the extension is not loaded
if (!function_exists('dd_trace_internal_fn')) {
    function dd_trace_internal_fn() { return false; }
}
