--TEST--
Downstream curl requests are evaluated natively: POST with a JSON body, sampled and not
--DESCRIPTION--
CURLOPT_POSTFIELDS, CURLOPT_HTTPHEADER and CURLOPT_COOKIE make up the request
evaluated before it is sent (W1, the only request-stage call of the hop); the
body (sampled transfer only) is parsed as JSON. Only one
transfer per request is sampled (DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=1):
the second sends no bodies. A third transfer posts a text/plain string, which
gives no body (G7.6; it does not use up the budget). The response of the test
server is text/plain, which gives no response body (only JSON and XML do,
G7.8).
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
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 9, 'ok'));
var_dump(rinit());
foreach (['sampled', 'not sampled'] as $label) {
    $ch = curl_init("$base/echo");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => '{"a":[1,2],"b":"x"}',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Custom: one', 'x-custom: two', 'X-Empty:'],
        CURLOPT_COOKIE => 'c=1',
    ]);
    var_dump(curl_exec($ch));
}
$ch = curl_init("$base/echo");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => 'plain text',
    CURLOPT_HTTPHEADER => ['Content-Type: text/plain'],
]);
var_dump(curl_exec($ch));
var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
?>
--EXPECTF--
bool(true)
string(19) "{"a":[1,2],"b":"x"}"
string(19) "{"a":[1,2],"b":"x"}"
string(10) "plain text"
bool(true)
curl-1-0 {"server.io.net.request.body":{"a":[1,2],"b":"x"},"server.io.net.request.headers":{"content-type":["application/json"],"cookie":["c=1"],"host":["127.0.0.1:PORT"],"x-custom":["one","two"]},"server.io.net.request.method":"POST","server.io.net.url":"http://127.0.0.1:PORT/echo"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["19"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
curl-2-0 {"server.io.net.request.headers":{"content-type":["application/json"],"cookie":["c=1"],"host":["127.0.0.1:PORT"],"x-custom":["one","two"]},"server.io.net.request.method":"POST","server.io.net.url":"http://127.0.0.1:PORT/echo"}
curl-2-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["19"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
curl-3-0 {"server.io.net.request.headers":{"content-type":["text/plain"],"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"POST","server.io.net.url":"http://127.0.0.1:PORT/echo"}
curl-3-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["10"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
3 subcontext(s), all closed
