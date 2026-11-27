--TEST--
Downstream curl requests: a handle with the app's CURLOPT_PREREQFUNCTION is skipped, even if the curl_setopt_array() that set it failed
--DESCRIPTION--
A handle whose app set its own CURLOPT_PREREQFUNCTION (PHP 8.4+) is skipped
entirely (skip reason prereqfunction, G5.13): no WAF call, no replacement
or removal of the callback, which keeps being called for every transfer.
curl_setopt_array() applies its options in order and stops at the first one
ext/curl rejects; the callback was set before that one, and is recorded.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq("calling the app's PREREQFUNCTION");
if (!defined('CURLOPT_PREREQFUNCTION')) die('skip PHP 8.4+');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 20, 'ok'));

function attempt($label, $url) {
    $calls = 0;
    $ch = curl_init($url);
    $ok = curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_PREREQFUNCTION => function ($ch, $ip, $lip, $port, $lport) use (&$calls) {
            $calls++;
            return CURL_PREREQFUNC_OK;
        },
        CURLOPT_SSLVERSION => 1000, // libcurl rejects it
    ]);
    curl_exec($ch);
    $first = $calls;
    curl_exec($ch);
    echo "$label: setopt_array: ", var_export($ok, true),
        ", prereq calls: first exec $first, second exec ", $calls - $first,
        ", reason: ",
        var_export(datadog\appsec\testing\curl_downstream_dump($ch)['reason'], true), "\n";
}

attempt('not evaluated', "$base/json");
var_dump(rinit());
attempt('evaluated', "$base/json");
var_dump(rshutdown());
?>
--EXPECT--
not evaluated: setopt_array: false, prereq calls: first exec 1, second exec 1, reason: 'prereqfunction'
bool(true)
evaluated: setopt_array: false, prereq calls: first exec 1, second exec 1, reason: 'prereqfunction'
bool(true)
