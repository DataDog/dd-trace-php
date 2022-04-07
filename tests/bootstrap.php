<?php

error_reporting(E_ALL);

if (getenv('DD_AUTOLOAD_NO_COMPILE') == 'true' && (false !== getenv('CI') || false !== getenv('CIRCLECI'))) {
    throw new Exception('Tests must run using the _generated.php script in CI');
}

// Setting an environment variable to signal we are in a tests run
putenv('DD_TEST_EXECUTION=1');

$phpunitVersionParts = class_exists('\PHPUnit\Runner\Version')
    ? explode('.', \PHPUnit\Runner\Version::id())
    : explode('.', PHPUnit_Runner_Version::id());
define('PHPUNIT_MAJOR', intval($phpunitVersionParts[0]));

if (PHPUNIT_MAJOR >= 8) {
    require __DIR__ . '/Common/MultiPHPUnitVersionAdapter_typed.php';
} else {
    require __DIR__ . '/Common/MultiPHPUnitVersionAdapter_untyped.php';
}

$skipAppSetup = (bool) \getenv('DD_SKIP_APP_SETUP');

if ($skipAppSetup) {
    error_log('Composer install and before-run script is disabled for this run. ' .
        'Set DD_SKIP_APP_SETUP=0 to run app setup instead.');
} else {
    error_log('Composer install and before-run script are run before every test case. ' .
        'Set DD_SKIP_APP_SETUP=1 to skip app setup instead.');
}
