<?php

error_reporting(E_ALL);

if (PHP_MAJOR_VERSION === 8) {
    require __DIR__ . '/Common/MultiPHPUnitVersionAdapter_8.php';
} else {
    require __DIR__ . '/Common/MultiPHPUnitVersionAdapter_5_7.php';
}

require __DIR__ . '/bootstrap_utils.php';

\DDTrace\Tests\prepend_test_autoloaders();
