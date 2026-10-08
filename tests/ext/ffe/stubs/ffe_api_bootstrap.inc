<?php

// run-tests.php can point TEST_PHP_SRCDIR at PHP itself (not this extension).
// Match the configured source layout used by Windows and packaged/PECL tests.
$root = getenv('TEST_PHP_SRCDIR') ?: dirname(__DIR__, 4);
$srcRoot = rtrim((string) ini_get('datadog.trace.sources_path'), '/\\');
foreach (array($srcRoot, $root . '/src', dirname(__DIR__, 4) . '/src') as $candidate) {
    if (is_dir($candidate . '/api')) {
        $srcRoot = $candidate;
        break;
    }
}
if (!is_dir($srcRoot . '/api')) {
    $installed = stream_resolve_include_path('datadog_trace/src/api/Log/LoggerInterface.php');
    if ($installed !== false) $srcRoot = dirname($installed, 3);
}
spl_autoload_register(function ($class) use ($srcRoot) {
    if (strpos($class, 'DDTrace\\') === 0) {
        $suffix = str_replace('\\', '/', substr($class, 8)) . '.php';
        $path = $srcRoot . '/api/' . $suffix;
        if (!is_file($path)) $path = $srcRoot . '/DDTrace/' . $suffix;
        if (is_file($path)) require_once $path;
    }
});
