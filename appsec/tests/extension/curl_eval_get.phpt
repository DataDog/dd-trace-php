--TEST--
Downstream curl requests are evaluated natively: GET, with and without bodies
--DESCRIPTION--
One WAF subcontext per transfer hop, named curl-<transfer>-<hop>: the request
as known before it is sent (W1: URL, method, Host, from the recorded options,
one call and no other request-stage call), then the response status and
headers and, for the first transfer, the body, in one response call that is the
last one of the subcontext. The first transfer is picked for body analysis
(rate 1, at most one per request); the second is not, so its response call
carries no body.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,root_span_get_metrics};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 7, 'ok'));
var_dump(rinit());

$ch = curl_init("$base/json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(curl_exec($ch));
$ch = curl_init("$base/json?second=1");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(curl_exec($ch));

var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
var_dump(root_span_get_metrics()['_dd.appsec.downstream_request'] ?? null);
?>
--EXPECT--
bool(true)
string(22) "{"status":"ok","n":42}"
string(22) "{"status":"ok","n":42}"
bool(true)
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
curl-1-0 last {"server.io.net.response.body":{"n":42,"status":"ok"},"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
curl-2-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json?second=1"}
curl-2-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
2 subcontext(s), all closed
float(1)
