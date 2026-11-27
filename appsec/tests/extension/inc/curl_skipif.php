<?php
// Shared --SKIPIF-- of the curl tests: Linux, a supported CPU architecture,
// ext/curl, the testing functions and the native layer enabled. Use it as
//
//   --SKIPIF--
//   <?php include __DIR__ . '/inc/curl_skipif.php';
//
// Tests that run with an unavailable or reduced layer include
// curl_skipif_testing.php instead. Every skip states its reason.
include __DIR__ . '/curl_skipif_testing.php';
if (!extension_loaded('curl')) {
    die('skip ext/curl not loaded');
}
$info = \datadog\appsec\testing\curl_linkage_info();
if (!$info['enabled']) {
    die('skip native layer disabled: ' . $info['reason']);
}
