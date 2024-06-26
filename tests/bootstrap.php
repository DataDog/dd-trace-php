<?php

error_reporting(E_ALL);

if (getenv('DD_AUTOLOAD_NO_COMPILE') == 'true' && (false !== getenv('CI') || false !== getenv('CIRCLECI'))) {
    throw new Exception('Tests must run using the _generated.php script in CI');
}

// Setting an environment variable to signal we are in a tests run
putenv('DD_TEST_EXECUTION=1');

if (function_exists("dd_trace_env_config") && \dd_trace_env_config("DD_TRACE_SIDECAR_TRACE_SENDER")) {
    // Only explicit flushes with sidecar
    putenv("DD_TRACE_AGENT_FLUSH_INTERVAL=3000000");
}

$phpunitVersionParts = class_exists('\PHPUnit\Runner\Version')
    ? explode('.', \PHPUnit\Runner\Version::id())
    : explode('.', PHPUnit_Runner_Version::id());
define('PHPUNIT_MAJOR', intval($phpunitVersionParts[0]));

if (PHPUNIT_MAJOR >= 8) {
    require __DIR__ . '/Common/MultiPHPUnitVersionAdapter_typed.php';
} else {
    require __DIR__ . '/Common/MultiPHPUnitVersionAdapter_untyped.php';
}
