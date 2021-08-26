<?php

// The autoloading mechanism of the tracing library is complex and has to support different use cases:
//     - applications using or not using composer;
//     - applications using or not using manual instrumentation;
//     - applications using `DDTrace\*` classes in the `opcache.preload` script;
//     - applications defining 'terminal' autoloaders that trigger an error if no class was found.
//
// Most of the complexity comes from the following facts:
//     - some `DDTrace` classes are defined internally by the extension, some are defined in userland but not autoloaded
//       by composer and meant for internal use, while some others represent the public api and are both required by the
//       internal userland classes and loaded by composer;
//     - automatic instrumentation runs before it is known that composer exists, and after any `opcache.preload` script;


// Do not trigger the autoloading mechanism if the class is not defined, so 'terminal' autoloaders - that trigger
// errors if a class was not found - are supported.
//   - Class `DDTrace\ComposerBootstrap` is declared in `src/api/bootstrap.composer.php` and it is loaded when the
//     Composer's `vendor/autoload.php` file is required by the application.
//   - It is declared in the `files` section of the composer file, not as a `psr4` autoloader, so it is loaded always,
//     even when not explicitly required.
//   - The existence of this class during `RINIT` means that all the following conditions are met:
//       1. `opcache.preload` script was executed;
//       2. `opcache.preload` script used composer;
//       3. composer requires `datadog/dd-trace`.
//   - A class definition is used, instead of a constant, because constants defined in the `opcache.preload` script
//     are not visible while the autoload script is loaded during `RINIT`.
$apiLoadedViaComposer = \class_exists('DDTrace\ComposerBootstrap', false);

if ($apiLoadedViaComposer) {
    // Basic 'DDTrace\\' class loader based on https://www.php-fig.org/psr/psr-4/examples/.
    // A `psr4` class loader is used in place of loading the `_generated_api.php` file described below because some
    // classes from `src/api` might have already be loaded during the execution of the `opcache.preload` script and
    // would cause a duplicate class declaration error.
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
        // If composer autoloader did not run, it is safe to hard load all api classes. This approach is used in place
        // of registering a `psr4` autoloader for performance reasons.
        // As a matter of facts, composer never kicks-in in this condition, since all the classes are already defined.
        require_once __DIR__ . '/_generated_api.php';
    }

    // File `_generated_internal.php` declares all the classes and functions meant only for internal use, and not meant
    // to be used by users for manual instrumentation.
    require_once __DIR__ . '/_generated_internal.php';
}
