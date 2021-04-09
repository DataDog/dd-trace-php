<?php

if (getenv('DD_AUTOLOAD_NO_COMPILE') === 'true') {
    // Development
    $files = explode("\n", file_get_contents(__DIR__ . '/_files.txt'));
    foreach ($files as $file) {
        require __DIR__ . "/../$file";
    }
} else {
    // Production
    require_once __DIR__ . '/_generated.php';
}
