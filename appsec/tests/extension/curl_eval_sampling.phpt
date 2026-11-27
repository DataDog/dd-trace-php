--TEST--
Downstream curl requests: one sampling decision per transfer, taken at its start
--DESCRIPTION--
The sampler counter ticks once per transfer, when the transfer starts, whatever
its bodies: a GET with a gzip response, one with an empty response, a POST and
a redirect chain (two hops) are one tick each. A transfer sampled that ends up
sending no body gives its slot back (G8.11), so with a limit of 1 the POST
that follows the two bodiless GETs is the one whose bodies are sent, and the
POST after that one is not sampled anymore.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('W2 and the response call');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,root_span_get_metrics,downstream_sampler_set_counter,downstream_sampler_get_counter};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 16, 'ok'));
var_dump(rinit());
downstream_sampler_set_counter(100);
foreach (['/gzip', '/status/204'] as $path) {
    $ch = curl_init("$base$path");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    var_dump(strlen(curl_exec($ch)));
    var_dump(downstream_sampler_get_counter());
}
foreach (['sampled', 'not sampled'] as $label) {
    $ch = curl_init("$base/echo");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => '{"a":1}',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    var_dump(curl_exec($ch));
    var_dump(downstream_sampler_get_counter());
}
$ch = curl_init("$base/redirect");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true]);
var_dump(strlen(curl_exec($ch)));
var_dump(downstream_sampler_get_counter());
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
int(101)
int(0)
int(102)
string(7) "{"a":1}"
int(103)
string(7) "{"a":1}"
int(104)
int(22)
int(105)
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
curl-5-0: server.io.net.request.headers,server.io.net.request.method,server.io.net.url
curl-5-0: server.io.net.response.headers,server.io.net.response.status
curl-5-1: server.io.net.request.method,server.io.net.url
curl-5-1: server.io.net.response.headers,server.io.net.response.status
6 subcontext(s), all closed
