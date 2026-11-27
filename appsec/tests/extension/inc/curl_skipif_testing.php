<?php
// Variant of curl_skipif.php for the curl tests that do not need the native
// layer: the platform (Linux, CPU architecture) and the testing functions.
// Every skip says why.
if (PHP_OS !== 'Linux') {
    die('skip Linux only');
}
if (!in_array(php_uname('m'), ['x86_64', 'aarch64', 'arm64'], true)) {
    die('skip CPU architecture ' . php_uname('m') . ' is not x86_64/aarch64');
}
if (!function_exists('datadog\appsec\testing\curl_linkage_info')) {
    die('skip appsec testing functions not available (DD_APPSEC_TESTING)');
}
