<?php

error_reporting(E_ALL);

// it has to be more complex than this actually: https://phpunit.de/supported-versions.html
if (in_array(PHP_MAJOR_VERSION, [7, 8])) {
    require __DIR__ . '/Common/MultiPHPUnitVersionAdapter_typed.php';
} else {
    require __DIR__ . '/Common/MultiPHPUnitVersionAdapter_untyped.php';
}

require __DIR__ . '/bootstrap_utils.php';

\DDTrace\Tests\prepend_test_autoloaders();
