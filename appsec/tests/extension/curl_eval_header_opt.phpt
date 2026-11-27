--TEST--
Downstream curl requests: with CURLOPT_HEADER the head bytes are not part of the response body copy
--DESCRIPTION--
With CURLOPT_HEADER=1 libcurl passes each head line to the write function as
well (before the header function). The phase rule of G5.9 drops write bytes
from the start of a hop to the blank line of its final head, so the app gets
head and body while the analysed body is the body alone (exception to G7.5).
Cases: CURLOPT_HEADER 0 (G10.20), a body with Content-Length, a chunked body, and a 1xx interim head
(Expect: 100-continue) followed by the final head. On libcurl 8.5 and later,
the trailer lines of a chunked response reach the write function after the
body, in the body phase, and end the body copy (accepted exception to G5.9);
before 8.5 they reach only the header function. That case uses a text/plain
response and the collector's dump, so the WAF calls do not depend on the
libcurl version.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif_response.php';
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=10
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,curl_downstream_dump};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 12, 'ok'));
var_dump(rinit());

function app_got($res)
{
    list($head, $body) = explode("\r\n\r\n", $res, 2);
    echo 'app: ', strtok($head, "\r\n"), ' ... body ', json_encode($body), "\n";
}

echo "== 1. Content-Length\n";
$ch = curl_init("$base/json");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]);
app_got(curl_exec($ch));

echo "== 2. chunked\n";
$ch = curl_init("$base/chunked-json");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]);
app_got(curl_exec($ch));

echo "== 3. 100-continue, then the final head\n";
$ch = curl_init("$base/echo-json");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
    CURLOPT_POSTFIELDS => '{"k":"v"}',
    CURLOPT_HTTPHEADER => ['Expect: 100-continue', 'Content-Type: application/json']]);
$res = curl_exec($ch);
echo 'app: ', strtok($res, "\r\n"), "\n";
app_got(substr($res, strpos($res, "\r\n\r\n") + 4));

echo "== 4. chunked with a trailer (body copy)\n";
datadog\appsec\testing\curl_downstream_collect_all(true);
$ch = curl_init("$base/chunked");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]);
$res = curl_exec($ch);
datadog\appsec\testing\curl_downstream_collect_all(false);
$app_body = explode("\r\n\r\n", $res, 2)[1];
$copy = curl_downstream_dump($ch)['response']['body'];
$v = datadog\appsec\testing\curl_linkage_info()['libcurl_version_num'];
$want = '0123456789abcde' . ($v >= 0x080500 ? "X-Trailer: t\r\n" : '');
echo 'app body and body copy as expected for this libcurl: ',
    var_export($app_body === $want && $copy === $want, true), "\n";
if ($app_body !== $want || $copy !== $want) {
    var_dump($app_body, $copy);
}

echo "== 5. CURLOPT_HEADER 0: the body alone\n";
$ch = curl_init("$base/json");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => false]);
echo 'app: ', json_encode(curl_exec($ch)), "\n";

var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
== 1. Content-Length
app: HTTP/1.1 200 OK ... body "{\"status\":\"ok\",\"n\":42}"
== 2. chunked
app: HTTP/1.1 200 OK ... body "{\"status\":\"ok\",\"n\":42}"
== 3. 100-continue, then the final head
app: HTTP/1.1 100 Continue
app: HTTP/1.1 200 OK ... body "{\"k\":\"v\"}"
== 4. chunked with a trailer (body copy)
app body and body copy as expected for this libcurl: true
== 5. CURLOPT_HEADER 0: the body alone
app: "{\"status\":\"ok\",\"n\":42}"
bool(true)
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
curl-1-0 last {"server.io.net.response.body":{"n":42,"status":"ok"},"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
curl-2-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/chunked-json"}
curl-2-0 last {"server.io.net.response.body":{"n":42,"status":"ok"},"server.io.net.response.headers":{"connection":["close"],"content-type":["application/json"],"transfer-encoding":["chunked"]},"server.io.net.response.status":"200"}
curl-3-0 {"server.io.net.request.body":{"k":"v"},"server.io.net.request.headers":{"content-type":["application/json"],"expect":["100-continue"],"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"POST","server.io.net.url":"http://127.0.0.1:PORT/echo-json"}
curl-3-0 last {"server.io.net.response.body":{"k":"v"},"server.io.net.response.headers":{"connection":["close"],"content-length":["9"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
curl-4-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/chunked"}
curl-4-0 last {"server.io.net.response.headers":{"connection":["close"],"content-type":["text/plain"],"transfer-encoding":["chunked"]},"server.io.net.response.status":"200"}
curl-5-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
curl-5-0 last {"server.io.net.response.body":{"n":42,"status":"ok"},"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
5 subcontext(s), all closed
