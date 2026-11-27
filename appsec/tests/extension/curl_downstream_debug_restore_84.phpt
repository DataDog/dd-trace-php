--TEST--
Downstream curl requests: CURLOPT_DEBUGFUNCTION set while a transfer is observed
--DESCRIPTION--
PHP 8.4+ (G5.5): a CURLOPT_DEBUGFUNCTION the app sets after the transfer
started is left in place and keeps getting the request head; every transfer
is observed (a pre-send and a response call each).
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
if (PHP_VERSION_ID < 80400) die('skip PHP 8.4+: CURLOPT_DEBUGFUNCTION');
?>
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 30, 'ok'), false);
var_dump(rinit());

$calls = 0;
$debug = function ($ch, $type, $data) use (&$calls) {
    if ($type === CURLINFO_HEADER_OUT) {
        $calls++;
    }
};

echo "== multi: after curl_multi_add_handle\n";
$mh = curl_multi_init();
$ch = curl_init("$base/json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh, $ch);
curl_setopt($ch, CURLOPT_VERBOSE, true);
curl_setopt($ch, CURLOPT_DEBUGFUNCTION, $debug);
do {
    $status = curl_multi_exec($mh, $running);
    if ($running) {
        curl_multi_select($mh, 1.0);
    }
} while ($running && $status == CURLM_OK);
curl_multi_remove_handle($mh, $ch);
echo "request heads seen: $calls\n";

echo "== curl_exec: from a callback, then another curl_exec\n";
$ch = curl_init("$base/json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_NOPROGRESS, false);
$done = false;
curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function () use (&$done, &$ch, $debug) {
    if (!$done) {
        $done = true;
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        curl_setopt($ch, CURLOPT_DEBUGFUNCTION, $debug);
    }
    return 0;
});
curl_exec($ch);
$calls = 0;
curl_exec($ch);
echo "request heads seen in the 2nd transfer: $calls\n";
var_dump(rshutdown());
// 3 transfers: the pre-send and the response call each
echo count(ce_execs($helper)), " request_exec call(s)\n";
?>
--EXPECT--
bool(true)
== multi: after curl_multi_add_handle
request heads seen: 1
== curl_exec: from a callback, then another curl_exec
request heads seen in the 2nd transfer: 1
bool(true)
6 request_exec call(s)
