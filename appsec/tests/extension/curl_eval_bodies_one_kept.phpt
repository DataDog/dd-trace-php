--TEST--
Downstream curl requests: only the response body of the last hop is kept
--DESCRIPTION--
With the transfer sampled and a JSON response body on every hop, the
collector keeps the body of the hop being received only: the body of the
redirect response is dropped once the next hop's request is about to be sent
(its PREREQ), so that one body at most is held (G7.9). The last hop's body is
sent with its response call.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('keeping the response body');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,curl_downstream_dump};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 4, 'ok'));
rinit();

$ch = curl_init("$base/redirect-to?json=1&u=" . urlencode("$base/echo-json"));
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, '{"k":"hello"}');
curl_setopt($ch, CURLOPT_POSTREDIR, 3); // POST again after the 302
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($h, $data) {
    static $seen = [];
    // the collector holds the current hop's body only; ours is appended
    // after this function returns, so at the first call of a hop nothing of
    // it is held yet. The body of the redirect (hop 0) is
    // {"r":"redirecting"}: 19 bytes.
    $d = curl_downstream_dump($h);
    $key = 'hop ' . ($d['hops'] - 1) . ': body bytes kept ' . strlen((string)$d['response']['body']);
    if (!isset($seen[$key])) {
        $seen[$key] = true;
        echo "$key\n";
    }
    return strlen($data);
});
curl_exec($ch);
rshutdown();
foreach (ce_execs($helper) as list($id, $last, $data)) {
    echo $id, $last ? ' last' : '', ' body=',
        json_encode($data['server.io.net.response.body'] ?? null), "\n";
}
?>
--EXPECT--
hop 1: body bytes kept 0
curl-1-0 body=null
curl-1-0 last body=null
curl-1-1 body=null
curl-1-1 last body={"k":"hello"}
