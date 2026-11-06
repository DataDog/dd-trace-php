<?php

// PHPUnit suites run from the repository, unlike packaged extension tests.
$root = dirname(__DIR__, 3);
$srcRoot = $root . '/src';
spl_autoload_register(function ($class) use ($srcRoot) {
    if (strpos($class, 'DDTrace\\') === 0) {
        $suffix = str_replace('\\', '/', substr($class, 8)) . '.php';
        $path = $srcRoot . '/api/' . $suffix;
        if (!is_file($path)) $path = $srcRoot . '/DDTrace/' . $suffix;
        if (is_file($path)) require_once $path;
    }
});
