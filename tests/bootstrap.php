<?php

error_reporting(E_ALL);

if (getenv('DD_AUTOLOAD_NO_COMPILE') == 'true' && (false !== getenv('CI') || false !== getenv('CIRCLECI'))) {
    throw new Exception('Running uncopiled files in CI');
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
