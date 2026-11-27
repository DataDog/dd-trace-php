--TEST--
Downstream curl requests: CURLOPT_CUSTOMREQUEST is the W1 method, verbatim
--DESCRIPTION--
G7.2: for hop 0 the method is the recorded CURLOPT_CUSTOMREQUEST as it is
(no case change; an empty string is an empty method) and wins over the
method derived from the other options. With a POSTFIELDS body the body is
still evaluated.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=100
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 12, 'ok'), false);
var_dump(rinit());
ce_execs($helper); // request_init

$cases = [
    'lowercase' => ['delete', null],
    'empty' => ['', null],
    'with body' => ['PATCH', '{"a":1}'],
];
foreach ($cases as $label => list($custom, $body)) {
    $ch = curl_init("$base/echo");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $custom);
    curl_exec($ch);
    $w1 = ce_execs($helper)[0][2];
    echo $label, ': ', json_encode([
        'method' => $w1['server.io.net.request.method'] ?? null,
        'body' => $w1['server.io.net.request.body'] ?? null,
    ]), "\n";
}
var_dump(rshutdown());
?>
--EXPECT--
bool(true)
lowercase: {"method":"delete","body":null}
empty: {"method":"","body":null}
with body: {"method":"PATCH","body":{"a":1}}
bool(true)
