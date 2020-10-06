<?php

error_reporting(E_ALL);

// it has to be more complex than this actually: https://phpunit.de/supported-versions.html
if (PHP_MAJOR_VERSION === 8) {
    require __DIR__ . '/Common/MultiPHPUnitVersionAdapter_8.php';
} else {
    require __DIR__ . '/Common/MultiPHPUnitVersionAdapter_5_7.php';
}

require __DIR__ . '/bootstrap_utils.php';

\DDTrace\Tests\prepend_test_autoloaders();
