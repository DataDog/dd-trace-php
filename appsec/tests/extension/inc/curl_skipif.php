<?php
// Shared --SKIPIF-- of the curl tests: Linux, a supported CPU architecture,
// ext/curl, the testing functions and the native layer enabled. Use it as
//
//   --SKIPIF--
//   <?php include __DIR__ . '/inc/curl_skipif.php';
//
// Tests that run with an unavailable or reduced layer include
// curl_skipif_testing.php instead. Every skip states its reason.
//
// A test that needs CURLOPT_PREREQFUNCTION (hops of 1 or more, W2, the
// response stage) calls curl_skipif_needs_prereq('what it needs') after the
// include: below libcurl 7.80 only the request side of hop 0 runs (G6.11,
// G10.16).
function curl_skipif_needs_prereq($what)
{
    if (\datadog\appsec\testing\curl_linkage_info()['libcurl_version_num'] < 0x075000) {
        die("skip $what: needs CURLOPT_PREREQFUNCTION (libcurl 7.80.0+)");
    }
}
include __DIR__ . '/curl_skipif_testing.php';
if (!extension_loaded('curl')) {
    die('skip ext/curl not loaded');
}
$info = \datadog\appsec\testing\curl_linkage_info();
if (!$info['enabled']) {
    die('skip native layer disabled: ' . $info['reason']);
}
if (!$info['calibration_accepted']) {
    die('skip native layer disabled: calibration refused: '
        . $info['calibration_reason']);
}
// the final verdict (G10.5): also no hooks with RASP off or without the curl
// handle type. curl_linkage_info.phpt fails instead of skipping.
if (empty($curl_skipif_hooks_optional) && !$info['layer_enabled']) {
    die('skip native layer disabled: hooks not installed (RASP off or no '
        . 'curl handle type)');
}
