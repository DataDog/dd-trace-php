--TEST--
Downstream curl requests: a sampled transfer that sends no body gives its slot back
--DESCRIPTION--
With DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=1 and a rate of 1,
every transfer is sampled at its start and takes the only slot. A GET whose
response is content-encoded, and a POST whose request body is plain text (and
so is its response), end up sending no body: each gives the slot
back, so the POST that follows has its request body analysed. The one after it
finds the slot taken (G8.11).
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,root_span_get_metrics};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 10, 'ok'));
var_dump(rinit());
$ch = curl_init("$base/gzip");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(strlen(curl_exec($ch)));
foreach (['plain', '{"a":1}', '{"b":2}'] as $body) {
    $ch = curl_init("$base/echo");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: ' .
            ($body === 'plain' ? 'text/plain' : 'application/json')],
    ]);
    var_dump(curl_exec($ch));
}
var_dump(rshutdown());
var_dump(root_span_get_metrics()['_dd.appsec.downstream_request'] ?? null);
$execs = ce_execs($helper);
foreach ($execs as list($id, $last, $data)) {
    echo $id, ': ', implode(',', array_keys($data)), "\n";
}
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
int(37)
string(5) "plain"
string(7) "{"a":1}"
string(7) "{"b":2}"
bool(true)
float(1)
curl-1-0: server.io.net.request.headers,server.io.net.request.method,server.io.net.url
curl-1-0: server.io.net.response.headers,server.io.net.response.status
curl-2-0: server.io.net.request.headers,server.io.net.request.method,server.io.net.url
curl-2-0: server.io.net.response.headers,server.io.net.response.status
curl-3-0: server.io.net.request.body,server.io.net.request.headers,server.io.net.request.method,server.io.net.url
curl-3-0: server.io.net.response.headers,server.io.net.response.status
curl-4-0: server.io.net.request.headers,server.io.net.request.method,server.io.net.url
curl-4-0: server.io.net.response.headers,server.io.net.response.status
4 subcontext(s), all closed
