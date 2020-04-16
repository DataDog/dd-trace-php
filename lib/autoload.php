<?php

if (getenv('PHPUNIT_TEST_NO_COMPILE') === 'true') {
    $files = include __DIR__ . '/_files.php';
    foreach ($files as $file) {
        require_once$file;
    }
} else {
    require_once __DIR__ . '/_compiled.php';
}
