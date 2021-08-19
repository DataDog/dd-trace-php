<?php

if (getenv('DD_AUTOLOAD_NO_COMPILE') === 'true') {
    // Development
    if (!class_exists('DDTrace\Contracts\Tracer')) {
        $apiFiles = include __DIR__ . '/_files_api.php';
        foreach ($apiFiles as $file) {
            require $file;
        }
    }
    $internalFiles = include __DIR__ . '/_files_internal.php';
    foreach ($internalFiles as $file) {
        require $file;
    }
} else {
    // Production
    if (!class_exists('DDTrace\Contracts\Tracer')) {
        require_once __DIR__ . '/_generated_api.php';
    }
    require_once __DIR__ . '/_generated_internal.php';
}
