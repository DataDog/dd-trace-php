<?php

$apiLoadedViaComposer = \class_exists('DDTrace\ComposerBootstrap', false);

if ($apiLoadedViaComposer) {
    // Some classes from 'src/api' might have already been loaded from composer, so we cannot hard load all api classes
    // from '_files_api.php' to avoid class redefinition errors.
    // Basic 'DDTrace\\' class loader based on https://www.php-fig.org/psr/psr-4/examples/
    spl_autoload_register(function ($class) {
        // If $class is not a DDTrace class, move quickly to the next autoloader
        $prefix = 'DDTrace\\';
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            // move to the next registered autoloader
            return;
        }

        $base_dir = __DIR__ . '/../src/api/';
        $relative_class = substr($class, $len);
        // 'DDTrace\\Some\\Class.php' to '../src/api/'
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

        // if the file exists, require it
        if (file_exists($file)) {
            require $file;
        }
    });
}

if (getenv('DD_AUTOLOAD_NO_COMPILE') === 'true') {
    // Development
    if (!$apiLoadedViaComposer) {
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
    if (!$apiLoadedViaComposer) {
        require_once __DIR__ . '/_generated_api.php';
    }
    require_once __DIR__ . '/_generated_internal.php';
}
