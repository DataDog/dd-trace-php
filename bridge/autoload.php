<?php

if (getenv('DD_AUTOLOAD_NO_COMPILE') === 'true') {
    // Development
    $files = include __DIR__ . '/_files.php';
    foreach ($files as $file) {
        require $file;
    }
} else {
    // Production
    require_once __DIR__ . '/_generated.php';
}
