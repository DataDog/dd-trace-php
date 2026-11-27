--TEST--
Downstream curl requests are evaluated natively: a raw body is still bounded by the body cap
--DESCRIPTION--
G7.10: a JSON request body carried as the converter's msgpack skips the
serialiser's limits but not the cap of the body (DD_APPSEC_MAX_BODY_BUFF_SIZE):
only the first 1024 bytes are converted, so an array of 3000 elements arrives
with far fewer.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_APPSEC_MAX_BODY_BUFF_SIZE=1024
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 4, 'ok'));
rinit();
$json = json_encode(['w' => range(1000, 4000)]);
$ch = curl_init("$base/len");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
curl_exec($ch);
rshutdown();
foreach (ce_execs($helper) as $e) {
    if (isset($e[2]['server.io.net.request.body'])) {
        $n = count($e[2]['server.io.net.request.body']['w']);
        echo strlen($json) > 1024 ? "body over the cap\n" : "body too small\n";
        echo $n > 0 && $n <= 205 ? "elements bounded by the cap\n" : "elements: $n\n";
    }
}
?>
--EXPECT--
body over the cap
elements bounded by the cap
